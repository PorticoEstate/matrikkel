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
use Iaasen\Matrikkel\Client\KommuneClient;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\FylkeId;
use Iaasen\Matrikkel\LocalDb\KommuneTable;
use Symfony\Component\Console\Style\SymfonyStyle;

class KommuneImportService
{
    public function __construct(
        private NedlastningClient $nedlastningClient,
        private KommuneClient $kommuneClient,
        private StoreClient $storeClient,
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
        
        // Cache for fylkesnavn
        $fylkeCache = [];
        
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
                    // Hent fylkesnavn
                    $fylkesnavn = null;
                    if (isset($kommune->fylkeId)) {
                        $fylkeId = is_object($kommune->fylkeId) ? $kommune->fylkeId->value : $kommune->fylkeId;
                        
                        if (isset($fylkeCache[$fylkeId])) {
                            $fylkesnavn = $fylkeCache[$fylkeId];
                        } else {
                            try {
                                $fylkeObjects = $this->storeClient->getObjects([new FylkeId($fylkeId)]);
                                if (!empty($fylkeObjects)) {
                                    $fylke = $fylkeObjects[0];
                                    $fylkesnavn = $fylke->fylkesnavn ?? null;
                                    if (!$fylkesnavn && $io) {
                                        $io->warning("Fant ikke fylkesnavn for fylkeId: $fylkeId");
                                    }
                                    $fylkeCache[$fylkeId] = $fylkesnavn;
                                }
                            } catch (\Exception $e) {
                                if ($io) {
                                    $io->warning("Feil ved henting av fylke $fylkeId: " . $e->getMessage());
                                }
                            }
                        }
                    }

                    $this->kommuneTable->insertRow($kommune, $fylkesnavn);
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
     * Sjekk om en kommune finnes i databasen
     * 
     * @param int $kommunenummer Kommunenummer (4 siffer, f.eks. 4601)
     * @return bool True hvis kommunen finnes
     */
    public function kommuneExists(int $kommunenummer): bool
    {
        return $this->kommuneTable->kommuneExists($kommunenummer);
    }
    
    /**
     * Importer en spesifikk kommune fra Matrikkel API
     * 
     * Bruker NedlastningClient for å hente kommuner, deretter filter lokalt.
     * Filter-syntaks i API er ukjent, så vi henter alle og filtrerer lokalt.
     * 
     * @param SymfonyStyle $io Console IO for output
     * @param int $kommunenummer Kommunenummer (4 siffer, f.eks. 4627)
     * @return bool True hvis import var vellykket
     */
    public function importKommune(SymfonyStyle $io, int $kommunenummer): bool
    {
        try {
            $io->text("Henter kommune $kommunenummer fra Matrikkel API...");
            
            // Use NedlastningClient to fetch Kommune objects
            // Filter syntax unknown - fetch without filter and search locally
            $kommuner = $this->nedlastningClient->findObjekterEtterId(
                0,                  // Start from beginning
                'Kommune',          // Domain class
                null,               // No filter - fetch all
                1000                // Max batch size
            );
            
            if (empty($kommuner)) {
                $io->warning("Ingen kommuner funnet i Matrikkel API");
                return false;
            }
            
            $io->text("API returnerte " . count($kommuner) . " kommuner, søker etter $kommunenummer...");
            
            // Find the specific kommune we need
            $targetKommune = null;
            foreach ($kommuner as $kommune) {
                $knr = isset($kommune->kommunenummer) ? (int)$kommune->kommunenummer : 0;
                if ($knr === $kommunenummer) {
                    $targetKommune = $kommune;
                    break;
                }
            }
            
            if (!$targetKommune) {
                $io->warning("Kommune $kommunenummer ikke funnet blant de " . count($kommuner) . " kommunene");
                return false;
            }
            
            $kommunenavn = $targetKommune->kommunenavn ?? 'Ukjent';
            $io->text("Fant kommune: $kommunenavn");
            
            // Hent fylkesnavn
            $fylkesnavn = null;
            if (isset($targetKommune->fylkeId)) {
                $fylkeId = is_object($targetKommune->fylkeId) ? $targetKommune->fylkeId->value : $targetKommune->fylkeId;
                try {
                    $fylkeObjects = $this->storeClient->getObjects([new FylkeId($fylkeId)]);
                    if (!empty($fylkeObjects)) {
                        $fylke = $fylkeObjects[0];
                        $fylkesnavn = $fylke->fylkesnavn ?? null;
                        if ($fylkesnavn) {
                            $io->text("Fant fylkesnavn: $fylkesnavn");
                        } else {
                            $io->warning("Fant ikke fylkesnavn for fylkeId: $fylkeId");
                        }
                    }
                } catch (\Exception $e) {
                    $io->warning("Feil ved henting av fylke $fylkeId: " . $e->getMessage());
                }
            }

            $io->text("Lagrer til database...");
            
            // Save to database using KommuneTable
            $this->kommuneTable->insertRow($targetKommune, $fylkesnavn);
            $this->kommuneTable->flush();
            
            $io->success("✓ Kommune $kommunenummer ($kommunenavn) importert");
            return true;
            
        } catch (\SoapFault $e) {
            $io->error([
                'SOAP-feil ved import av kommune:',
                'Melding: ' . $e->getMessage(),
                'Detaljer: ' . ($e->faultstring ?? ''),
            ]);
            return false;
        } catch (\Exception $e) {
            $io->error([
                'Feil ved import av kommune:',
                'Melding: ' . $e->getMessage(),
                'Trace: ' . $e->getTraceAsString(),
            ]);
            return false;
        }
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
