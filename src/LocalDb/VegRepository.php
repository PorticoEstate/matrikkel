<?php

namespace Iaasen\Matrikkel\LocalDb;

class VegRepository extends DatabaseRepository
{
    /**
     * Find all streets in a municipality
     */
    public function findByKommunenummer(int $kommunenummer): array
    {
        $sql = "
            SELECT 
                v.veg_id,
                v.kommune_id as kommunenummer,
                v.adressekode,
                v.adressenavn,
                v.kort_adressenavn,
                v.stedsnummer,
                v.uuid
            FROM matrikkel_veger v
            WHERE v.kommune_id = :kommunenummer
            ORDER BY v.adressenavn
        ";

        return $this->fetchAll($sql, ['kommunenummer' => $kommunenummer]);
    }

    /**
     * Find a specific street by veg_id
     */
    public function findById(int $vegId): ?array
    {
        $sql = "
            SELECT 
                v.veg_id,
                v.kommune_id as kommunenummer,
                v.adressekode,
                v.adressenavn,
                v.kort_adressenavn,
                v.stedsnummer,
                v.uuid
            FROM matrikkel_veger v
            WHERE v.veg_id = :veg_id
        ";

        return $this->fetchOne($sql, ['veg_id' => $vegId]);
    }

    /**
     * Find a specific street by municipality and street code
     */
    public function findByKommunenummerAndAdressekode(int $kommunenummer, int $adressekode): ?array
    {
        $sql = "
            SELECT 
                v.veg_id,
                v.kommune_id as kommunenummer,
                v.adressekode,
                v.adressenavn,
                v.kort_adressenavn,
                v.stedsnummer,
                v.uuid
            FROM matrikkel_veger v
            WHERE v.kommune_id = :kommunenummer
            AND v.adressekode = :adressekode
        ";

        return $this->fetchOne($sql, [
            'kommunenummer' => $kommunenummer,
            'adressekode' => $adressekode
        ]);
    }
}
