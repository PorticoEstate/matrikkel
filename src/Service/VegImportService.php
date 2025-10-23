<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\NedlastningClient;

/**
 * Import veger using cursor-based pagination via NedlastningService
 * 
 * CRITICAL: Veger MUST be imported BEFORE adresser!
 * Vegadresser have foreign key constraint to matrikkel_veger.
 */
class VegImportService
{
    private const BATCH_SIZE = 500;
    private const DOMAINKLASSE = 'Veg';

    public function __construct(
        private NedlastningClient $nedlastningClient,
        private \PDO $db
    ) {}

    /**
     * Import all veger for a kommune using cursor-based pagination
     *
     * @param int $kommunenummer Kommune number (e.g., 4601 for Bergen)
     * @return int Number of veger imported
     */
    public function importVegerForKommune(int $kommunenummer): int
    {
        // Use simple string filter format as shown in documentation
        $filter = '{"kommunefilter": ["' . $kommunenummer . '"]}';
        $cursor = null; // Start with null for first batch (as per documentation)
        $totalCount = 0;

        do {
            // Fetch batch with cursor-based pagination using ClassMap method
            // (same as MatrikkelenhetImportService - proven to work)
            $batch = $this->nedlastningClient->findObjekterEtterIdWithClassMap(
                $cursor,
                self::DOMAINKLASSE,
                $filter,
                self::BATCH_SIZE
            );

            if (empty($batch)) {
                break;
            }

            // Save batch to database
            foreach ($batch as $item) {
                // item has: id (VegId object), soapObject (stdClass with veg data)
                $this->saveVeg($item->soapObject);
                $totalCount++;
            }
            
            // Update cursor to last object's ID (entire object, not just value!)
            $lastObject = end($batch);
            $cursor = $lastObject->id;

            // If we got fewer objects than batch size, this is the last batch
            if (count($batch) < self::BATCH_SIZE) {
                break;
            }

        } while (true);

        return $totalCount;
    }

    private function saveVeg(object $veg): void
    {
        $vegId = (int) $veg->id->value;
        
        // Kommune ID - might be object or integer
        $kommuneId = isset($veg->kommuneId) 
            ? (is_object($veg->kommuneId) ? (int) $veg->kommuneId->value : (int) $veg->kommuneId)
            : null;
        
        // Adressekode (required)
        $adressekode = isset($veg->adressekode) ? (int) $veg->adressekode : null;
        
        // Adressenavn (required)
        $adressenavn = isset($veg->adressenavn) ? $veg->adressenavn : null;
        
        // Kort adressenavn (optional)
        $kort_adressenavn = isset($veg->kortAdressenavn) ? $veg->kortAdressenavn : null;
        
        // Stedsnummer (optional)
        $stedsnummer = isset($veg->stedsnummer) ? $veg->stedsnummer : null;
        
        // UUID (optional, might be object)
        $uuid = null;
        if (isset($veg->uuid)) {
            if (is_object($veg->uuid)) {
                $uuid = isset($veg->uuid->value) ? (string) $veg->uuid->value : null;
            } else {
                $uuid = (string) $veg->uuid;
            }
        }

        $sql = "
            INSERT INTO matrikkel_veger (
                veg_id,
                kommune_id,
                adressekode,
                adressenavn,
                kort_adressenavn,
                stedsnummer,
                uuid,
                sist_lastet_ned,
                oppdatert
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
            ON CONFLICT (veg_id) DO UPDATE SET
                kommune_id = EXCLUDED.kommune_id,
                adressekode = EXCLUDED.adressekode,
                adressenavn = EXCLUDED.adressenavn,
                kort_adressenavn = EXCLUDED.kort_adressenavn,
                stedsnummer = EXCLUDED.stedsnummer,
                uuid = EXCLUDED.uuid,
                sist_lastet_ned = NOW(),
                oppdatert = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $vegId,
            $kommuneId,
            $adressekode,
            $adressenavn,
            $kort_adressenavn,
            $stedsnummer,
            $uuid,
        ]);
    }
}
