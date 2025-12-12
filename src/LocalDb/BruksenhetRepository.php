<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_bruksenheter table
 */
class BruksenhetRepository extends DatabaseRepository
{
    /**
     * Find bruksenhet by ID
     */
    public function findById(int $bruksenhetId): ?array
    {
        $sql = "
            SELECT 
                br.*,
                b.matrikkel_bygning_nummer,
                b.bygningstype_kode_id,
                me.matrikkelnummer_tekst,
                me.kommunenummer
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_bygninger b ON br.bygning_id = b.bygning_id
            LEFT JOIN matrikkel_matrikkelenheter me ON br.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE br.bruksenhet_id = :bruksenhet_id
        ";

        return $this->fetchOne($sql, ['bruksenhet_id' => $bruksenhetId]);
    }

    /**
     * Find bruksenheter by bygning ID
     */
    public function findByBygningId(int $bygningId): array
    {
        $sql = "
            SELECT 
                br.*,
                me.matrikkelnummer_tekst
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_matrikkelenheter me ON br.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE br.bygning_id = :bygning_id
            ORDER BY br.etasjenummer, br.lopenummer
        ";

        return $this->fetchAll($sql, ['bygning_id' => $bygningId]);
    }

    /**
     * Find bruksenheter by bygning ID including address info (for entrance grouping)
     */
    public function findByBygningIdWithAdresse(int $bygningId): array
    {
        $sql = "
            SELECT 
                br.*, 
                va.veg_id,
                va.nummer AS husnummer,
                va.bokstav,
                v.adressenavn,
                v.kort_adressenavn
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_adresser a ON br.adresse_id = a.adresse_id
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            WHERE br.bygning_id = :bygning_id
            ORDER BY br.etasjenummer NULLS FIRST, br.lopenummer, br.bruksenhet_id
        ";

        return $this->fetchAll($sql, ['bygning_id' => $bygningId]);
    }

    /**
     * Find bruksenheter by matrikkelenhet ID
     */
    public function findByMatrikkelenhetId(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT 
                br.*,
                b.matrikkel_bygning_nummer,
                b.bygningstype_kode_id
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_bygninger b ON br.bygning_id = b.bygning_id
            WHERE br.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY b.matrikkel_bygning_nummer, br.etasjenummer, br.lopenummer
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Find bruksenheter by adresse ID
     */
    public function findByAdresseId(int $adresseId): array
    {
        $sql = "
            SELECT 
                br.*,
                b.matrikkel_bygning_nummer,
                me.matrikkelnummer_tekst
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_bygninger b ON br.bygning_id = b.bygning_id
            LEFT JOIN matrikkel_matrikkelenheter me ON br.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE br.adresse_id = :adresse_id
            ORDER BY br.etasjenummer, br.lopenummer
        ";

        return $this->fetchAll($sql, ['adresse_id' => $adresseId]);
    }

    /**
     * Search bruksenheter by criteria
     */
    public function search(array $criteria, int $limit = 100): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($criteria['bruksenhettype_kode_id'])) {
            $whereClauses[] = 'br.bruksenhettype_kode_id = :bruksenhettype_kode_id';
            $params['bruksenhettype_kode_id'] = $criteria['bruksenhettype_kode_id'];
        }

        if (!empty($criteria['matrikkelenhet_id'])) {
            $whereClauses[] = 'br.matrikkelenhet_id = :matrikkelenhet_id';
            $params['matrikkelenhet_id'] = $criteria['matrikkelenhet_id'];
        }

        if (!empty($criteria['bygning_id'])) {
            $whereClauses[] = 'br.bygning_id = :bygning_id';
            $params['bygning_id'] = $criteria['bygning_id'];
        }

        if (!empty($criteria['min_antall_rom'])) {
            $whereClauses[] = 'br.antall_rom >= :min_antall_rom';
            $params['min_antall_rom'] = $criteria['min_antall_rom'];
        }

        $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT 
                br.*,
                b.matrikkel_bygning_nummer,
                me.matrikkelnummer_tekst
            FROM matrikkel_bruksenheter br
            LEFT JOIN matrikkel_bygninger b ON br.bygning_id = b.bygning_id
            LEFT JOIN matrikkel_matrikkelenheter me ON br.matrikkelenhet_id = me.matrikkelenhet_id
            {$whereClause}
            ORDER BY me.matrikkelnummer_tekst, b.matrikkel_bygning_nummer, br.lopenummer
            LIMIT :limit
        ";

        $params['limit'] = $limit;

        return $this->fetchAll($sql, $params);
    }

    /**
     * Fetch bruksenheter for en inngang, sortert for stabil løpenummerering
     */
    public function findByInngangId(int $inngangId): array
    {
        $sql = "
            SELECT br.*
            FROM matrikkel_bruksenheter br
            WHERE br.inngang_id = :inngang_id
            ORDER BY br.etasjenummer NULLS FIRST, br.lopenummer, br.bruksenhet_id
        ";

        return $this->fetchAll($sql, ['inngang_id' => $inngangId]);
    }

    /**
     * Update løpenummer innen inngang
     */
    public function updateLopenummerIInngang(int $bruksenhetId, int $lopenummer): void
    {
        $sql = "
            UPDATE matrikkel_bruksenheter
            SET lopenummer_i_inngang = :lopenummer
            WHERE bruksenhet_id = :bruksenhet_id
        ";

        $this->execute($sql, [
            'bruksenhet_id' => $bruksenhetId,
            'lopenummer' => $lopenummer,
        ]);
    }

    /**
     * Set inngang_id reference
     */
    public function updateInngangReference(int $bruksenhetId, ?int $inngangId): void
    {
        $sql = "
            UPDATE matrikkel_bruksenheter
            SET inngang_id = :inngang_id
            WHERE bruksenhet_id = :bruksenhet_id
        ";

        $this->execute($sql, [
            'bruksenhet_id' => $bruksenhetId,
            'inngang_id' => $inngangId,
        ]);
    }

    /**
     * Update lokasjonskode for bruksenhet
     */
    public function updateLokasjonskode(int $bruksenhetId, string $lokasjonskode): void
    {
        $sql = "
            UPDATE matrikkel_bruksenheter
            SET lokasjonskode_bruksenhet = :lokasjonskode
            WHERE bruksenhet_id = :bruksenhet_id
        ";

        $this->execute($sql, [
            'bruksenhet_id' => $bruksenhetId,
            'lokasjonskode' => $lokasjonskode,
        ]);
    }

    /**
     * Count total bruksenheter
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_bruksenheter");
    }

    /**
     * Get bruksenheter statistics
     */
    public function getStatistics(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_count,
                COUNT(CASE WHEN bruksareal IS NOT NULL THEN 1 END) as with_bruksareal,
                AVG(bruksareal) as avg_bruksareal,
                AVG(antall_rom) as avg_antall_rom,
                COUNT(DISTINCT bruksenhettype_kode_id) as unique_types
            FROM matrikkel_bruksenheter
        ";

        return $this->fetchOne($sql) ?? [];
    }
}
