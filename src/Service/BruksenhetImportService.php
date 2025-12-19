<?php
/**
 * BruksenhetImportService - Import bruksenheter for filtered matrikkelenheter
 * 
 * This is Phase 2 Step 3: Import bruksenheter (dwelling units) for matrikkelenheter
 * that we already have in the database.
 * 
 * Strategy (Two-Step API Pattern):
 * 1. Get all matrikkelenhet_id from database
 * 2. Call BruksenhetClient.findBruksenheterForMatrikkelenheter() → Get bruksenhet IDs
 * 3. Batch fetch full Bruksenhet objects via StoreClient.getObjects()
 * 4. Save to matrikkel_bruksenheter table
 * 
 * Why this approach?
 * - API has direct method: findBruksenheterForMatrikkelenheter()
 * - Server-side filtering (only returns IDs for our matrikkelenheter)
 * - Two-step pattern is optimal for performance
 * 
 * @author Sigurd Nes
 * @date 2025-10-23
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\BruksenhetClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\BruksenhetId;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class BruksenhetImportService
{
    private StoreClient $storeClient;
    private BruksenhetClient $bruksenhetClient;
    private PDO $db;
    private MatrikkelenhetImportService $matrikkelenhetImportService;
    
    public function __construct(
        StoreClient $storeClient,
        BruksenhetClient $bruksenhetClient,
        PDO $db,
        MatrikkelenhetImportService $matrikkelenhetImportService
    ) {
        $this->storeClient = $storeClient;
        $this->bruksenhetClient = $bruksenhetClient;
        $this->db = $db;
        $this->matrikkelenhetImportService = $matrikkelenhetImportService;
    }
    
    /**
     * Import bruksenheter for all matrikkelenheter in database
     * 
     * @param SymfonyStyle $io Console output
     * @param int $kommunenummer Kommune number (for logging)
     * @param array $matrikkelenhetIds Optional: specific matrikkelenhet IDs to import (if empty, imports all from DB)
     * @param int $batchSize Batch size for StoreClient calls
     * @return int Number of bruksenheter imported
     */
    public function importBruksenheterForMatrikkelenheter(
        SymfonyStyle $io,
        int $kommunenummer,
        array $matrikkelenhetIds = [],
        int $batchSize = 500
    ): int {
        $io->section("Importing bruksenheter for matrikkelenheter");
        
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
            return 0;
        }
        
        $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter");
        
        // 2. Find Bruksenhet IDs via API (Two-step pattern: Step 1)
        $io->text("Finner bruksenhet-IDs via API...");
        
        $allBruksenhetIds = [];
        $matrikkelenhetToBruksenheter = [];
        
        $progressBar = $io->createProgressBar(count($matrikkelenhetIds));
        $progressBar->setFormat('very_verbose');
        
        // Batch process matrikkelenheter (500 per batch)
        foreach (array_chunk($matrikkelenhetIds, 500) as $batch) {
            $matrikkelenhetIdObjects = array_map(
                fn($id) => new MatrikkelenhetId($id),
                $batch
            );
            
            try {
                // API call: findBruksenheterForMatrikkelenheter()
                // WSDL parameter: matrikkelenhetIds (type: MatrikkelenhetIdList with item array)
                $result = $this->bruksenhetClient->findBruksenheterForMatrikkelenheter([
                    'matrikkelenhetIds' => ['item' => $matrikkelenhetIdObjects]
                ]);
                
                // Response structure: return->entry[] (Map entries)
                // Each entry has: key (MatrikkelenhetId), value->item[] (BruksenhetId list)
                if (isset($result->return) && isset($result->return->entry)) {
                    $entries = is_array($result->return->entry)
                        ? $result->return->entry
                        : [$result->return->entry];
                    
                    foreach ($entries as $entry) {
                        $matrikkelenhetId = $entry->key->value ?? null;
                        
                        // Extract bruksenhet IDs from entry->value->item
                        if ($matrikkelenhetId && isset($entry->value) && isset($entry->value->item)) {
                            $bruksenhetIdObjects = is_array($entry->value->item)
                                ? $entry->value->item
                                : [$entry->value->item];
                            
                            $bruksenhetIds = [];
                            foreach ($bruksenhetIdObjects as $bruksenhetIdObj) {
                                $bruksenhetId = $bruksenhetIdObj->value ?? null;
                                if ($bruksenhetId) {
                                    $allBruksenhetIds[] = $bruksenhetId;
                                    $bruksenhetIds[] = $bruksenhetId;
                                }
                            }
                            
                            $matrikkelenhetToBruksenheter[$matrikkelenhetId] = $bruksenhetIds;
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->error("Feil ved henting av bruksenhet-IDs: " . $e->getMessage());
            }
            
            $progressBar->advance(count($batch));
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
        if (empty($allBruksenhetIds)) {
            $io->warning("Ingen bruksenheter funnet for matrikkelenhetene!");
            return 0;
        }
        
        $io->success("Funnet " . count($allBruksenhetIds) . " bruksenhet-IDs");
        
        // 3. Fetch full Bruksenhet objects via StoreClient (Two-step pattern: Step 2)
        $io->text("Henter fullstendige bruksenhet-objekter...");
        
        $bruksenhetCount = 0;
        
        $progressBar2 = $io->createProgressBar(count($allBruksenhetIds));
        $progressBar2->setFormat('very_verbose');
        
        foreach (array_chunk($allBruksenhetIds, 500) as $batch) {
            $bruksenhetIdObjects = array_map(
                fn($id) => new BruksenhetId($id),
                $batch
            );
            
            try {
                $objects = $this->storeClient->getObjects($bruksenhetIdObjects);
                
                foreach ($objects as $bruksenhet) {
                    $matrikkelenhetId = isset($bruksenhet->matrikkelenhetId) 
                        ? $bruksenhet->matrikkelenhetId->value 
                        : null;
                    
                    if ($this->saveBruksenhet($bruksenhet, $matrikkelenhetId)) {
                        $bruksenhetCount++;
                    }
                    
                    $progressBar2->advance();
                }
            } catch (\Exception $e) {
                $io->error("Feil ved lagring av bruksenheter: " . $e->getMessage());
                $progressBar2->advance(count($batch));
            }
        }
        
        $progressBar2->finish();
        $io->newLine(2);
        
        $io->success("Importert $bruksenhetCount bruksenheter");
        
        return $bruksenhetCount;
    }
    
    /**
     * Complete missing bruksenheter for bygninger
     * 
     * After importing bygninger, check each bygning for associated bruksenheter.
     * If any are missing from database, fetch them via API.
     * 
     * @param SymfonyStyle $io Console output
     * @param array $bygningIds Array of bygning_id integers to check
     * @return int Number of bruksenheter imported
     */
    public function completeMissingBruksenheterForBygninger(
        SymfonyStyle $io,
        array $bygningIds
    ): int {
        if (empty($bygningIds)) {
            return 0;
        }
        
        $io->text(sprintf('Sjekker %d bygninger for manglende bruksenheter...', count($bygningIds)));
        
        $io->text('Henter bruksenheter fra API for alle bygninger...');
        
        $allNewBruksenhetIds = [];
        $bygningToBruksenheter = [];
        
        // Batch process bygninger (100 per batch to avoid timeout)
        $progressBar = $io->createProgressBar(count($bygningIds));
        $progressBar->setFormat('very_verbose');
        
        foreach (array_chunk($bygningIds, 100) as $batch) {
            $bygningIdObjects = array_map(
                fn($id) => new \Iaasen\Matrikkel\Client\BygningId($id),
                $batch
            );
            
            try {
                // API call: findBruksenheterForByggList()
                $result = $this->bruksenhetClient->findBruksenheterForByggList([
                    'byggIds' => ['item' => $bygningIdObjects]
                ]);
                
                // Response structure: return->entry[] (Map entries)
                if (isset($result->return) && isset($result->return->entry)) {
                    $entries = is_array($result->return->entry)
                        ? $result->return->entry
                        : [$result->return->entry];
                    
                    foreach ($entries as $entry) {
                        $bygningId = $entry->key->value ?? null;
                        
                        if ($bygningId && isset($entry->value) && isset($entry->value->item)) {
                            $bruksenhetIdObjects = is_array($entry->value->item)
                                ? $entry->value->item
                                : [$entry->value->item];
                            
                            $bruksenhetIds = [];
                            foreach ($bruksenhetIdObjects as $bruksenhetIdObj) {
                                $bruksenhetId = $bruksenhetIdObj->value ?? null;
                                if ($bruksenhetId) {
                                    $bruksenhetIds[] = $bruksenhetId;
                                }
                            }
                            
                            if (!empty($bruksenhetIds)) {
                                $bygningToBruksenheter[$bygningId] = $bruksenhetIds;
                                $allNewBruksenhetIds = array_merge($allNewBruksenhetIds, $bruksenhetIds);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->error("Feil ved henting av bruksenhet-IDs for bygninger: " . $e->getMessage());
            }
            
            $progressBar->advance(count($batch));
        }
        
        $progressBar->finish();
        $io->newLine();
        
        if (empty($allNewBruksenhetIds)) {
            $io->text('Ingen nye bruksenheter funnet for bygningene');
            return 0;
        }
        
        // Filter out bruksenheter that already exist in database
        $allNewBruksenhetIds = array_unique($allNewBruksenhetIds);
        $placeholders = implode(',', array_fill(0, count($allNewBruksenhetIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT bruksenhet_id FROM matrikkel_bruksenheter WHERE bruksenhet_id IN ($placeholders)"
        );
        $stmt->execute($allNewBruksenhetIds);
        $existingBruksenhetIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'bruksenhet_id');
        
        $newBruksenhetIds = array_diff($allNewBruksenhetIds, $existingBruksenhetIds);
        
        if (empty($newBruksenhetIds)) {
            $io->text('Alle bruksenheter finnes allerede i databasen');
            return 0;
        }
        
        $io->text(sprintf('Fant %d nye bruksenheter å laste ned', count($newBruksenhetIds)));
        
        // Fetch full Bruksenhet objects via StoreClient
        $bruksenhetCount = 0;
        $progressBar2 = $io->createProgressBar(count($newBruksenhetIds));
        $progressBar2->setFormat('very_verbose');
        
        foreach (array_chunk($newBruksenhetIds, 500) as $batch) {
            $bruksenhetIdObjects = array_map(
                fn($id) => new \Iaasen\Matrikkel\Client\BruksenhetId($id),
                $batch
            );
            
            try {
                $objects = $this->storeClient->getObjects($bruksenhetIdObjects);
                
                foreach ($objects as $bruksenhet) {
                    $matrikkelenhetId = isset($bruksenhet->matrikkelenhetId) 
                        ? $bruksenhet->matrikkelenhetId->value 
                        : null;
                    
                    if ($this->saveBruksenhet($bruksenhet, $matrikkelenhetId)) {
                        $bruksenhetCount++;
                    }
                    
                    $progressBar2->advance();
                }
            } catch (\Exception $e) {
                $io->error("Feil ved lagring av bruksenheter: " . $e->getMessage());
                $progressBar2->advance(count($batch));
            }
        }
        
        $progressBar2->finish();
        $io->newLine();
        
        return $bruksenhetCount;
    }
    
    /**
     * Save bruksenhet to database
     * 
     * @param object $bruksenhet Bruksenhet SOAP object
     * @param int|null $matrikkelenhetId Matrikkelenhet ID (if null, extracted from bruksenhet object)
     * @return bool Success
     */
    private function saveBruksenhet($bruksenhet, ?int $matrikkelenhetId): bool
    {
        try {
            // Extract bruksenhet_id
            $bruksenhetId = $bruksenhet->id->value ?? null;
            if (!$bruksenhetId) {
                error_log("saveBruksenhet: Missing bruksenhet_id");
                return false;
            }
            
            // Extract matrikkelenhetId if not provided
            if ($matrikkelenhetId === null) {
                $matrikkelenhetId = isset($bruksenhet->matrikkelenhetId) 
                    ? $bruksenhet->matrikkelenhetId->value 
                    : null;
            }
            
            // If still no matrikkelenhetId, try to find one from bygning
            if ($matrikkelenhetId === null && isset($bruksenhet->byggId)) {
                $bygningId = $bruksenhet->byggId->value ?? null;
                if ($bygningId) {
                    // Find a matrikkelenhet for this bygning from the M:N table
                    $stmt = $this->db->prepare(
                        "SELECT matrikkelenhet_id FROM matrikkel_bygning_matrikkelenhet 
                         WHERE bygning_id = ? LIMIT 1"
                    );
                    $stmt->execute([$bygningId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result) {
                        $matrikkelenhetId = (int) $result['matrikkelenhet_id'];
                    }
                }
            }
            
            // Skip if still no matrikkelenhetId
            if ($matrikkelenhetId === null) {
                return false;
            }
            
            // Validate that matrikkelenhetId exists in database
            // This prevents FK constraint violations when bruksenheter reference non-existent matrikkelenheter
            $stmt = $this->db->prepare("SELECT 1 FROM matrikkel_matrikkelenheter WHERE matrikkelenhet_id = ? LIMIT 1");
            $stmt->execute([$matrikkelenhetId]);
            if (!$stmt->fetch()) {
                // Matrikkelenhet doesn't exist - try to fetch and import it from API
                error_log("Bruksenhet $bruksenhetId: Matrikkelenhet $matrikkelenhetId not in DB. Attempting to import from API...");
                
                try {
                    // Try to fetch this single matrikkelenhet from StoreClient
                    $matrikkelenhetIdObj = new \Iaasen\Matrikkel\Client\MatrikkelenhetId();
                    $matrikkelenhetIdObj->value = $matrikkelenhetId;
                    
                    $fetched = $this->storeClient->getObjects([$matrikkelenhetIdObj]);
                    
                    if (!empty($fetched)) {
                        // Get the first (and should be only) object
                        $matrikkelenhetObj = reset($fetched);
                        
                        // Extract matrikkelenhet fields from the fetched object
                        $kommunenr = $matrikkelenhetObj->matrikkelenhetId->kommunenummer ?? null;
                        $gnr = $matrikkelenhetObj->matrikkelenhetId->gardsnummer ?? null;
                        $bnr = $matrikkelenhetObj->matrikkelenhetId->bruksnummer ?? null;
                        $fnr = $matrikkelenhetObj->matrikkelenhetId->festenummer ?? 0;
                        $snr = $matrikkelenhetObj->matrikkelenhetId->seksjonsnummer ?? 0;
                        
                        // Build matrikkelnummer tekst
                        $matrikkelnummerTekst = $matrikkelenhetObj->matrikkelnummerTekst ?? 
                            "$kommunenr/$gnr/$bnr";
                        if ($fnr > 0) $matrikkelnummerTekst .= "-$fnr";
                        if ($snr > 0) $matrikkelnummerTekst .= "-$snr";
                        
                        // Import it to database
                        $stmt = $this->db->prepare("
                            INSERT INTO matrikkel_matrikkelenheter (
                                matrikkelenhet_id,
                                kommunenummer,
                                gardsnummer,
                                bruksnummer,
                                festenummer,
                                seksjonsnummer,
                                matrikkelnummer_tekst,
                                timestamp_created,
                                timestamp_updated
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ON CONFLICT (matrikkelenhet_id) DO NOTHING
                        ");
                        
                        $stmt->execute([
                            $matrikkelenhetId,
                            $kommunenr,
                            $gnr,
                            $bnr,
                            $fnr,
                            $snr,
                            $matrikkelnummerTekst
                        ]);
                        
                        error_log("✓ Successfully imported matrikkelenhet $matrikkelenhetId from API");
                    } else {
                        error_log("⚠ Could not fetch matrikkelenhet $matrikkelenhetId from API - skipping bruksenhet");
                        return false;
                    }
                } catch (\Exception $e) {
                    error_log("✗ Failed to import matrikkelenhet $matrikkelenhetId: " . $e->getMessage() . " - skipping bruksenhet");
                    return false;
                }
            }
            
            // Extract other fields
            $lopenummer = $bruksenhet->lopenummer ?? null;
            $uuid = isset($bruksenhet->uuid) ? $bruksenhet->uuid->uuid : null;
            
            // Extract kode IDs - these are MatrikkelBubbleId objects with ->value property
            // Note: 0 can be a valid value (means "not specified"), so we include it
            $bruksenhetstypeKodeId = isset($bruksenhet->bruksenhetstypeKodeId) && isset($bruksenhet->bruksenhetstypeKodeId->value)
                ? $bruksenhet->bruksenhetstypeKodeId->value 
                : null;
            $etasjeplanKodeId = isset($bruksenhet->etasjeplanKodeId) && isset($bruksenhet->etasjeplanKodeId->value)
                ? $bruksenhet->etasjeplanKodeId->value 
                : null;
            $kjokkentilgangKodeId = isset($bruksenhet->kjokkentilgangId) && isset($bruksenhet->kjokkentilgangId->value)
                ? $bruksenhet->kjokkentilgangId->value 
                : null;
            $kostraFunksjonKodeId = isset($bruksenhet->kostraFunksjonKodeId) && isset($bruksenhet->kostraFunksjonKodeId->value)
                ? $bruksenhet->kostraFunksjonKodeId->value 
                : null;
            
            $etasjenummer = $bruksenhet->etasjenummer ?? null;
            $adresseId = isset($bruksenhet->adresseId) ? $bruksenhet->adresseId->value : null;
            $antallRom = $bruksenhet->antallRom ?? null;
            $antallBad = $bruksenhet->antallBad ?? null;
            $antallWC = $bruksenhet->antallWC ?? null;
            $bruksareal = $bruksenhet->bruksareal ?? null;
            $bygningId = isset($bruksenhet->byggId) && isset($bruksenhet->byggId->value)
                ? $bruksenhet->byggId->value
                : null;
            
            // Insert or update bruksenhet
            $stmt = $this->db->prepare("
                INSERT INTO matrikkel_bruksenheter (
                    bruksenhet_id,
                    matrikkelenhet_id,
                    bygning_id,
                    lopenummer,
                    uuid,
                    bruksenhettype_kode_id,
                    etasjeplan_kode_id,
                    kjokkentilgang_kode_id,
                    kostra_funksjon_kode_id,
                    etasjenummer,
                    adresse_id,
                    antall_rom,
                    antall_bad,
                    antall_wc,
                    bruksareal,
                    sist_lastet_ned,
                    opprettet,
                    oppdatert
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (bruksenhet_id) DO UPDATE SET
                    matrikkelenhet_id = EXCLUDED.matrikkelenhet_id,
                    bygning_id = EXCLUDED.bygning_id,
                    lopenummer = EXCLUDED.lopenummer,
                    uuid = EXCLUDED.uuid,
                    bruksenhettype_kode_id = EXCLUDED.bruksenhettype_kode_id,
                    etasjeplan_kode_id = EXCLUDED.etasjeplan_kode_id,
                    kjokkentilgang_kode_id = EXCLUDED.kjokkentilgang_kode_id,
                    kostra_funksjon_kode_id = EXCLUDED.kostra_funksjon_kode_id,
                    etasjenummer = EXCLUDED.etasjenummer,
                    adresse_id = EXCLUDED.adresse_id,
                    antall_rom = EXCLUDED.antall_rom,
                    antall_bad = EXCLUDED.antall_bad,
                    antall_wc = EXCLUDED.antall_wc,
                    bruksareal = EXCLUDED.bruksareal,
                    sist_lastet_ned = CURRENT_TIMESTAMP,
                    oppdatert = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $bruksenhetId,
                $matrikkelenhetId,
                $bygningId,
                $lopenummer,
                $uuid,
                $bruksenhetstypeKodeId,
                $etasjeplanKodeId,
                $kjokkentilgangKodeId,
                $kostraFunksjonKodeId,
                $etasjenummer,
                $adresseId,
                $antallRom,
                $antallBad,
                $antallWC,
                $bruksareal
            ]);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("Feil ved lagring av bruksenhet: " . $e->getMessage());
            return false;
        }
    }
}
