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
     */
    public function search(string $query, int $limit = 100): array
    {
        $searchPattern = '%' . $query . '%';

        $sql = "
            SELECT 
                a.adresse_id,
                a.adressetype,
                a.matrikkelenhet_id,
                a.adressetilleggsnavn,
                a.kortnavn,
                -- VEGADRESSE fields
                va.nummer,
                va.bokstav,
                v.adressenavn,
                v.kort_adressenavn,
                -- MATRIKKELENHET fields
                me.matrikkelnummer_tekst,
                me.kommunenummer
            FROM matrikkel_adresser a
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            LEFT JOIN matrikkel_matrikkelenheter me ON a.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE 
                v.adressenavn ILIKE :query
                OR me.matrikkelnummer_tekst ILIKE :query
                OR a.kortnavn ILIKE :query
            ORDER BY 
                CASE 
                    WHEN a.adressetype = 'VEGADRESSE' THEN v.adressenavn
                    ELSE me.matrikkelnummer_tekst
                END
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'query' => $searchPattern,
            'limit' => $limit
        ]);
    }

    /**
     * Find all addresses in a kommune
     */
    public function findByKommunenummer(int $kommunenummer, int $limit = 1000): array
    {
        $sql = "
            SELECT 
                a.adresse_id,
                a.adressetype,
                a.matrikkelenhet_id,
                va.nummer,
                va.bokstav,
                v.adressenavn,
                me.matrikkelnummer_tekst
            FROM matrikkel_adresser a
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            LEFT JOIN matrikkel_matrikkelenheter me ON a.matrikkelenhet_id = me.matrikkelenhet_id
            WHERE 
                me.kommunenummer = :kommunenummer
            ORDER BY 
                CASE 
                    WHEN a.adressetype = 'VEGADRESSE' THEN v.adressenavn
                    ELSE me.matrikkelnummer_tekst
                END,
                va.nummer,
                va.bokstav
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'kommunenummer' => $kommunenummer,
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
