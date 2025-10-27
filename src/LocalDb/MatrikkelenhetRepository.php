<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_matrikkelenheter table
 * Handles cadastral unit queries
 */
class MatrikkelenhetRepository extends DatabaseRepository
{
    /**
     * Find matrikkelenhet by ID
     */
    public function findById(int $matrikkelenhetId): ?array
    {
        $sql = "
            SELECT 
                me.*,
                k.kommunenavn,
                k.fylkesnummer,
                k.fylkesnavn
            FROM matrikkel_matrikkelenheter me
            LEFT JOIN matrikkel_kommuner k ON me.kommunenummer = k.kommunenummer
            WHERE me.matrikkelenhet_id = :matrikkelenhet_id
        ";

        return $this->fetchOne($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Find matrikkelenheter by kommune
     */
    public function findByKommunenummer(int $kommunenummer, int $limit = 1000): array
    {
        $sql = "
            SELECT 
                me.*,
                k.kommunenavn
            FROM matrikkel_matrikkelenheter me
            LEFT JOIN matrikkel_kommuner k ON me.kommunenummer = k.kommunenummer
            WHERE me.kommunenummer = :kommunenummer
            ORDER BY me.gardsnummer, me.bruksnummer, me.festenummer, me.seksjonsnummer
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'kommunenummer' => $kommunenummer,
            'limit' => $limit
        ]);
    }

    /**
     * Find matrikkelenhet by GNR/BNR/FNR/SNR
     */
    public function findByMatrikkelNummer(
        int $kommunenummer,
        int $gardsnummer,
        int $bruksnummer,
        int $festenummer = 0,
        int $seksjonsnummer = 0
    ): ?array {
        $sql = "
            SELECT 
                me.*,
                k.kommunenavn,
                k.fylkesnummer,
                k.fylkesnavn
            FROM matrikkel_matrikkelenheter me
            LEFT JOIN matrikkel_kommuner k ON me.kommunenummer = k.kommunenummer
            WHERE 
                me.kommunenummer = :kommunenummer
                AND me.gardsnummer = :gardsnummer
                AND me.bruksnummer = :bruksnummer
                AND me.festenummer = :festenummer
                AND me.seksjonsnummer = :seksjonsnummer
        ";

        return $this->fetchOne($sql, [
            'kommunenummer' => $kommunenummer,
            'gardsnummer' => $gardsnummer,
            'bruksnummer' => $bruksnummer,
            'festenummer' => $festenummer,
            'seksjonsnummer' => $seksjonsnummer
        ]);
    }

    /**
     * Search matrikkelenheter by various criteria
     */
    public function search(array $criteria, int $limit = 100): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($criteria['kommunenummer'])) {
            $whereClauses[] = 'me.kommunenummer = :kommunenummer';
            $params['kommunenummer'] = $criteria['kommunenummer'];
        }

        if (!empty($criteria['gardsnummer'])) {
            $whereClauses[] = 'me.gardsnummer = :gardsnummer';
            $params['gardsnummer'] = $criteria['gardsnummer'];
        }

        if (!empty($criteria['bruksnummer'])) {
            $whereClauses[] = 'me.bruksnummer = :bruksnummer';
            $params['bruksnummer'] = $criteria['bruksnummer'];
        }

        if (isset($criteria['tinglyst'])) {
            $whereClauses[] = 'me.tinglyst = :tinglyst';
            $params['tinglyst'] = $criteria['tinglyst'];
        }

        if (!empty($criteria['bruksnavn'])) {
            $whereClauses[] = 'me.bruksnavn ILIKE :bruksnavn';
            $params['bruksnavn'] = '%' . $criteria['bruksnavn'] . '%';
        }

        $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT 
                me.*,
                k.kommunenavn
            FROM matrikkel_matrikkelenheter me
            LEFT JOIN matrikkel_kommuner k ON me.kommunenummer = k.kommunenummer
            {$whereClause}
            ORDER BY me.kommunenummer, me.gardsnummer, me.bruksnummer
            LIMIT :limit
        ";

        $params['limit'] = $limit;

        return $this->fetchAll($sql, $params);
    }

    /**
     * Get matrikkelenheter with their eierforhold (ownership)
     */
    public function findWithEierforhold(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT 
                me.*,
                k.kommunenavn,
                e.id as eierforhold_id,
                e.andel_teller,
                e.andel_nevner,
                e.dato_fra,
                e.tinglyst as eierforhold_tinglyst,
                p.navn as eier_navn,
                p.nummer as eier_nummer
            FROM matrikkel_matrikkelenheter me
            LEFT JOIN matrikkel_kommuner k ON me.kommunenummer = k.kommunenummer
            LEFT JOIN matrikkel_eierforhold e ON me.matrikkelenhet_id = e.matrikkelenhet_id
            LEFT JOIN matrikkel_personer p ON e.fysisk_person_id = p.id OR e.juridisk_person_entity_id = p.id
            WHERE me.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY e.andel_teller DESC
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Count total matrikkelenheter
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_matrikkelenheter");
    }

    /**
     * Count matrikkelenheter by kommune
     */
    public function countByKommune(int $kommunenummer): int
    {
        return $this->fetchCount(
            "SELECT COUNT(*) FROM matrikkel_matrikkelenheter WHERE kommunenummer = :kommunenummer",
            ['kommunenummer' => $kommunenummer]
        );
    }
}
