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
            SELECT *
            FROM matrikkel_innganger
            WHERE bygning_id = :bygning_id
            ORDER BY lopenummer_i_bygg, husnummer, bokstav
        ";

        return $this->fetchAll($sql, ['bygning_id' => $bygningId]);
    }

    /**
    * Find existing or create new entrance for a building/address combination
    */
    public function findOrCreate(int $bygningId, ?int $vegId, int $husnummer, ?string $bokstav): array
    {
        $existing = $this->fetchOne(
            "SELECT * FROM matrikkel_innganger WHERE bygning_id = :bygning_id AND veg_id IS NOT DISTINCT FROM :veg_id AND husnummer = :husnummer AND bokstav IS NOT DISTINCT FROM :bokstav",
            [
                'bygning_id' => $bygningId,
                'veg_id' => $vegId,
                'husnummer' => $husnummer,
                'bokstav' => $bokstav,
            ]
        );

        if ($existing) {
            return $existing;
        }

        $this->execute(
            "INSERT INTO matrikkel_innganger (bygning_id, veg_id, husnummer, bokstav, lopenummer_i_bygg, lokasjonskode_inngang)
             VALUES (:bygning_id, :veg_id, :husnummer, :bokstav, 0, '')",
            [
                'bygning_id' => $bygningId,
                'veg_id' => $vegId,
                'husnummer' => $husnummer,
                'bokstav' => $bokstav,
            ]
        );

        return $this->fetchOne(
            "SELECT * FROM matrikkel_innganger WHERE bygning_id = :bygning_id AND veg_id IS NOT DISTINCT FROM :veg_id AND husnummer = :husnummer AND bokstav IS NOT DISTINCT FROM :bokstav",
            [
                'bygning_id' => $bygningId,
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
