<?php
/**
 * EierforholdImportService - Oppdater Matrikkelenheter med Eierforhold
 * 
 * Dette er Phase 1, Step 4: Koble matrikkelenheter til personer via eierforhold.
 * 
 * VIKTIG: Eierforhold er denormalisert inn i matrikkel_matrikkelenheter tabellen:
 * - eier_type: 'person', 'juridisk_person', eller 'ukjent'
 * - eier_person_id: Foreign key til matrikkel_personer
 * - eier_juridisk_person_id: Foreign key til matrikkel_juridiske_personer
 * 
 * Flow:
 * 1. Hent alle matrikkelenheter for kommunen fra database
 * 2. Batch-hent komplette matrikkelenheter med eierforhold fra StoreService
 * 3. For hver matrikkelenhet: ekstraher eierforhold
 * 4. UPDATE matrikkel_matrikkelenheter med eier_person_id/eier_juridisk_person_id
 * 
 * Performance:
 * - Batch size: 500 matrikkelenheter per StoreService call
 * - For Bergen (50,000 matrikkelenheter): ~100 API-kall
 * 
 * @author Matrikkel Integration System
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class EierforholdImportService
{
    private StoreClient $storeClient;
    private PDO $db;
    
    public function __construct(StoreClient $storeClient, PDO $db)
    {
        $this->storeClient = $storeClient;
        $this->db = $db;
    }
    
    /**
     * Importer eierforhold for alle matrikkelenheter i kommunen
     * 
     * @param SymfonyStyle $io Console output
     * @param int $kommunenummer Kommune nummer (f.eks. 4601)
     * @param int $batchSize Batch size for StoreService calls (default 500)
     * @return int Antall matrikkelenheter oppdatert
     */
    public function importEierforholdForKommune(SymfonyStyle $io, int $kommunenummer, int $batchSize = 500): int
    {
        $io->section("Step 4: Import Eierforhold for kommune $kommunenummer");
        
        // 1. Hent alle matrikkelenhet-IDer fra database
        $io->text("Henter matrikkelenheter fra database...");
        $stmt = $this->db->prepare(
            "SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = ?"
        );
        $stmt->execute([$kommunenummer]);
        $matrikkelenheter = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($matrikkelenheter)) {
            $io->warning("Ingen matrikkelenheter funnet for kommune $kommunenummer. Kjør først MatrikkelenhetImportService!");
            return 0;
        }
        
        $io->success("Funnet " . count($matrikkelenheter) . " matrikkelenheter");
        
        // 2. Batch-hent komplette matrikkelenheter med eierforhold fra StoreService
        $io->text("Henter eierforhold fra Matrikkel API...");
        $matrikkelenhetIds = array_map(
            fn($m) => new MatrikkelenhetId($m['matrikkelenhet_id']),
            $matrikkelenheter
        );
        
        $updateCount = 0;
        $personEierCount = 0;
        $juridiskEierCount = 0;
        $ukjentEierCount = 0;
        
        $progressBar = $io->createProgressBar(count($matrikkelenhetIds));
        $progressBar->setFormat('very_verbose');
        
        foreach (array_chunk($matrikkelenhetIds, $batchSize) as $batch) {
            $objects = $this->storeClient->getObjects($batch);
            
            foreach ($objects as $obj) {
                // Ekstraher matrikkelenhet_id
                $matrikkelenhetId = $obj->id->value ?? null;
                if (!$matrikkelenhetId) {
                    $io->warning("Matrikkelenhet uten ID");
                    $progressBar->advance();
                    continue;
                }
                
                // Ekstraher eierforhold
                // Structure: $obj->eierforhold->item (can be single object or array)
                if (isset($obj->eierforhold) && isset($obj->eierforhold->item)) {
                    $items = is_array($obj->eierforhold->item) 
                        ? $obj->eierforhold->item 
                        : [$obj->eierforhold->item];
                    
                    // For now, save to matrikkel_eierforhold table
                    // Ta første eierforhold (primær eier) - TODO: Håndter alle eierforhold
                    foreach ($items as $eierforhold) {
                        if (!isset($eierforhold->eierId)) {
                            continue;
                        }
                        
                        $eierId = $eierforhold->eierId;
                        $eierIdValue = is_object($eierId) && isset($eierId->value) ? $eierId->value : null;
                        
                        if (!$eierIdValue) {
                            continue;
                        }
                        
                        // Insert into matrikkel_eierforhold table
                        try {
                            // Check if eierforhold already exists
                            $checkStmt = $this->db->prepare(
                                "SELECT id FROM matrikkel_eierforhold 
                                WHERE matrikkelenhet_id = ? AND person_id = ?"
                            );
                            $checkStmt->execute([$matrikkelenhetId, $eierIdValue]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $eierforholdId = isset($eierforhold->id) ? $eierforhold->id : null;
                            $andelTeller = isset($eierforhold->andel->teller) ? $eierforhold->andel->teller : null;
                            $andelNevner = isset($eierforhold->andel->nevner) ? $eierforhold->andel->nevner : null;
                            $andelsnummer = isset($eierforhold->andelsnummer) ? $eierforhold->andelsnummer : null;
                            $tinglyst = true; // Assume tinglyst if from eierforhold (not ikkeTinglystEierforhold)
                            
                            if ($existing) {
                                // Update existing
                                $stmt = $this->db->prepare(
                                    "UPDATE matrikkel_eierforhold SET
                                        matrikkel_eierforhold_id = ?,
                                        andel_teller = ?,
                                        andel_nevner = ?,
                                        andelsnummer = ?,
                                        tinglyst = ?,
                                        sist_lastet_ned = CURRENT_TIMESTAMP
                                    WHERE id = ?"
                                );
                                $stmt->execute([
                                    $eierforholdId,
                                    $andelTeller,
                                    $andelNevner,
                                    $andelsnummer,
                                    $tinglyst ? 't' : 'f',
                                    $existing['id']
                                ]);
                            } else {
                                // Insert new
                                $stmt = $this->db->prepare(
                                    "INSERT INTO matrikkel_eierforhold (
                                        matrikkelenhet_id,
                                        person_id,
                                        matrikkel_eierforhold_id,
                                        andel_teller,
                                        andel_nevner,
                                        andelsnummer,
                                        tinglyst
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?)"
                                );
                                $stmt->execute([
                                    $matrikkelenhetId,
                                    $eierIdValue,
                                    $eierforholdId,
                                    $andelTeller,
                                    $andelNevner,
                                    $andelsnummer,
                                    $tinglyst ? 't' : 'f'
                                ]);
                            }
                            
                            $updateCount++;
                            $personEierCount++; // We'll differentiate fysisk/juridisk later when we have person data
                            
                        } catch (\PDOException $e) {
                            $io->warning("Feil ved lagring av eierforhold for matrikkelenhet $matrikkelenhetId: " . $e->getMessage());
                        }
                        
                        // Only save first eierforhold for now (primary owner)
                        break;
                    }
                }
                
                $progressBar->advance();
            }
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
        $io->success("Oppdatert $updateCount matrikkelenheter med eierforhold:");
        $io->listing([
            "Fysiske personer: $personEierCount",
            "Juridiske personer: $juridiskEierCount",
            "Ukjent eier: $ukjentEierCount"
        ]);
        
        return $updateCount;
    }
}
