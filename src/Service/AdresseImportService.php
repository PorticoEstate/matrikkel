<?php
/**
 * AdresseImportService - Import adresser for filtered matrikkelenheter
 * 
 * This is Phase 2 Step 5: Import adresser (addresses) for matrikkelenheter
 * that we already have in the database.
 * 
 * Strategy (Two-Step API Pattern):
 * 1. Get all matrikkelenhet_id from database
 * 2. Call AdresseClient.findAdresserForMatrikkelenheter() → Get adresse IDs with relationships
 * 3. Batch fetch full Adresse objects via StoreClient.getObjects()
 * 4. Save to matrikkel_adresser table (base table)
 * 5. If VEGADRESSE type: Save to matrikkel_vegadresser (subclass table with veg_id FK)
 * 6. Save M:N relationships to matrikkel_matrikkelenhet_adresse junction table
 * 
 * Pattern copied from BruksenhetImportService (proven working)
 * 
 * @author Sigurd Nes
 * @date 2025-10-23
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\AdresseClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\AdresseId;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class AdresseImportService
{
    private StoreClient $storeClient;
    private AdresseClient $adresseClient;
    private PDO $db;
    
    public function __construct(
        StoreClient $storeClient,
        AdresseClient $adresseClient,
        PDO $db
    ) {
        $this->storeClient = $storeClient;
        $this->adresseClient = $adresseClient;
        $this->db = $db;
    }
    
    /**
     * Import adresser for all matrikkelenheter in database
     * 
     * @param SymfonyStyle $io Console output
     * @param int $kommunenummer Kommune number (for logging)
     * @param array $matrikkelenhetIds Optional: specific matrikkelenhet IDs to import (if empty, imports all from DB)
     * @param int $batchSize Batch size for StoreClient calls
     * @return array ['adresser' => int, 'relations' => int]
     */
    public function importAdresserForMatrikkelenheter(
        SymfonyStyle $io,
        int $kommunenummer,
        array $matrikkelenhetIds = [],
        int $batchSize = 500
    ): array {
        $io->section("Importing adresser for matrikkelenheter");
        
        // 1. Get matrikkelenhet IDs to process
        if (empty($matrikkelenhetIds)) {
            $io->text("Henter matrikkelenheter fra database...");
            $stmt = $this->db->prepare(
                "SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = ?"
            );
            $stmt->execute([$kommunenummer]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $matrikkelenhetIds = array_column($rows, 'matrikkelenhet_id');
        }
        
        if (empty($matrikkelenhetIds)) {
            $io->warning("Ingen matrikkelenheter funnet! Kjør Phase 1 først.");
            return ['adresser' => 0, 'relations' => 0];
        }
        
        $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter");
        
        // 2. Find Adresse IDs via API (Two-step pattern: Step 1)
        $io->text("Finner adresse-IDs via API...");
        
        $allAdresseIds = [];
        $matrikkelenhetToAdresser = [];
        
        $progressBar = $io->createProgressBar(count($matrikkelenhetIds));
        $progressBar->setFormat('very_verbose');
        
        // Batch process matrikkelenheter (500 per batch)
        foreach (array_chunk($matrikkelenhetIds, 500) as $batch) {
            $matrikkelenhetIdObjects = array_map(
                fn($id) => new MatrikkelenhetId($id),
                $batch
            );
            
            try {
                // API call: findAdresserForMatrikkelenheter()
                // WSDL parameter: matrikkelenhetIds (type: MatrikkelenhetIdList with item array)
                $result = $this->adresseClient->findAdresserForMatrikkelenheter([
                    'matrikkelenhetIds' => ['item' => $matrikkelenhetIdObjects]
                ]);
                
                // Response structure: return->entry[] (Map entries)
                // Each entry has: key (MatrikkelenhetId), value->item[] (AdresseId list)
                if (isset($result->return) && isset($result->return->entry)) {
                    $entries = is_array($result->return->entry)
                        ? $result->return->entry
                        : [$result->return->entry];
                    
                    foreach ($entries as $entry) {
                        $matrikkelenhetId = $entry->key->value ?? null;
                        
                        // Extract adresse IDs from entry->value->item
                        if ($matrikkelenhetId && isset($entry->value) && isset($entry->value->item)) {
                            $adresseIdObjects = is_array($entry->value->item)
                                ? $entry->value->item
                                : [$entry->value->item];
                            
                            $adresseIds = [];
                            foreach ($adresseIdObjects as $adresseIdObj) {
                                $adresseId = $adresseIdObj->value ?? null;
                                if ($adresseId) {
                                    $allAdresseIds[] = (int) $adresseId;  // Store as integer
                                    $adresseIds[] = (int) $adresseId;      // Store as integer
                                }
                            }
                            
                            $matrikkelenhetToAdresser[$matrikkelenhetId] = $adresseIds;
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->error("Feil ved henting av adresse-IDs: " . $e->getMessage());
            }
            
            $progressBar->advance(count($batch));
        }
        
        $progressBar->finish();
        $io->newLine(2);

        // Build reverse lookup: adresse_id => [matrikkelenhet_id, ...]
        $adresseToMatrikkelenheter = [];
        foreach ($matrikkelenhetToAdresser as $matrikkelenhetId => $adresseIds) {
            foreach ($adresseIds as $adresseId) {
                if (!isset($adresseToMatrikkelenheter[$adresseId])) {
                    $adresseToMatrikkelenheter[$adresseId] = [];
                }
                $adresseToMatrikkelenheter[$adresseId][$matrikkelenhetId] = true;
            }
        }

        if (empty($allAdresseIds)) {
            $io->warning("Ingen adresser funnet for matrikkelenhetene!");
            return ['adresser' => 0, 'relations' => 0];
        }
        
        $io->success("Funnet " . count($allAdresseIds) . " adresse-IDs");
        
        // 3. Fetch full Adresse objects via StoreClient (Two-step pattern: Step 2)
        $io->text("Henter fullstendige adresse-objekter...");
        
        $adresseCount = 0;
        $relationCount = 0;
        
        $progressBar2 = $io->createProgressBar(count($allAdresseIds));
        $progressBar2->setFormat('very_verbose');
        
        foreach (array_chunk($allAdresseIds, $batchSize) as $batch) {
            try {
                // StoreClient.getObjects() accepts array of ID objects directly (NOT wrapped in ['ids' => ...])
                $adresseIdObjects = array_map(fn($id) => new AdresseId($id), $batch);
                
                $adresser = $this->storeClient->getObjects($adresseIdObjects);  // Pass directly!
                
                if (empty($adresser)) {
                    $progressBar2->advance(count($batch));
                    continue;
                }
                
                // Ensure adresser is array
                if (!is_array($adresser)) {
                    $adresser = [$adresser];
                }
                
                // Save each adresse to database
                foreach ($adresser as $adresse) {
                    $adresseId = (int) $adresse->id->value;
                    
                    // Save base adresse
                    $primaryMatrikkelenhetId = isset($adresseToMatrikkelenheter[$adresseId])
                        ? array_key_first($adresseToMatrikkelenheter[$adresseId])
                        : null;

                    $this->saveAdresse($adresse, $primaryMatrikkelenhetId);
                    $adresseCount++;
                    
                    // Save subclass data if VEGADRESSE
                    $adresseType = $this->getAdresseType($adresse);
                    if ($adresseType === 'VEGADRESSE') {
                        $this->saveVegadresse($adresse);
                    }
                    
                    // Save M:N relations
                    // Find all matrikkelenheter that have this adresse
                    if (isset($adresseToMatrikkelenheter[$adresseId])) {
                        foreach (array_keys($adresseToMatrikkelenheter[$adresseId]) as $matrikkelenhetId) {
                            $this->saveMatrikkelenhetAdresseRelation($matrikkelenhetId, $adresseId);
                            $relationCount++;
                        }
                    }
                }
                
            } catch (\Exception $e) {
                $io->error("Feil ved henting av adresse-objekter: " . $e->getMessage());
            }
            
            $progressBar2->advance(count($batch));
        }
        
        $progressBar2->finish();
        $io->newLine(2);
        
        return ['adresser' => $adresseCount, 'relations' => $relationCount];
    }
    
    /**
     * Save base Adresse entity to matrikkel_adresser table
     */
    private function saveAdresse(object $adresse, ?int $primaryMatrikkelenhetId = null): void
    {
        $adresseId = (int) $adresse->id->value;
        $adresseType = $this->getAdresseType($adresse);
        
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_adresser (
                adresse_id,
                adressetype,
                representasjonspunkt_x,
                representasjonspunkt_y,
                representasjonspunkt_z,
                koordinatsystem,
                adressetilleggsnavn,
                kortnavn,
                uuid,
                matrikkelenhet_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (adresse_id) DO UPDATE SET
                adressetype = EXCLUDED.adressetype,
                representasjonspunkt_x = EXCLUDED.representasjonspunkt_x,
                representasjonspunkt_y = EXCLUDED.representasjonspunkt_y,
                representasjonspunkt_z = EXCLUDED.representasjonspunkt_z,
                koordinatsystem = EXCLUDED.koordinatsystem,
                adressetilleggsnavn = EXCLUDED.adressetilleggsnavn,
                kortnavn = EXCLUDED.kortnavn,
                uuid = EXCLUDED.uuid,
                matrikkelenhet_id = EXCLUDED.matrikkelenhet_id,
                oppdatert = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $adresseId,
            $adresseType,
            $adresse->representasjonspunkt->position->x ?? null,
            $adresse->representasjonspunkt->position->y ?? null,
            $adresse->representasjonspunkt->position->z ?? null,
            isset($adresse->representasjonspunkt->koordinatsystemKodeId->value)
                ? $adresse->representasjonspunkt->koordinatsystemKodeId->value
                : null,
            $adresse->adressetilleggsnavn ?? null,
            $adresse->kortnavn ?? null,
            null,  // uuid - not directly available in response
            $primaryMatrikkelenhetId
        ]);
    }
    
    /**
     * Save Vegadresse subclass to matrikkel_vegadresser table
     */
    private function saveVegadresse(object $adresse): void
    {
        $adresseId = (int) $adresse->id->value;
        $vegId = isset($adresse->vegId) ? (int) $adresse->vegId->value : null;
        
        if (!$vegId) {
            error_log("AdresseImportService: VEGADRESSE missing veg_id for adresse_id=$adresseId");
            return;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_vegadresser (
                vegadresse_id,
                veg_id,
                nummer,
                bokstav
            ) VALUES (?, ?, ?, ?)
            ON CONFLICT (vegadresse_id) DO UPDATE SET
                veg_id = EXCLUDED.veg_id,
                nummer = EXCLUDED.nummer,
                bokstav = EXCLUDED.bokstav
        ");
        
        $stmt->execute([
            $adresseId,  // vegadresse_id references adresse_id
            $vegId,
            $adresse->nummer ?? null,
            $adresse->bokstav ?? null
        ]);
    }
    
    /**
     * Save M:N relationship to matrikkel_matrikkelenhet_adresse junction table
     */
    private function saveMatrikkelenhetAdresseRelation(int $matrikkelenhetId, int $adresseId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_matrikkelenhet_adresse (
                matrikkelenhet_id,
                adresse_id
            ) VALUES (?, ?)
            ON CONFLICT (matrikkelenhet_id, adresse_id) DO NOTHING
        ");
        
        $stmt->execute([$matrikkelenhetId, $adresseId]);
    }
    
    /**
     * Detect adresse type (VEGADRESSE or MATRIKKELADRESSE)
     * 
     * @param object $adresse SOAP object
     * @return string 'VEGADRESSE' or 'MATRIKKELADRESSE'
     */
    private function getAdresseType(object $adresse): string
    {
        // Check for vegadresse-specific properties
        if (isset($adresse->vegId) || isset($adresse->nummer)) {
            return 'VEGADRESSE';
        }
        
        return 'MATRIKKELADRESSE';
    }
}
