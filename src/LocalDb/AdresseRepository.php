<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_adresser table
 * Handles both VEGADRESSE and MATRIKKELADRESSE types
 */
class AdresseRepository extends DatabaseRepository
{
    /**
     * Find address by ID
     */
    public function findById(int $adresseId): ?array
    {
        $sql = "
            SELECT 
                a.adresse_id,
                a.adressetype,
                a.matrikkelenhet_id,
                a.representasjonspunkt_x,
                a.representasjonspunkt_y,
                a.representasjonspunkt_z,
                a.koordinatsystem,
                a.adressetilleggsnavn,
                a.kortnavn,
                a.uuid,
                a.sist_lastet_ned,
                -- VEGADRESSE fields
                va.nummer,
                va.bokstav,
                v.adressekode,
                v.adressenavn,
                v.kort_adressenavn,
                v.stedsnummer,
                -- MATRIKKELENHET fields
                me.kommunenummer,
                me.gardsnummer,
                me.bruksnummer,
                me.festenummer,
                me.seksjonsnummer,
                me.matrikkelnummer_tekst
            FROM matrikkel_adresser a
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            LEFT JOIN matrikkel_matrikkelenheter me ON a.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE a.adresse_id = :adresse_id
        ";

        return $this->fetchOne($sql, ['adresse_id' => $adresseId]);
    }

    /**
     * Search addresses by text query (address name or matrikkel number)
     * Focuses on vegadresser with full details including matrikkelenhet relationships
     */
    public function search(string $query, int $limit = 100): array
    {
        $searchPattern = '%' . $query . '%';

        $sql = "
            SELECT 
                v.adressenavn AS gatenavn,
                va.nummer AS husnummer,
                va.bokstav,
                v.kort_adressenavn,
                a.adresse_id,
                a.adressetilleggsnavn,
                a.representasjonspunkt_x,
                a.representasjonspunkt_y,
                v.kommune_id,
                ma.matrikkelenhet_id,
                m.matrikkelnummer_tekst
            FROM 
                matrikkel_vegadresser va
                INNER JOIN matrikkel_adresser a ON va.vegadresse_id = a.adresse_id
                INNER JOIN matrikkel_veger v ON va.veg_id = v.veg_id
                LEFT JOIN matrikkel_matrikkelenhet_adresse ma ON a.adresse_id = ma.adresse_id
                LEFT JOIN matrikkel_matrikkelenheter m ON ma.matrikkelenhet_id = m.matrikkelenhet_id
            WHERE 
                a.adressetype = 'VEGADRESSE'
                AND va.nummer IS NOT NULL
                AND v.adressenavn ILIKE :query
            ORDER BY 
                v.adressenavn, 
                va.nummer, 
                va.bokstav NULLS FIRST
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'query' => $searchPattern,
            'limit' => $limit
        ]);
    }

    /**
     * Find all addresses in a kommune
     * Returns only the primary address for each building (by lowest husnummer)
     */
    public function findByKommunenummer(int $kommunenummer, int $limit = 1000): array
    {
        $sql = "
            SELECT DISTINCT b.matrikkel_bygning_nummer as bygningsnummer,
                a.adresse_id,
                v.adressenavn as gatenavn,
                va.nummer as husnummer,
                va.bokstav,
                b.lopenummer
            FROM matrikkel_bygninger b
            JOIN matrikkel_bruksenheter bu ON b.bygning_id = bu.bygning_id
            JOIN matrikkel_adresser a ON bu.adresse_id = a.adresse_id
            JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            
            WHERE 
                a.adressetype = 'VEGADRESSE'
                AND v.kommune_id = :kommunenummer
            ORDER BY 
                b.matrikkel_bygning_nummer,
                va.nummer,
                va.bokstav NULLS FIRST
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'kommunenummer' => $kommunenummer,
            'limit' => $limit
        ]);
    }

    /**
     * Find all addresses in a kommune filtered by bygningsnummer
     * Returns only the primary address for each building (by lowest husnummer)
     */
    public function findByKommunenummerAndBygningsnummer(int $kommunenummer, int $bygningsnummer, int $limit = 1000): array
    {
        $sql = "
            SELECT DISTINCT 
                a.adresse_id,
                v.adressenavn as gatenavn,
                va.nummer as husnummer,
                va.bokstav,
                b.matrikkel_bygning_nummer as bygningsnummer
            FROM matrikkel_bygninger b
            JOIN matrikkel_bruksenheter bu ON b.bygning_id = bu.bygning_id
            JOIN matrikkel_adresser a ON bu.adresse_id = a.adresse_id
            JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            
            WHERE 
                a.adressetype = 'VEGADRESSE'
                AND b.matrikkel_bygning_nummer = :bygningsnummer
                AND v.kommune_id = :kommunenummer
            ORDER BY 
 --               b.bygning_id,
                va.nummer,
                va.bokstav NULLS FIRST
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'kommunenummer' => $kommunenummer,
            'bygningsnummer' => $bygningsnummer,
            'limit' => $limit
        ]);
    }

    /**
     * Find addresses by matrikkelenhet ID
     */
    public function findByMatrikkelenhetId(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT 
                a.adresse_id,
                a.adressetype,
                a.adressetilleggsnavn,
                a.kortnavn,
                va.nummer,
                va.bokstav,
                v.adressenavn
            FROM matrikkel_adresser a
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            WHERE a.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY v.adressenavn, va.nummer, va.bokstav
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Count total addresses in database
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_adresser");
    }

    /**
     * Count addresses by type
     */
    public function countByType(string $type): int
    {
        return $this->fetchCount(
            "SELECT COUNT(*) FROM matrikkel_adresser WHERE adressetype = :type",
            ['type' => $type]
        );
    }
}
