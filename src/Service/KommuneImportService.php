<?php
/**
 * KommuneImportService - Service for import av norske kommuner fra Matrikkel API
 * 
 * Bruker NedlastningClient for effektiv bulk-nedlasting av alle norske kommuner.
 * Implementerer cursor-basert paginering for å håndtere store datamengder.
 * 
 * @author Sigurd Nes
 * @date 2025-10-07
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\NedlastningClient;
use Iaasen\Matrikkel\LocalDb\KommuneTable;
use Symfony\Component\Console\Style\SymfonyStyle;

class KommuneImportService
{
    public function __construct(
        private NedlastningClient $nedlastningClient,
        private KommuneTable $kommuneTable
    ) {}
    
    /**
     * Importer alle norske kommuner fra Matrikkel API
     * 
     * Bruker TestNedlastningCommand-logikken som er bevist stabil.
     * Henter i store batches (1000) for effektivitet.
     * 
     * @param SymfonyStyle|null $io Console IO for progress bars (optional)
     * @param int $maxAntall Maksimalt antall kommuner å hente (default: 1000)
     * @return int Totalt antall kommuner importert
     */
    public function importAlleKommuner(?SymfonyStyle $io = null, int $maxAntall = 1000): int
    {
        if ($io) {
            $io->section('Importerer kommuner fra Matrikkel API');
            $io->text('Bruker TestNedlastningCommand-logikk (bevist stabil)...');
            $io->progressStart($maxAntall);
        }
        
        $lastId = 0;
        $totalCount = 0;
        $batchCount = 0;
        
        try {
            do {
                // Hent batch med samme logikk som TestNedlastningCommand
                $batch = $this->nedlastningClient->findObjekterEtterId(
                    $lastId,
                    'Kommune',
                    null, // Ingen filter
                    min($maxAntall - $totalCount, 1000) // Batch maks 1000 som i TestNedlastning
                );
                
                $batchCount++;
                
                if (count($batch) === 0) {
                    break;
                }
                
                // Lagre objekter til database
                foreach ($batch as $kommune) {
                    $this->kommuneTable->insertRow($kommune);
                    $lastId = $kommune->id->value;
                    $totalCount++;
                    
                    if ($io) {
                        $io->progressAdvance();
                    }
                    
                    if ($totalCount >= $maxAntall) {
                        break 2; // Break out of both foreach and do-while
                    }
                }
                
                // Flush etter hver batch
                $this->kommuneTable->flush();
                
                if ($io) {
                    $io->text(sprintf("  Batch %d: Hentet %d objekter (totalt: %d, siste ID: %d)", 
                        $batchCount, count($batch), $totalCount, $lastId));
                }
                
            } while (count($batch) > 0 && $totalCount < $maxAntall);
            
            if ($io) {
                $io->progressFinish();
                $io->success([
                    "Import fullført!",
                    "Totalt $totalCount kommuner importert",
                    "Antall batches: $batchCount",
                    "Siste ID: $lastId"
                ]);
            }
            
            return $totalCount;
            
        } catch (\SoapFault $e) {
            if ($io) {
                $io->progressFinish();
                $io->error([
                    'SOAP-feil oppstod:',
                    'Kode: ' . $e->getCode(),
                    'Melding: ' . $e->getMessage(),
                    "Importerte $totalCount kommuner før feilen"
                ]);
            }
            
            // Flush det vi har så langt
            $this->kommuneTable->flush();
            
            return $totalCount;
        }
    }
    
    /**
     * Tell antall kommuner i lokal database
     * 
     * @return int Antall kommuner
     */
    public function countKommuner(): int
    {
        return $this->kommuneTable->countKommuner();
    }
    
    /**
     * Hent alle kommuner fra lokal database
     * 
     * @return array Liste med alle kommuner
     */
    public function getAllKommuner(): array
    {
        return $this->kommuneTable->getAllKommuner();
    }
    
    /**
     * Hent en kommune fra lokal database basert på kommunenummer
     * 
     * @param string $kommunenummer Kommunenummer (f.eks. "0301")
     * @return array|null Kommune-data eller null hvis ikke funnet
     */
    public function getKommuneByNumber(string $kommunenummer): ?array
    {
        return $this->kommuneTable->getKommuneByNumber($kommunenummer);
    }
}
