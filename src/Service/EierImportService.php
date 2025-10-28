<?php
/**
 * EierImportService - On-demand import of personer and juridiske_personer
 * 
 * Fetches eiere (owners) from StoreService based on IDs found in matrikkelenheter.
 * Uses batch fetching for efficiency.
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\LocalDb\PersonTable;
use Iaasen\Matrikkel\LocalDb\JuridiskPersonTable;
use Laminas\Db\Adapter\Adapter;
use Symfony\Component\Console\Style\SymfonyStyle;

class EierImportService
{
    private StoreClient $storeClient;
    private Adapter $dbAdapter;
    private PersonTable $personTable;
    private JuridiskPersonTable $juridiskPersonTable;
    
    public function __construct(
        StoreClient $storeClient,
        Adapter $dbAdapter
    ) {
        $this->storeClient = $storeClient;
        $this->dbAdapter = $dbAdapter;
        $this->personTable = new PersonTable($dbAdapter);
        $this->juridiskPersonTable = new JuridiskPersonTable($dbAdapter);
    }
    
    /**
     * Import eiere (personer and juridiske personer) for given kommuner
     * 
     * Strategi: Siden vi ikke alltid vet typen før fetch, henter vi alle eier_person_id
     * (som inneholder både kjente personer og ukjente ID-er), fetcher dem via StoreService,
     * identifiserer riktig type fra respons, og oppdaterer database deretter.
     * 
     * @param array|null $kommunenummer Array of kommune numbers, or null for all
     * @param SymfonyStyle $io Console output
     * @param int $batchSize Number of IDs to fetch per API call (max 100 recommended, 20 for stability)
     * @return array Statistics about import
     */
    public function importEiereForKommuner(?array $kommunenummer, SymfonyStyle $io, int $batchSize = 20): array
    {
        $io->section('Henter eiere fra matrikkelenheter');
        
        // Build WHERE clause for kommuner
        $whereClause = '';
        if ($kommunenummer !== null && count($kommunenummer) > 0) {
            $kommuneList = implode(',', array_map('intval', $kommunenummer));
            $whereClause = " WHERE kommunenummer IN ($kommuneList)";
        }
        
        // Find all unique eier IDs (from eier_person_id which holds both persons and unknowns)
        $io->text('Finner unike eier-IDer (inkludert ukjent type)...');
        $personIds = $this->findUniqueEierIds($whereClause, 'eier_person_id');
        $io->text(sprintf('  Fant %d unike eier-IDer å hente', count($personIds)));
        
        // Also find known juridisk_person_id (if any were identified during import)
        $juridiskPersonIds = $this->findUniqueEierIds($whereClause, 'eier_juridisk_person_id');
        if (count($juridiskPersonIds) > 0) {
            $io->text(sprintf('  Fant %d eksplisitt juridiske personer', count($juridiskPersonIds)));
        }
        
        $io->newLine();
        
        // Import all eiere (will auto-classify during fetch)
        $stats = ['personer' => 0, 'juridiske_personer' => 0];
        
        if (count($personIds) > 0) {
            $io->text(sprintf('Henter og klassifiserer %d eiere...', count($personIds)));
            $fetchStats = $this->fetchAndImportEiere($personIds, $io, $batchSize, $whereClause);
            $stats['personer'] += $fetchStats['personer'];
            $stats['juridiske_personer'] += $fetchStats['juridiske_personer'];
        }
        
        // Import explicitly known juridiske personer
        if (count($juridiskPersonIds) > 0) {
            $io->text(sprintf('Henter %d eksplisitt juridiske personer...', count($juridiskPersonIds)));
            $juridiskePersonerImportert = $this->fetchAndImportJuridiskePersoner($juridiskPersonIds, $io, $batchSize);
            $stats['juridiske_personer'] += $juridiskePersonerImportert;
        }
        
        $io->success(sprintf(
            'Eier-import fullført: %d personer, %d juridiske personer',
            $stats['personer'],
            $stats['juridiske_personer']
        ));
        
        return [
            'personer' => $stats['personer'],
            'juridiske_personer' => $stats['juridiske_personer'],
            'totalt' => $stats['personer'] + $stats['juridiske_personer'],
        ];
    }
    
    /**
     * Find unique eier IDs from matrikkelenheter table
     */
    private function findUniqueEierIds(string $whereClause, string $columnName): array
    {
        $sql = "SELECT DISTINCT $columnName 
                FROM matrikkel_matrikkelenheter 
                $whereClause 
                AND $columnName IS NOT NULL 
                ORDER BY $columnName";
        
        $result = $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        
        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int)$row[$columnName];
        }
        
        return $ids;
    }
    
    /**
     * Fetch eiere from StoreService, auto-classify, and import to database
     * Also updates matrikkelenhet records with correct type and column
     */
    private function fetchAndImportEiere(array $eierIds, SymfonyStyle $io, int $batchSize, string $whereClause): array
    {
        $stats = ['personer' => 0, 'juridiske_personer' => 0];
        $totalBatches = ceil(count($eierIds) / $batchSize);
        $currentBatch = 0;
        
        //Process in batches
        foreach (array_chunk($eierIds, $batchSize) as $idBatch) {
            $currentBatch++;
            $io->text(sprintf('  Batch %d/%d: Henter %d eiere fra API...', 
                $currentBatch, $totalBatches, count($idBatch)));
            
            try {
                // Build ID array for StoreClient
                $bubbleIds = array_map(function($id) {
                    return (object)['value' => $id];
                }, $idBatch);
                
                // Fetch from StoreService
                $objects = $this->storeClient->getObjects($bubbleIds);
                
                // Process each object - classify and insert
                foreach ($objects as $eier) {
                    $eierId = isset($eier->id->value) ? (int)$eier->id->value : null;
                    if (!$eierId) continue;
                    
                    $className = get_class($eier);
                    
                    // Classify based on class name
                    if (stripos($className, 'JuridiskPerson') !== false) {
                        // Juridisk person
                        $this->juridiskPersonTable->insertRow($eier);
                        $stats['juridiske_personer']++;
                        
                        // Update matrikkelenhet records: move ID from eier_person_id to eier_juridisk_person_id
                        $this->updateMatrikkelenhetEierType($eierId, 'juridisk_person', $whereClause);
                        
                    } elseif (stripos($className, 'Person') !== false) {
                        // Fysisk person
                        $this->personTable->insertRow($eier);
                        $stats['personer']++;
                        
                        // Update matrikkelenhet records: ensure eier_type is 'person'
                        $this->updateMatrikkelenhetEierType($eierId, 'person', $whereClause);
                    }
                }
                
                // Flush after each batch
                $this->personTable->flush();
                $this->juridiskPersonTable->flush();
                
            } catch (\Exception $e) {
                $io->error(sprintf('Feil ved henting av batch %d: %s', $currentBatch, $e->getMessage()));
            }
        }
        
        return $stats;
    }
    
    /**
     * Update matrikkelenhet eier type and move ID to correct column
     */
    private function updateMatrikkelenhetEierType(int $eierId, string $type, string $whereClause): void
    {
        if ($type === 'juridisk_person') {
            // Move from eier_person_id to eier_juridisk_person_id
            $sql = "UPDATE matrikkel_matrikkelenheter 
                    SET eier_type = 'juridisk_person',
                        eier_juridisk_person_id = eier_person_id,
                        eier_person_id = NULL
                    WHERE eier_person_id = $eierId 
                    $whereClause";
        } else {
            // Just update type to 'person' (ID already in eier_person_id)
            $sql = "UPDATE matrikkel_matrikkelenheter 
                    SET eier_type = 'person'
                    WHERE eier_person_id = $eierId 
                    AND eier_type = 'ukjent'
                    $whereClause";
        }
        
        $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
    }
    
    /**
     * Fetch personer from StoreService and import to database
     */
    private function fetchAndImportPersoner(array $personIds, SymfonyStyle $io, int $batchSize): int
    {
        $totalImported = 0;
        $totalBatches = ceil(count($personIds) / $batchSize);
        $currentBatch = 0;
        
        // Process in batches
        foreach (array_chunk($personIds, $batchSize) as $idBatch) {
            $currentBatch++;
            $io->text(sprintf('  Batch %d/%d: Henter %d personer fra API...', 
                $currentBatch, $totalBatches, count($idBatch)));
            
            try {
                // Build ID array for StoreClient
                $bubbleIds = array_map(function($id) {
                    return (object)['value' => $id];
                }, $idBatch);
                
                // Fetch persons from StoreService
                $objects = $this->storeClient->getObjects($bubbleIds);
                
                // Insert into database
                foreach ($objects as $person) {
                    // Verify this is a Person object (not something else)
                    $className = get_class($person);
                    if (stripos($className, 'Person') !== false && 
                        stripos($className, 'Juridisk') === false) {
                        $this->personTable->insertRow($person);
                        $totalImported++;
                    }
                }
                
                // Flush after each batch
                $this->personTable->flush();
                
            } catch (\Exception $e) {
                $io->error(sprintf('Feil ved henting av batch %d: %s', $currentBatch, $e->getMessage()));
            }
        }
        
        return $totalImported;
    }
    
    /**
     * Fetch juridiske personer from StoreService and import to database
     */
    private function fetchAndImportJuridiskePersoner(array $juridiskPersonIds, SymfonyStyle $io, int $batchSize): int
    {
        $totalImported = 0;
        $totalBatches = ceil(count($juridiskPersonIds) / $batchSize);
        $currentBatch = 0;
        
        // Process in batches
        foreach (array_chunk($juridiskPersonIds, $batchSize) as $idBatch) {
            $currentBatch++;
            $io->text(sprintf('  Batch %d/%d: Henter %d juridiske personer fra API...', 
                $currentBatch, $totalBatches, count($idBatch)));
            
            try {
                // Build ID array for StoreClient
                $bubbleIds = array_map(function($id) {
                    return (object)['value' => $id];
                }, $idBatch);
                
                // Fetch juridiske personer from StoreService
                $objects = $this->storeClient->getObjects($bubbleIds);
                
                // Insert into database
                foreach ($objects as $juridiskPerson) {
                    // Verify this is a JuridiskPerson object
                    $className = get_class($juridiskPerson);
                    if (stripos($className, 'JuridiskPerson') !== false) {
                        $this->juridiskPersonTable->insertRow($juridiskPerson);
                        $totalImported++;
                    }
                }
                
                // Flush after each batch
                $this->juridiskPersonTable->flush();
                
            } catch (\Exception $e) {
                $io->error(sprintf('Feil ved henting av batch %d: %s', $currentBatch, $e->getMessage()));
            }
        }
        
        return $totalImported;
    }
}
