<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\BygningClient;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\BygningId;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import bygninger using two-step API pattern:
 * 1. Find bygning IDs for specific matrikkelenheter via BygningClient
 * 2. Fetch full bygning objects via StoreClient
 */
class BygningImportService
{
    private const BATCH_SIZE_IDS = 200;      // Max matrikkelenheter per findByggForMatrikkelenheter() call
    private const BATCH_SIZE_OBJECTS = 500;   // Max bygninger per StoreClient.getObjects() call

    public function __construct(
        private BygningClient $bygningClient,
        private StoreClient $storeClient,
        private \PDO $db
    ) {}

    /**
     * Import bygninger for given matrikkelenhet IDs using two-step pattern
     *
     * @param array<int> $matrikkelenhetIds Array of matrikkelenhet IDs to find bygninger for
     * @param SymfonyStyle|null $io Console output for progress indication
     * @return array{bygninger: int, relations: int} Count of imported bygninger and relations
     */
    public function importBygningerForMatrikkelenheter(array $matrikkelenhetIds, ?SymfonyStyle $io = null): array
    {
        if (empty($matrikkelenhetIds)) {
            return ['bygninger' => 0, 'relations' => 0];
        }

        // Step 1: Find bygning IDs for these matrikkelenheter (in batches)
        if ($io) {
            $io->text("Finner bygning-IDs via API...");
        }
        
        $allBygningIds = [];
        $bygningToMatrikkelMap = []; // Track which bygninger belong to which matrikkelenheter

        // Create progress bar for Step 1
        $progressBar = null;
        if ($io) {
            $progressBar = $io->createProgressBar(count($matrikkelenhetIds));
            $progressBar->setFormat('very_verbose');
        }

        foreach (array_chunk($matrikkelenhetIds, self::BATCH_SIZE_IDS) as $batch) {
            $matrikkelenhetIdObjects = array_map(
                fn($id) => new MatrikkelenhetId($id),
                $batch
            );

            // WSDL: BygningServiceWS_schema1.xsd line 188 - parameter is "matrikkelenhetIdList"
            $result = $this->bygningClient->findByggForMatrikkelenheter([
                'matrikkelenhetIdList' => ['item' => $matrikkelenhetIdObjects]
            ]);

            // Parse response: matrikkelenhetIdTilByggIdsMap with entries
            if (isset($result->return->entry)) {
                $entries = is_array($result->return->entry) ? $result->return->entry : [$result->return->entry];
                
                foreach ($entries as $entry) {
                    $matrikkelenhetId = (int) $entry->key->value;
                    
                    if (isset($entry->value->item)) {
                        $byggIds = is_array($entry->value->item) ? $entry->value->item : [$entry->value->item];
                        
                        foreach ($byggIds as $byggId) {
                            $bygningId = (int) $byggId->value;
                            $allBygningIds[] = $bygningId;
                            
                            // Track M:N relationship
                            if (!isset($bygningToMatrikkelMap[$bygningId])) {
                                $bygningToMatrikkelMap[$bygningId] = [];
                            }
                            $bygningToMatrikkelMap[$bygningId][] = $matrikkelenhetId;
                        }
                    }
                }
            }
            
            if ($progressBar) {
                $progressBar->advance(count($batch));
            }
        }

        if ($progressBar) {
            $progressBar->finish();
            $io->newLine(2);
        }

        if (empty($allBygningIds)) {
            if ($io) {
                $io->warning("Ingen bygninger funnet for matrikkelenhetene!");
            }
            return ['bygninger' => 0, 'relations' => 0];
        }

        $allBygningIds = array_unique($allBygningIds);
        
        if ($io) {
            $io->success("Funnet " . count($allBygningIds) . " bygning-IDs");
            $io->text("Henter fullstendige bygning-objekter...");
        }

        // Step 2: Fetch full bygning objects via StoreClient (in batches)
        $bygningerCount = 0;
        $relationsCount = 0;

        // Create progress bar for Step 2
        $progressBar2 = null;
        if ($io) {
            $progressBar2 = $io->createProgressBar(count($allBygningIds));
            $progressBar2->setFormat('very_verbose');
        }

        foreach (array_chunk($allBygningIds, self::BATCH_SIZE_OBJECTS) as $batch) {
            $bygningIdObjects = array_map(
                fn($id) => new BygningId((int)$id),
                $batch
            );

            try {
                $objects = $this->storeClient->getObjects($bygningIdObjects);
            } catch (\Exception $e) {
                error_log("StoreClient error: " . $e->getMessage());
                error_log("Sample IDs in batch: " . print_r(array_slice($batch, 0, 3), true));
                throw $e;
            }

            foreach ($objects as $bygning) {
                $this->saveBygning($bygning);
                $bygningerCount++;

                // Save M:N relations in junction table
                $bygningId = (int) $bygning->id->value;
                if (isset($bygningToMatrikkelMap[$bygningId])) {
                    foreach ($bygningToMatrikkelMap[$bygningId] as $matrikkelenhetId) {
                        $this->saveBygningMatrikkelenhetRelation($bygningId, $matrikkelenhetId);
                        $relationsCount++;
                    }
                }
                
                if ($progressBar2) {
                    $progressBar2->advance();
                }
            }
        }

        if ($progressBar2) {
            $progressBar2->finish();
            $io->newLine(2);
        }

        if ($io) {
            $io->success("Importert $bygningerCount bygninger og $relationsCount relasjoner");
        }

        return ['bygninger' => $bygningerCount, 'relations' => $relationsCount];
    }

