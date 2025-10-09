<?php
/**
 * EierImportServiceV2 - On-demand import of personer and juridiske_personer
 * 
 * OPPDATERT: Bruker StoreClient.getObject() i loop i stedet for getObjects()
 * Årsak: StoreService.getObjects() feiler pga manglende type-spesifikasjon
 * 
 * @author Sigurd Nes
 * Date: 08.10.2025
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\BubbleId;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\LocalDb\PersonTable;
use Iaasen\Matrikkel\LocalDb\JuridiskPersonTable;
use Laminas\Db\Adapter\Adapter;
use Symfony\Component\Console\Style\SymfonyStyle;

class EierImportServiceV2
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
     * Strategi: Henter eiere én og én via StoreClient.getObject() siden
     * getObjects() feiler pga manglende type-spesifikasjon (PersonId vs JuridiskPersonId).
     * 
     * @param array|null $kommunenummer Array of kommune numbers, or null for all
     * @param SymfonyStyle $io Console output
     * @param int $batchSize Flush interval for database operations
     * @return array Statistics about import
     */
    public function importEiereForKommuner(?array $kommunenummer, SymfonyStyle $io, int $batchSize = 100): array
    {
        $io->section('Henter eiere fra StoreService (single-object mode)');
        
        // Build WHERE clause for kommuner
        $whereClause = '';
        if ($kommunenummer !== null && count($kommunenummer) > 0) {
            $kommuneList = implode(',', array_map('intval', $kommunenummer));
            $whereClause = "WHERE kommunenummer IN ($kommuneList)";
        }
        
        // Find unique person IDs (includes both known persons and unknown types)
        $io->text('Finner unike eier-IDer (inkludert ukjent type)...');
        $personIds = $this->findUniqueEierIds($whereClause, 'eier_person_id');
        
        // Find explicitly known juridisk person IDs
        $juridiskPersonIds = $this->findUniqueEierIds($whereClause, 'eier_juridisk_person_id');
        
        $io->text(sprintf('  Fant %d person-IDer og %d juridisk-person-IDer å hente', 
            count($personIds), count($juridiskPersonIds)));
        
        // Import all eiere (will auto-classify during fetch)
        $stats = ['personer' => 0, 'juridiske_personer' => 0, 'feilet' => 0];
        
        if (count($personIds) > 0) {
            $io->text(sprintf('Henter og klassifiserer %d eiere (single-object mode)...', count($personIds)));
            $fetchStats = $this->fetchAndImportEiereSingleMode($personIds, $io, $batchSize, $whereClause);
            $stats['personer'] += $fetchStats['personer'];
            $stats['juridiske_personer'] += $fetchStats['juridiske_personer'];
            $stats['feilet'] += $fetchStats['feilet'];
        }
        
        // Import explicitly known juridiske personer
        if (count($juridiskPersonIds) > 0) {
            $io->text(sprintf('Henter %d eksplisitt juridiske personer...', count($juridiskPersonIds)));
            $juridiskePersonerImportert = $this->fetchAndImportJuridiskePersonerSingleMode(
                $juridiskPersonIds, $io, $batchSize
            );
            $stats['juridiske_personer'] += $juridiskePersonerImportert['juridiske_personer'];
            $stats['feilet'] += $juridiskePersonerImportert['feilet'];
        }
        
        // Flush remaining buffered data
        $this->personTable->flush();
        $this->juridiskPersonTable->flush();
        
        $io->success(sprintf(
            'Eier-import fullført: %d personer, %d juridiske personer (%d feilet)',
            $stats['personer'],
            $stats['juridiske_personer'],
            $stats['feilet']
        ));
        
        return [
            'personer' => $stats['personer'],
            'juridiske_personer' => $stats['juridiske_personer'],
            'feilet' => $stats['feilet'],
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
     * Fetch eiere ONE BY ONE from StoreService, auto-classify, and import to database
     * Also updates matrikkelenhet records with correct type and column
     * 
     * @param array $eierIds List of eier IDs to fetch
     * @param SymfonyStyle $io Console output
     * @param int $flushInterval How often to flush buffered inserts
     * @param string $whereClause WHERE clause for matrikkelenhet updates
     * @return array Statistics
     */
    private function fetchAndImportEiereSingleMode(
        array $eierIds, 
        SymfonyStyle $io, 
        int $flushInterval,
        string $whereClause
    ): array {
        $stats = ['personer' => 0, 'juridiske_personer' => 0, 'feilet' => 0];
        $context = $this->buildMatrikkelContext();
        $totalIds = count($eierIds);
        $processed = 0;
        
        $io->progressStart($totalIds);
        
        foreach ($eierIds as $eierId) {
            $processed++;
            
            try {
                // Fetch single object via getObject()
                // Use PersonId as default type (will auto-classify after fetch)
                $response = $this->storeClient->getObject([
                    'id' => BubbleId::getId($eierId, 'PersonId'),
                    'matrikkelContext' => $context
                ]);
                
                // Extract object from response
                $object = $response->return ?? null;
                
                if (!$object) {
                    $stats['feilet']++;
                    $io->progressAdvance();
                    continue;
                }
                
                // Auto-classify based on class name
                $className = get_class($object);
                $type = 'ukjent';
                
                // Debug: Log first 3 objects structure
                if ($stats['personer'] + $stats['juridiske_personer'] < 3) {
                    $io->note("Hentet objekt med klasse: $className (ID: $eierId)");
                    $io->writeln("Objekt-struktur: " . print_r($object, true));
                }
                
                if (stripos($className, 'JuridiskPerson') !== false) {
                    $type = 'juridisk_person';
                    $this->juridiskPersonTable->insertRow($object);
                    $stats['juridiske_personer']++;
                    
                    // Update matrikkelenhet: move from eier_person_id to eier_juridisk_person_id
                    $this->updateMatrikkelenhetEierType($eierId, $type, $whereClause);
                    
                } elseif (stripos($className, 'Person') !== false) {
                    $type = 'person';
                    $this->personTable->insertRow($object);
                    $stats['personer']++;
                    
                    // Update matrikkelenhet: change type from ukjent to person
                    $this->updateMatrikkelenhetEierType($eierId, $type, $whereClause);
                }
                
                // Flush periodically
                if ($processed % $flushInterval === 0) {
                    $this->personTable->flush();
                    $this->juridiskPersonTable->flush();
                }
                
            } catch (\SoapFault $e) {
                $stats['feilet']++;
                // Log error for debugging (only first 3 to avoid spam)
                if ($stats['feilet'] <= 3) {
                    $io->warning("Feil ved henting av eier $eierId: " . $e->getMessage());
                }
                // Continue with next ID on error
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        return $stats;
    }
    
    /**
     * Fetch juridiske personer ONE BY ONE (explicit type known)
     */
    private function fetchAndImportJuridiskePersonerSingleMode(
        array $juridiskPersonIds,
        SymfonyStyle $io,
        int $flushInterval
    ): array {
        $stats = ['juridiske_personer' => 0, 'feilet' => 0];
        $context = $this->buildMatrikkelContext();
        $totalIds = count($juridiskPersonIds);
        $processed = 0;
        
        $io->progressStart($totalIds);
        
        foreach ($juridiskPersonIds as $juridiskPersonId) {
            $processed++;
            
            try {
                // Fetch single juridisk person with explicit type
                $response = $this->storeClient->getObject([
                    'id' => BubbleId::getId($juridiskPersonId, 'JuridiskPersonId'),
                    'matrikkelContext' => $context
                ]);
                
                $object = $response->return ?? null;
                
                if (!$object) {
                    $stats['feilet']++;
                    $io->progressAdvance();
                    continue;
                }
                
                // Insert to juridiske_personer table
                $this->juridiskPersonTable->insertRow($object);
                $stats['juridiske_personer']++;
                
                // Flush periodically
                if ($processed % $flushInterval === 0) {
                    $this->juridiskPersonTable->flush();
                }
                
            } catch (\SoapFault $e) {
                $stats['feilet']++;
                // Log error for debugging (only first 3 to avoid spam)
                if ($stats['feilet'] <= 3) {
                    $io->warning("Feil ved henting av juridisk person $juridiskPersonId: " . $e->getMessage());
                }
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        return $stats;
    }
    
    /**
     * Update matrikkelenhet record with correct eier type and column
     * 
     * For juridisk_person: Move ID from eier_person_id to eier_juridisk_person_id
     * For person: Just update eier_type from 'ukjent' to 'person'
     */
    private function updateMatrikkelenhetEierType(int $eierId, string $type, string $whereClause): void
    {
        if ($type === 'juridisk_person') {
            // Move ID from eier_person_id to eier_juridisk_person_id
            $sql = "UPDATE matrikkel_matrikkelenheter 
                    SET eier_type = 'juridisk_person',
                        eier_juridisk_person_id = $eierId,
                        eier_person_id = NULL
                    WHERE eier_person_id = $eierId";
            
            if ($whereClause) {
                $sql .= " AND " . str_replace('WHERE ', '', $whereClause);
            }
            
            $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
            
        } elseif ($type === 'person') {
            // Update type from ukjent to person (ID already in correct column)
            $sql = "UPDATE matrikkel_matrikkelenheter 
                    SET eier_type = 'person'
                    WHERE eier_person_id = $eierId 
                    AND eier_type = 'ukjent'";
            
            if ($whereClause) {
                $sql .= " AND " . str_replace('WHERE ', '', $whereClause);
            }
            
            $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }
    }
    
    /**
     * Build standard MatrikkelContext for SOAP calls
     */
    private function buildMatrikkelContext(): array
    {
        return [
            'locale' => [
                'language' => 'no',
                'country' => 'NO'
            ],
            // koordinatsystemKodeId irrelevant for Person objects, but required by schema
            'koordinatsystemKodeId' => 84
        ];
    }
}
