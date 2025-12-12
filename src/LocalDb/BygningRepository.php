<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_bygninger table
 */
class BygningRepository extends DatabaseRepository
{
    /**
     * Find bygning by ID
     */
    public function findById(int $bygningId): ?array
    {
        $sql = "
            SELECT 
                b.*,
                GROUP_CONCAT(DISTINCT bm.matrikkelenhet_id) as matrikkelenhet_ids
            FROM matrikkel_bygninger b
            LEFT JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
            WHERE b.bygning_id = :bygning_id
            GROUP BY b.bygning_id
        ";

        return $this->fetchOne($sql, ['bygning_id' => $bygningId]);
    }

    /**
     * Find bygninger by matrikkelenhet ID
     */
    public function findByMatrikkelenhetId(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT 
                b.*
            FROM matrikkel_bygninger b
            INNER JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
            WHERE bm.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY b.byggeaar DESC, b.matrikkel_bygning_nummer
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Search bygninger by criteria
     */
    public function search(array $criteria, int $limit = 100): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($criteria['bygningstype_kode_id'])) {
            $whereClauses[] = 'b.bygningstype_kode_id = :bygningstype_kode_id';
            $params['bygningstype_kode_id'] = $criteria['bygningstype_kode_id'];
        }

        if (!empty($criteria['bygningsstatus_kode_id'])) {
            $whereClauses[] = 'b.bygningsstatus_kode_id = :bygningsstatus_kode_id';
            $params['bygningsstatus_kode_id'] = $criteria['bygningsstatus_kode_id'];
        }

        if (!empty($criteria['min_byggeaar'])) {
            $whereClauses[] = 'b.byggeaar >= :min_byggeaar';
            $params['min_byggeaar'] = $criteria['min_byggeaar'];
        }

        if (!empty($criteria['max_byggeaar'])) {
            $whereClauses[] = 'b.byggeaar <= :max_byggeaar';
            $params['max_byggeaar'] = $criteria['max_byggeaar'];
        }

        if (!empty($criteria['matrikkelenhet_id'])) {
            $whereClauses[] = 'bm.matrikkelenhet_id = :matrikkelenhet_id';
            $params['matrikkelenhet_id'] = $criteria['matrikkelenhet_id'];
        }

        $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT 
                b.*,
                GROUP_CONCAT(DISTINCT bm.matrikkelenhet_id) as matrikkelenhet_ids
            FROM matrikkel_bygninger b
            LEFT JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
            {$whereClause}
            GROUP BY b.bygning_id
            ORDER BY b.byggeaar DESC, b.matrikkel_bygning_nummer
            LIMIT :limit
        ";

        $params['limit'] = $limit;

        return $this->fetchAll($sql, $params);
    }

    /**
     * Count total bygninger
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_bygninger");
    }

    /**
     * Get bygninger for a matrikkelenhet ordered by bygning_id (for stable numbering)
     */
    public function getBygningerForEiendom(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT b.*
            FROM matrikkel_bygninger b
            INNER JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
            WHERE b.bygningsstatus_kode_id != 9 AND bm.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY b.bygning_id ASC
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Update løpenummer innen eiendom
     */
    public function updateLopenummerIEiendom(int $bygningId, int $lopenummer): void
    {
        $sql = "
            UPDATE matrikkel_bygninger
            SET lopenummer_i_eiendom = :lopenummer
            WHERE bygning_id = :bygning_id
        ";

        $this->execute($sql, [
            'bygning_id' => $bygningId,
            'lopenummer' => $lopenummer,
        ]);
    }

    /**
     * Update lokasjonskode for bygg
     */
    public function updateLokasjonskode(int $bygningId, string $lokasjonskode): void
    {
        $sql = "
            UPDATE matrikkel_bygninger
            SET lokasjonskode_bygg = :lokasjonskode
            WHERE bygning_id = :bygning_id
        ";

        $this->execute($sql, [
            'bygning_id' => $bygningId,
            'lokasjonskode' => $lokasjonskode,
        ]);
    }

    /**
     * Get bygninger with statistics
     */
    public function getStatistics(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_count,
                COUNT(CASE WHEN byggeaar IS NOT NULL THEN 1 END) as with_byggeaar,
                AVG(byggeaar) as avg_byggeaar,
                AVG(bruksareal) as avg_bruksareal,
                COUNT(CASE WHEN antall_etasjer IS NOT NULL THEN 1 END) as with_etasjer
            FROM matrikkel_bygninger
        ";

        return $this->fetchOne($sql) ?? [];
    }
}
