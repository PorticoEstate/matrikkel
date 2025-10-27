<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_kommuner table
 */
class KommuneRepository extends DatabaseRepository
{
    /**
     * Find kommune by kommunenummer
     */
    public function findById(int $kommunenummer): ?array
    {
        $sql = "
            SELECT *
            FROM matrikkel_kommuner
            WHERE kommunenummer = :kommunenummer
        ";

        return $this->fetchOne($sql, ['kommunenummer' => $kommunenummer]);
    }

    /**
     * Find all kommuner
     */
    public function findAll(int $limit = 1000): array
    {
        $sql = "
            SELECT *
            FROM matrikkel_kommuner
            ORDER BY kommunenummer
            LIMIT :limit
        ";

        return $this->fetchAll($sql, ['limit' => $limit]);
    }

    /**
     * Find kommuner by fylkesnummer
     */
    public function findByFylkesnummer(int $fylkesnummer): array
    {
        $sql = "
            SELECT *
            FROM matrikkel_kommuner
            WHERE fylkesnummer = :fylkesnummer
            ORDER BY kommunenummer
        ";

        return $this->fetchAll($sql, ['fylkesnummer' => $fylkesnummer]);
    }

    /**
     * Search kommuner by name
     */
    public function searchByName(string $name, int $limit = 100): array
    {
        $sql = "
            SELECT *
            FROM matrikkel_kommuner
            WHERE kommunenavn ILIKE :name
            ORDER BY kommunenavn
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'name' => '%' . $name . '%',
            'limit' => $limit
        ]);
    }

    /**
     * Count total kommuner
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_kommuner");
    }

    /**
     * Get kommune with statistics about its matrikkelenheter
     */
    public function findWithStatistics(int $kommunenummer): ?array
    {
        $sql = "
            SELECT 
                k.*,
                COUNT(DISTINCT me.matrikkelenhet_id) as antall_matrikkelenheter,
                COUNT(DISTINCT b.bygning_id) as antall_bygninger,
                COUNT(DISTINCT br.bruksenhet_id) as antall_bruksenheter
            FROM matrikkel_kommuner k
            LEFT JOIN matrikkel_matrikkelenheter me ON k.kommunenummer = me.kommunenummer
            LEFT JOIN matrikkel_bygning_matrikkelenhet bm ON me.matrikkelenhet_id = bm.matrikkelenhet_id
            LEFT JOIN matrikkel_bygninger b ON bm.bygning_id = b.bygning_id
            LEFT JOIN matrikkel_bruksenheter br ON me.matrikkelenhet_id = br.matrikkelenhet_id
            WHERE k.kommunenummer = :kommunenummer
            GROUP BY k.kommune_id
        ";

        return $this->fetchOne($sql, ['kommunenummer' => $kommunenummer]);
    }
}