    private function saveBygning(object $bygning): void
    {
        $bygningId = (int) $bygning->id->value;
        $bygningsnummer = isset($bygning->bygningsnummer) ? (int) $bygning->bygningsnummer : null;
        $lopenummer = isset($bygning->lopenummer) ? (int) $bygning->lopenummer : null;
        
        // UUID might be an object or string
        $uuid = null;
        if (isset($bygning->uuid)) {
            if (is_object($bygning->uuid)) {
                $uuid = isset($bygning->uuid->value) ? (string) $bygning->uuid->value : null;
            } else {
                $uuid = (string) $bygning->uuid;
            }
        }
        
        // Extract kode IDs - these are MatrikkelBubbleId objects with ->value property
        // Note: 0 can be a valid value (means "not specified"), so we include it
        $bygningstype_kode_id = isset($bygning->bygningstypeKodeId) && isset($bygning->bygningstypeKodeId->value)
            ? $bygning->bygningstypeKodeId->value
            : null;
        $bygningsstatus_kode_id = isset($bygning->bygningsstatusKodeId) && isset($bygning->bygningsstatusKodeId->value)
            ? $bygning->bygningsstatusKodeId->value
            : null;
        
        // Areal
        $bebygd_areal = isset($bygning->bebygdAreal) ? (float) $bygning->bebygdAreal : null;
        
        // bruksarealTotalt is in etasjedata object, not on top level
        $bruksareal = null;
        if (isset($bygning->etasjedata) && isset($bygning->etasjedata->bruksarealTotalt)) {
            $bruksareal = (float) $bygning->etasjedata->bruksarealTotalt;
        }
        
        $uten_bebygd_areal = isset($bygning->utenBebygdAreal) && $bygning->utenBebygdAreal ? true : false;
        $ufullstendig_areal = isset($bygning->ufullstendigAreal) && $bygning->ufullstendigAreal ? true : false;
        
        // Year and counts
        // byggeaar: Extract year from "Tatt i bruk" status in bygningsstatusHistorikker
        // Status code 4 = "Tatt i bruk" (taken into use)
        $byggeaar = null;
        if (isset($bygning->bygningsstatusHistorikker) && isset($bygning->bygningsstatusHistorikker->item)) {
            $statusItems = is_array($bygning->bygningsstatusHistorikker->item) 
                ? $bygning->bygningsstatusHistorikker->item 
                : [$bygning->bygningsstatusHistorikker->item];
            
            // Look for "Tatt i bruk" status (code 4)
            foreach ($statusItems as $statusItem) {
                if (isset($statusItem->bygningsstatusKodeId) 
                    && $statusItem->bygningsstatusKodeId->value == 4
                    && isset($statusItem->registrertDato)
                    && isset($statusItem->registrertDato->timestamp)) {
                    // Extract year from timestamp like "2010-09-23T13:29:45.000000000+02:00"
                    $timestamp = $statusItem->registrertDato->timestamp;
                    $byggeaar = (int) substr($timestamp, 0, 4);
                    break; // Use first "Tatt i bruk" status found
                }
            }
        }
        
        // antallEtasjer is in kommunalTilleggsdel according to WSDL (kommunetillegg.xsd line 154)
        $antall_etasjer = null;
        if (isset($bygning->kommunalTilleggsdel) && isset($bygning->kommunalTilleggsdel->antallEtasjer)) {
            $antall_etasjer = (int) $bygning->kommunalTilleggsdel->antallEtasjer;
        }
        
        // Boolean flags
        $har_heis = isset($bygning->harHeis) && $bygning->harHeis ? true : false;
        $har_sefrakminne = isset($bygning->harSefrakminne) && $bygning->harSefrakminne ? true : false;
        $har_kulturminne = isset($bygning->harKulturminne) && $bygning->harKulturminne ? true : false;
        $skjermingsverdig = isset($bygning->skjermingsverdig) && $bygning->skjermingsverdig ? true : false;
        $nymatrikulert = isset($bygning->nymatrikulert) && $bygning->nymatrikulert ? true : false;
        
        // Extract more kode IDs
        $avlops_kode_id = isset($bygning->avlopsKodeId) && isset($bygning->avlopsKodeId->value)
            ? $bygning->avlopsKodeId->value
            : null;
        $vannforsynings_kode_id = isset($bygning->vannforsyningsKodeId) && isset($bygning->vannforsyningsKodeId->value)
            ? $bygning->vannforsyningsKodeId->value
            : null;
        $naringsgruppe_kode_id = isset($bygning->naringsgruppeKodeId) && isset($bygning->naringsgruppeKodeId->value)
            ? $bygning->naringsgruppeKodeId->value
            : null;
        $opprinnelses_kode_id = isset($bygning->opprinnelsesKodeId) && isset($bygning->opprinnelsesKodeId->value)
            ? $bygning->opprinnelsesKodeId->value
            : null;
        
        // Arrays of kode IDs - convert to PostgreSQL array format
        $oppvarmings_kode_ids = null;
        if (isset($bygning->oppvarmingsKodeIds) && isset($bygning->oppvarmingsKodeIds->item)) {
            $items = is_array($bygning->oppvarmingsKodeIds->item) 
                ? $bygning->oppvarmingsKodeIds->item 
                : [$bygning->oppvarmingsKodeIds->item];
            $values = array_map(fn($item) => $item->value, $items);
            $oppvarmings_kode_ids = '{' . implode(',', $values) . '}';
        }
        
        $energikilde_kode_ids = null;
        if (isset($bygning->energikildeKodeIds) && isset($bygning->energikildeKodeIds->item)) {
            $items = is_array($bygning->energikildeKodeIds->item) 
                ? $bygning->energikildeKodeIds->item 
                : [$bygning->energikildeKodeIds->item];
            $values = array_map(fn($item) => $item->value, $items);
            $energikilde_kode_ids = '{' . implode(',', $values) . '}';
        }
        
        // Representasjonspunkt (coordinates)
        $representasjonspunkt_x = null;
        $representasjonspunkt_y = null;
        $representasjonspunkt_z = null;
        $koordinatsystem = null;
        
        if (isset($bygning->representasjonspunkt)) {
            $punkt = $bygning->representasjonspunkt;
            
            // Coordinates are in position object
            if (isset($punkt->position)) {
                $representasjonspunkt_x = isset($punkt->position->x) ? (float) $punkt->position->x : null;
                $representasjonspunkt_y = isset($punkt->position->y) ? (float) $punkt->position->y : null;
                $representasjonspunkt_z = isset($punkt->position->z) ? (float) $punkt->position->z : null;
            }
            
            // Coordinate system is in koordinatsystemKodeId
            if (isset($punkt->koordinatsystemKodeId) && isset($punkt->koordinatsystemKodeId->value)) {
                $koordinatsystem = 'EPSG:' . $punkt->koordinatsystemKodeId->value;
            }
        }

        $sql = "
            INSERT INTO matrikkel_bygninger (
                bygning_id, matrikkel_bygning_nummer, lopenummer, uuid,
                bygningstype_kode_id, bygningsstatus_kode_id,
                bebygd_areal, bruksareal,
                uten_bebygd_areal, ufullstendig_areal,
                byggeaar, antall_etasjer,
                har_heis, har_sefrakminne, har_kulturminne, skjermingsverdig, nymatrikulert,
                avlops_kode_id, vannforsynings_kode_id,
                oppvarmings_kode_ids, energikilde_kode_ids,
                naringsgruppe_kode_id, opprinnelses_kode_id,
                representasjonspunkt_x, representasjonspunkt_y, representasjonspunkt_z, koordinatsystem,
                sist_lastet_ned, oppdatert
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
            ON CONFLICT (bygning_id) DO UPDATE SET
                matrikkel_bygning_nummer = EXCLUDED.matrikkel_bygning_nummer,
                lopenummer = EXCLUDED.lopenummer,
                uuid = EXCLUDED.uuid,
                bygningstype_kode_id = EXCLUDED.bygningstype_kode_id,
                bygningsstatus_kode_id = EXCLUDED.bygningsstatus_kode_id,
                bebygd_areal = EXCLUDED.bebygd_areal,
                bruksareal = EXCLUDED.bruksareal,
                uten_bebygd_areal = EXCLUDED.uten_bebygd_areal,
                ufullstendig_areal = EXCLUDED.ufullstendig_areal,
                byggeaar = EXCLUDED.byggeaar,
                antall_etasjer = EXCLUDED.antall_etasjer,
                har_heis = EXCLUDED.har_heis,
                har_sefrakminne = EXCLUDED.har_sefrakminne,
                har_kulturminne = EXCLUDED.har_kulturminne,
                skjermingsverdig = EXCLUDED.skjermingsverdig,
                nymatrikulert = EXCLUDED.nymatrikulert,
                avlops_kode_id = EXCLUDED.avlops_kode_id,
                vannforsynings_kode_id = EXCLUDED.vannforsynings_kode_id,
                oppvarmings_kode_ids = EXCLUDED.oppvarmings_kode_ids,
                energikilde_kode_ids = EXCLUDED.energikilde_kode_ids,
                naringsgruppe_kode_id = EXCLUDED.naringsgruppe_kode_id,
                opprinnelses_kode_id = EXCLUDED.opprinnelses_kode_id,
                representasjonspunkt_x = EXCLUDED.representasjonspunkt_x,
                representasjonspunkt_y = EXCLUDED.representasjonspunkt_y,
                representasjonspunkt_z = EXCLUDED.representasjonspunkt_z,
                koordinatsystem = EXCLUDED.koordinatsystem,
                sist_lastet_ned = NOW(),
                oppdatert = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $bygningId, $bygningsnummer, $lopenummer, $uuid,
            $bygningstype_kode_id, $bygningsstatus_kode_id,
            $bebygd_areal, $bruksareal,
            $uten_bebygd_areal ? 't' : 'f',
            $ufullstendig_areal ? 't' : 'f',
            $byggeaar, $antall_etasjer,
            $har_heis ? 't' : 'f',
            $har_sefrakminne ? 't' : 'f',
            $har_kulturminne ? 't' : 'f',
            $skjermingsverdig ? 't' : 'f',
            $nymatrikulert ? 't' : 'f',
            $avlops_kode_id, $vannforsynings_kode_id,
            $oppvarmings_kode_ids, $energikilde_kode_ids,
            $naringsgruppe_kode_id, $opprinnelses_kode_id,
            $representasjonspunkt_x, $representasjonspunkt_y, $representasjonspunkt_z, $koordinatsystem,
        ]);
    }

    private function saveBygningMatrikkelenhetRelation(int $bygningId, int $matrikkelenhetId): void
    {
        $sql = "
            INSERT INTO matrikkel_bygning_matrikkelenhet (
                bygning_id,
                matrikkelenhet_id
            ) VALUES (?, ?)
            ON CONFLICT (bygning_id, matrikkelenhet_id) DO NOTHING
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$bygningId, $matrikkelenhetId]);
    }
}
