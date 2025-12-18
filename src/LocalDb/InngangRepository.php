<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for matrikkel_innganger (entrances)
 */
class InngangRepository extends DatabaseRepository
{
    public function findById(int $inngangId): ?array
    {
        $sql = "
            SELECT *
            FROM matrikkel_innganger
            WHERE inngang_id = :inngang_id
        ";

        return $this->fetchOne($sql, ['inngang_id' => $inngangId]);
    }

    public function findByBygningId(int $bygningId): array
    {
        $sql = "
            SELECT matrikkel_innganger.*, matrikkel_veger.adressenavn as gatenavn
            FROM matrikkel_innganger
            LEFT JOIN matrikkel_veger ON matrikkel_innganger.veg_id = matrikkel_veger.veg_id
            WHERE bygning_id = :bygning_id
            ORDER BY lopenummer_i_bygg, husnummer, bokstav
        ";

        return $this->fetchAll($sql, ['bygning_id' => $bygningId]);
    }

    /**
     * Find existing or create new entrance for a building/address combination
     */
    public function findOrCreate(
        int $bygningId,
        ?int $matrikkelBygningNummer,
        ?int $kommunenummer,
        ?int $vegId,
        int $husnummer,
        ?string $bokstav,
        ?int $adressekode = null
    ): array {
        $existing = null;
        if ($matrikkelBygningNummer !== null && $kommunenummer !== null) {
            $existing = $this->fetchOne(
                "SELECT * FROM matrikkel_innganger WHERE matrikkel_bygning_nummer = :matrikkel_bygning_nummer AND kommunenummer = :kommunenummer AND veg_id IS NOT DISTINCT FROM :veg_id AND husnummer = :husnummer AND bokstav IS NOT DISTINCT FROM :bokstav",
                [
                    'matrikkel_bygning_nummer' => $matrikkelBygningNummer,
                    'kommunenummer' => $kommunenummer,
                    'veg_id' => $vegId,
                    'husnummer' => $husnummer,
                    'bokstav' => $bokstav,
                ]
            );
        }

        if (!$existing) {
            $existing = $this->fetchOne(
                "SELECT * FROM matrikkel_innganger WHERE bygning_id = :bygning_id AND veg_id IS NOT DISTINCT FROM :veg_id AND husnummer = :husnummer AND bokstav IS NOT DISTINCT FROM :bokstav",
                [
                    'bygning_id' => $bygningId,
                    'veg_id' => $vegId,
                    'husnummer' => $husnummer,
                    'bokstav' => $bokstav,
                ]
            );
        }

        if ($existing) {
            if ($adressekode !== null && $existing['adressekode'] !== $adressekode) {
                $this->execute(
                    "UPDATE matrikkel_innganger SET adressekode = :adressekode WHERE inngang_id = :inngang_id",
                    [
                        'adressekode' => $adressekode,
                        'inngang_id' => $existing['inngang_id'],
                    ]
                );
                $existing['adressekode'] = $adressekode;
            }

            if ((($existing['matrikkel_bygning_nummer'] ?? null) === null || ($existing['kommunenummer'] ?? null) === null) && $matrikkelBygningNummer !== null && $kommunenummer !== null) {
                $this->execute(
                    "UPDATE matrikkel_innganger SET matrikkel_bygning_nummer = :matrikkel_bygning_nummer, kommunenummer = :kommunenummer WHERE inngang_id = :inngang_id",
                    [
                        'matrikkel_bygning_nummer' => $matrikkelBygningNummer,
                        'kommunenummer' => $kommunenummer,
                        'inngang_id' => $existing['inngang_id'],
                    ]
                );
                $existing['matrikkel_bygning_nummer'] = $matrikkelBygningNummer;
                $existing['kommunenummer'] = $kommunenummer;
            }

            return $existing;
        }

        $this->execute(
            "INSERT INTO matrikkel_innganger (bygning_id, matrikkel_bygning_nummer, kommunenummer, veg_id, husnummer, bokstav, adressekode, lopenummer_i_bygg, lokasjonskode_inngang)
             VALUES (:bygning_id, :matrikkel_bygning_nummer, :kommunenummer, :veg_id, :husnummer, :bokstav, :adressekode, 0, '')",
            [
                'bygning_id' => $bygningId,
                'matrikkel_bygning_nummer' => $matrikkelBygningNummer,
                'kommunenummer' => $kommunenummer,
                'veg_id' => $vegId,
                'husnummer' => $husnummer,
                'bokstav' => $bokstav,
                'adressekode' => $adressekode,
            ]
        );

        return $this->fetchOne(
            "SELECT * FROM matrikkel_innganger WHERE matrikkel_bygning_nummer IS NOT DISTINCT FROM :matrikkel_bygning_nummer AND kommunenummer IS NOT DISTINCT FROM :kommunenummer AND veg_id IS NOT DISTINCT FROM :veg_id AND husnummer = :husnummer AND bokstav IS NOT DISTINCT FROM :bokstav",
            [
                'matrikkel_bygning_nummer' => $matrikkelBygningNummer,
                'kommunenummer' => $kommunenummer,
                'veg_id' => $vegId,
                'husnummer' => $husnummer,
                'bokstav' => $bokstav,
            ]
        );
    }

    public function updateLopenummer(int $inngangId, int $lopenummer): void
    {
        $this->execute(
            "UPDATE matrikkel_innganger SET lopenummer_i_bygg = :lopenummer WHERE inngang_id = :inngang_id",
            [
                'inngang_id' => $inngangId,
                'lopenummer' => $lopenummer,
            ]
        );
    }

    public function updateLokasjonskode(int $inngangId, string $lokasjonskode): void
    {
        $this->execute(
            "UPDATE matrikkel_innganger SET lokasjonskode_inngang = :lokasjonskode WHERE inngang_id = :inngang_id",
            [
                'inngang_id' => $inngangId,
                'lokasjonskode' => $lokasjonskode,
            ]
        );
    }
}
