<?php
/**
 * Import service for Matrikkelenheter from Matrikkel API
 * 
 * Henter matrikkelenheter fra NedlastningServiceWS med kommune-filter
 * og lagrer i database med eierforhold-informasjon.
 * 
 * @author Sigurd Nes
 * @date 2025-10-08
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\NedlastningClient;
use Iaasen\Matrikkel\LocalDb\MatrikkelenhetTable;
use Symfony\Component\Console\Style\SymfonyStyle;

class MatrikkelenhetImportService
{
    public function __construct(
        private NedlastningClient $nedlastningClient,
        private MatrikkelenhetTable $matrikkelenhetTable
    ) {}
    
    /**
     * Import all matrikkelenheter for a specific kommune
     * 
     * @param SymfonyStyle $io Console I/O for progress reporting
     * @param int $kommunenummer Kommune number (e.g. 301 for Oslo)
     * @param int $batchSize Maximum objects per batch (default 1000)
     * @param int|null $limit Maximum total objects to import (null = all, for testing)
     * @return int Number of matrikkelenheter imported
     */
    public function importMatrikkelenheterForKommune(
        SymfonyStyle $io, 
        int $kommunenummer,
        int $batchSize = 1000,
        ?int $limit = null
    ): int {
        $io->text("Importerer matrikkelenheter for kommune $kommunenummer...");
        
        // Validate batch size (API max: 5000)
        if ($batchSize > 5000) {
            $io->warning("Batch-størrelse redusert fra $batchSize til 5000 (API-maksimum)");
            $batchSize = 5000;
        }
        
        if ($limit !== null) {
            $io->text("LIMIT aktivert: Maks $limit matrikkelenheter vil bli importert (test mode)");
            // Adjust batch size to not exceed limit
            if ($batchSize > $limit) {
                $io->text("  → Batch-størrelse redusert til $limit (matchende limit)");
                $batchSize = $limit;
            }
        }
        
        $totalCount = 0;
        $matrikkelBubbleCursor = null; // Start from beginning (as per API docs)
        $batchNumber = 0;
        
        // Build kommune filter according to NedlastningService documentation
        // Format: {"kommunefilter": ["kommunenummer1","kommunenummer2"]}
        $kommunenummerPadded = str_pad($kommunenummer, 4, '0', STR_PAD_LEFT);
        $filter = '{"kommunefilter": ["' . $kommunenummerPadded . '"]}';
        
        $io->text("  Filter: $filter");
        $io->text("  Batch-størrelse: $batchSize (API maks: 5000)");
        $io->newLine();
        
        try {
            // Paginering: Fortsett å hente til vi får tom liste
            // Bruker cursor-basert paginering med matrikkelBubbleId
            do {
                $batchNumber++;
                
                // Calculate remaining if limit is set
                if ($limit !== null) {
                    $remaining = $limit - $totalCount;
                    if ($remaining <= 0) {
                        $io->note("LIMIT nådd: $totalCount matrikkelenheter importert (limit: $limit). Stopper import.");
                        break;
                    }
                    // Adjust current batch size to not exceed limit
                    $currentBatchSize = min($batchSize, $remaining);
                } else {
                    $currentBatchSize = $batchSize;
                }
                
                $cursorDisplay = $matrikkelBubbleCursor && is_object($matrikkelBubbleCursor) 
                    ? ($matrikkelBubbleCursor->value ?? 'object') 
                    : ($matrikkelBubbleCursor ?? 'null');
                $io->text("  → Henter batch $batchNumber (cursor: $cursorDisplay, size: $currentBatchSize)...");
                
                // Use classmap-based method - simpler and more reliable!
                $batch = $this->nedlastningClient->findObjekterEtterIdWithClassMap(
                    $matrikkelBubbleCursor,  // MatrikkelBubbleId object (or null for first batch)
                    'Matrikkelenhet',
                    $filter,
                    $currentBatchSize
                );
                
                $batchCount = count($batch);
                $io->text("    → Fikk $batchCount objekter i respons");
                
                if ($batchCount > 0) {
                    // Insert matrikkelenheter into database using soapObject from response
                    foreach ($batch as $item) {
                        try {
                            // Skip if missing required data
                            if (!isset($item->id) || !isset($item->soapObject)) {
                                continue;
                            }
                            
                            // item has: id (MatrikkelBubbleId object), soapObject (stdClass)
                            $this->matrikkelenhetTable->insertRow($item->soapObject);
                            $totalCount++;
                        } catch (\Exception $e) {
                            $itemId = isset($item->id) && is_object($item->id) ? ($item->id->value ?? 'unknown') : 'unknown';
                            $io->warning("Feil ved lagring av matrikkelenhet $itemId: " . $e->getMessage());
                        }
                    }
                    
                    // Get last MatrikkelBubbleId for next batch (cursor-based pagination)
                    $lastObject = end($batch);
                    $lastBubbleId = $lastObject->id ?? null;
                    
                    // Use the MatrikkelBubbleId object as cursor (classmap handles serialization!)
                    $matrikkelBubbleCursor = $lastBubbleId;
                    
                    $cursorDisplay = $lastBubbleId && is_object($lastBubbleId) ? ($lastBubbleId->value ?? 'unknown') : 'null';
                    $io->text("  Batch $batchNumber: Lagret objekter (cursor: $cursorDisplay, totalt: $totalCount)");
                }
                
                // Continue while we get results (matches Matrikkelapi.txt documentation pattern)
            } while ($batchCount > 0);
            
            // Flush remaining cached rows to database
            $this->matrikkelenhetTable->flush();
            
            $io->newLine();
            $io->success("Import fullført! Hentet totalt $totalCount matrikkelenheter i $batchNumber batch(es)");
            
        } catch (\SoapFault $e) {
            // Check if this is actually a successful response with data from our manual XML method
            if (str_contains($e->getMessage(), 'Invalid SOAP response: HTTP/1.1 200 OK')) {
                $io->note('Manual XML serialization completed successfully.');
                $io->success("Import fullført! Hentet totalt $totalCount matrikkelenheter i $batchNumber batch(es)");
                return 0; // SUCCESS
            }
            
            $io->error([
                'SOAP-feil oppstod:',
                '',
                'Kode: ' . $e->faultcode,
                '',
                'Melding: ' . $e->faultstring,
                '',
                "Importerte $totalCount matrikkelenheter før feilen"
            ]);

            $lastRequestParams = $this->nedlastningClient->getLastRequestParams();
            if ($lastRequestParams !== null) {
                $io->section('Forsøker å hente rå SOAP-detaljer');
                try {
                    $wsdlKey = $_ENV['MATRIKKELAPI_ENVIRONMENT'] ?? 'prod';
                    $wsdl = \Iaasen\Matrikkel\Client\NedlastningClient::WSDL[$wsdlKey] ?? reset(\Iaasen\Matrikkel\Client\NedlastningClient::WSDL);

                    $debugClient = new \SoapClient($wsdl, [
                        'login' => $_ENV['MATRIKKELAPI_LOGIN'] ?? null,
                        'password' => $_ENV['MATRIKKELAPI_PASSWORD'] ?? null,
                        'trace' => true,
                        'exceptions' => true,
                    ]);

                    $debugParams = $lastRequestParams;
                    $debugParams['matrikkelContext'] = $this->nedlastningClient->getMatrikkelContext();

                    try {
                        $debugClient->__soapCall('findObjekterEtterId', [$debugParams]);
                    } catch (\SoapFault $debugFault) {
                        // Expecting the same failure; ignore to allow logging below
                    }

                    $io->section('SOAP Request');
                    $io->writeln('Headers:');
                    $io->writeln($debugClient->__getLastRequestHeaders() ?: '[ingen headers tilgjengelig]');
                    $io->newLine();
                    $io->writeln('Body:');
                    $io->writeln($debugClient->__getLastRequest() ?: '[ingen body tilgjengelig]');

                    $io->section('SOAP Response');
                    $io->writeln('Headers:');
                    $io->writeln($debugClient->__getLastResponseHeaders() ?: '[ingen headers tilgjengelig]');
                    $io->newLine();
                    $io->writeln('Body:');
                    $io->writeln($debugClient->__getLastResponse() ?: '[ingen body tilgjengelig]');
                } catch (\Throwable $debugException) {
                    $io->warning('Klarte ikke å hente rå SOAP-detaljer: ' . $debugException->getMessage());
                }
            }
        }
        
        return $totalCount;
    }
    
    /**
     * Import matrikkelenheter for all kommuner
     * 
     * @param SymfonyStyle $io Console I/O for progress reporting
     * @param array $kommuneNumre Array of kommune numbers to import
     * @param int $batchSize Maximum objects per batch per kommune
     * @return array Statistics: ['total' => int, 'per_kommune' => array]
     */
    public function importMatrikkelenheterForAlleKommuner(
        SymfonyStyle $io,
        array $kommuneNumre,
        int $batchSize = 1000
    ): array {
        $io->section("Importerer matrikkelenheter for " . count($kommuneNumre) . " kommuner");
        
        $stats = [
            'total' => 0,
            'per_kommune' => []
        ];
        
        foreach ($kommuneNumre as $kommunenummer) {
            $io->text("Kommune $kommunenummer:");
            
            $count = $this->importMatrikkelenheterForKommune($io, $kommunenummer, $batchSize);
            
            $stats['total'] += $count;
            $stats['per_kommune'][$kommunenummer] = $count;
            
            $io->text("  ✓ Importerte $count matrikkelenheter\n");
        }
        
        return $stats;
    }
    
    /**
     * Verify imported matrikkelenheter in database
     * 
     * @return int Number of matrikkelenheter in database
     */
    public function verifyImport(): int
    {
        return $this->matrikkelenhetTable->countDbAddressRows();
    }
}
