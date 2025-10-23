<?php
/**
 * PersonImportService - Import Personer fra Eierforhold
 * 
 * Dette er Phase 1, Step 3: Hent alle personer som eier matrikkelenheter
 * i kommunen og lagre dem i databasen.
 * 
 * Flow:
 * 1. Hent alle matrikkelenheter for kommunen fra database
 * 2. Batch-hent komplette matrikkelenheter med eierforhold fra StoreService
 * 3. Ekstraher unike person-IDer fra eierforhold
 * 4. Batch-hent komplette person-objekter fra StoreService
 * 5. Skille mellom fysiske og juridiske personer
 * 6. Lagre i matrikkel_personer eller matrikkel_juridiske_personer
 * 
 * Database schema:
 * - matrikkel_personer: Fysiske personer (fodselsnummer)
 * - matrikkel_juridiske_personer: Juridiske personer (organisasjonsnummer)
 * 
 * Performance:
 * - Batch size: 500 matrikkelenheter per StoreService call
 * - Batch size: 500 personer per StoreService call
 * - For Bergen (50,000 matrikkelenheter): ~100 API-kall for matrikkelenheter + ~40 API-kall for personer
 * 
 * @author Matrikkel Integration System
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\PersonId;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class PersonImportService
{
    private StoreClient $storeClient;
    private PDO $db;
    
    public function __construct(StoreClient $storeClient, PDO $db)
    {
        $this->storeClient = $storeClient;
        $this->db = $db;
    }
    
    /**
     * Importer alle personer som eier matrikkelenheter i kommunen
     * 
     * @param SymfonyStyle $io Console output
     * @param int $kommunenummer Kommune nummer (f.eks. 4601)
     * @param int $batchSize Batch size for StoreService calls (default 500)
     * @return int Antall personer importert
     */
    public function importPersonerForKommune(SymfonyStyle $io, int $kommunenummer, int $batchSize = 500): int
    {
        $io->section("Step 3: Import Personer for kommune $kommunenummer");
        
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
        
        $allEierforhold = [];
        $progressBar = $io->createProgressBar(count($matrikkelenhetIds));
        $progressBar->setFormat('very_verbose');
        
        foreach (array_chunk($matrikkelenhetIds, $batchSize) as $batch) {
            $objects = $this->storeClient->getObjects($batch);
            
            foreach ($objects as $obj) {
                // Ekstraher eierforhold
                // Structure: $obj->eierforhold->item (can be single object or array)
                if (isset($obj->eierforhold)) {
                    $eierforholdWrapper = $obj->eierforhold;
                    
                    // Handle SOAP wrapper structure: eierforhold.item can be single or array
                    if (isset($eierforholdWrapper->item)) {
                        $items = is_array($eierforholdWrapper->item) 
                            ? $eierforholdWrapper->item 
                            : [$eierforholdWrapper->item];
                        $allEierforhold = array_merge($allEierforhold, $items);
                    }
                }
                
                $progressBar->advance();
            }
        }
        
        $progressBar->finish();
        $io->newLine(2);
        $io->success("Funnet " . count($allEierforhold) . " eierforhold");
        
        // 3. Ekstraher unike person-IDer
        $io->text("Ekstraherer unike person-IDer...");
        $personIds = [];
        foreach ($allEierforhold as $eierforhold) {
            // Structure: $eierforhold->eierId (PersonId object with ->value)
            if (isset($eierforhold->eierId)) {
                $eierId = $eierforhold->eierId;
                
                // eierId is a PersonId object with value property
                if (is_object($eierId) && isset($eierId->value)) {
                    $value = $eierId->value;
                    if ($value) {
                        $personIds[$value] = $eierId;
                    }
                }
            }
        }
        
        $io->success("Funnet " . count($personIds) . " unike personer");
        
        if (empty($personIds)) {
            $io->warning("Ingen personer funnet i eierforhold!");
            return 0;
        }
        
        // 4. Batch-hent komplette person-objekter fra StoreService
        $io->text("Henter komplette person-objekter fra Matrikkel API...");
        $personCount = 0;
        $fysiskCount = 0;
        $juridiskCount = 0;
        
        $progressBar = $io->createProgressBar(count($personIds));
        $progressBar->setFormat('very_verbose');
        
        foreach (array_chunk(array_values($personIds), $batchSize) as $batch) {
            $personer = $this->storeClient->getObjects($batch);
            
            foreach ($personer as $person) {
                // Sjekk om dette er juridisk person (har organisasjonsformKode)
                $isJuridisk = isset($person->organisasjonsformKode);
                
                // Skille mellom fysiske og juridiske personer
                if ($isJuridisk) {
                    $this->saveJuridiskPerson($person);
                    $juridiskCount++;
                } else {
                    $this->saveFysiskPerson($person);
                    $fysiskCount++;
                }
                
                $personCount++;
                $progressBar->advance();
            }
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
        $io->success("Importert $personCount personer totalt:");
        $io->listing([
            "Fysiske personer: $fysiskCount",
            "Juridiske personer: $juridiskCount"
        ]);
        
        return $personCount;
    }
    
    /**
     * Lagre fysisk person i database
     */
    private function saveFysiskPerson($person): void
    {
        // PersonId is in id->value (not personId)
        $personId = $person->id->value ?? null;
        if (!$personId) {
            error_log("[PersonImportService] Fysisk person mangler id->value: " . print_r($person, true));
            return;
        }
        
        $fornavn = $person->fornavn ?? null;
        $etternavn = $person->etternavn ?? null;
        $navn = $person->navn ?? trim(($fornavn ?? '') . ' ' . ($etternavn ?? ''));
        $uuid = isset($person->uuid) ? $person->uuid->uuid ?? null : null;
        
        // nummer field contains fødselsnummer (may be masked with XXXXX)
        $fodselsnummer = $person->nummer ?? null;
        $validFodselsnummer = ($fodselsnummer && strlen($fodselsnummer) === 11 && !strpos($fodselsnummer, 'X')) 
            ? $fodselsnummer 
            : null;
        
        // FIRST: Always insert into matrikkel_personer (parent table)
        // Schema columns: id, matrikkel_person_id, uuid, nummer, navn, postadresse_*, sist_lastet_ned
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_personer (
                matrikkel_person_id, uuid, nummer, navn, sist_lastet_ned
            ) VALUES (
                ?, ?, ?, ?, CURRENT_TIMESTAMP
            )
            ON CONFLICT (matrikkel_person_id) DO UPDATE SET
                uuid = EXCLUDED.uuid,
                nummer = EXCLUDED.nummer,
                navn = EXCLUDED.navn,
                sist_lastet_ned = CURRENT_TIMESTAMP
            RETURNING id
        ");
        
        $stmt->execute([
            $personId,
            $uuid,
            $fodselsnummer,
            $navn
        ]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $dbId = $row['id'];
        
        // SECOND: Insert into matrikkel_fysiske_personer (child table with FK to matrikkel_personer.id)
        // Schema columns: id (FK), fodselsnummer, etternavn, fornavn, person_status_kode_id, bostedsadresse_*, hjemlandsadresse_*
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_fysiske_personer (
                id, fodselsnummer, etternavn, fornavn
            ) VALUES (
                ?, ?, ?, ?
            )
            ON CONFLICT (id) DO UPDATE SET
                fodselsnummer = EXCLUDED.fodselsnummer,
                etternavn = EXCLUDED.etternavn,
                fornavn = EXCLUDED.fornavn
        ");
        
        $stmt->execute([
            $dbId,
            $validFodselsnummer,
            $etternavn,
            $fornavn
        ]);
    }
    
    /**
     * Lagre juridisk person i database
     */
    private function saveJuridiskPerson($person): void
    {
        // PersonId is in id->value (not personId)
        $juridiskPersonId = $person->id->value ?? null;
        if (!$juridiskPersonId) {
            error_log("[PersonImportService] Juridisk person mangler id->value: " . print_r($person, true));
            return;
        }
        
        $navn = $person->navn ?? null;
        $uuid = isset($person->uuid) ? $person->uuid->uuid ?? null : null;
        
        // nummer field contains organisasjonsnummer (may be masked)
        $organisasjonsnummer = $person->nummer ?? null;
        $validOrgnr = ($organisasjonsnummer && strlen($organisasjonsnummer) === 9 && ctype_digit($organisasjonsnummer)) 
            ? $organisasjonsnummer 
            : null;
        
        $organisasjonsformKode = null;
        
        // Ekstraher organisasjonsform hvis tilgjengelig
        if (isset($person->organisasjonsformKode)) {
            $orgformKode = $person->organisasjonsformKode;
            $organisasjonsformKode = is_object($orgformKode)
                ? ($orgformKode->orgformKode ?? null)
                : $orgformKode;
        }
        
        // FIRST: Always insert into matrikkel_personer (parent table)
        // Schema columns: id, matrikkel_person_id, uuid, nummer, navn, postadresse_*, sist_lastet_ned
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_personer (
                matrikkel_person_id, uuid, nummer, navn, sist_lastet_ned
            ) VALUES (
                ?, ?, ?, ?, CURRENT_TIMESTAMP
            )
            ON CONFLICT (matrikkel_person_id) DO UPDATE SET
                uuid = EXCLUDED.uuid,
                nummer = EXCLUDED.nummer,
                navn = EXCLUDED.navn,
                sist_lastet_ned = CURRENT_TIMESTAMP
            RETURNING id
        ");
        
        $stmt->execute([
            $juridiskPersonId,
            $uuid,
            $organisasjonsnummer,
            $navn
        ]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $dbId = $row['id'];
        
        // SECOND: Insert into matrikkel_juridiske_personer (child table with FK to matrikkel_personer.id)
        // Schema columns: id (FK), organisasjonsnummer, organisasjonsform_kode, slettet_dato, forretningsadresse_*
        $stmt = $this->db->prepare("
            INSERT INTO matrikkel_juridiske_personer (
                id, organisasjonsnummer, organisasjonsform_kode
            ) VALUES (
                ?, ?, ?
            )
            ON CONFLICT (id) DO UPDATE SET
                organisasjonsnummer = EXCLUDED.organisasjonsnummer,
                organisasjonsform_kode = EXCLUDED.organisasjonsform_kode
        ");
        
        $stmt->execute([
            $dbId,
            $validOrgnr,
            $organisasjonsformKode
        ]);
    }
}
