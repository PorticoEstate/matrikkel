<?php

namespace Iaasen\Matrikkel\LocalDb;

/**
 * Repository for querying matrikkel_personer table
 * Handles both fysiske and juridiske personer
 */
class PersonRepository extends DatabaseRepository
{
    /**
     * Find person by matrikkel_person_id
     */
    public function findById(int $matrikkelPersonId): ?array
    {
        $sql = "
            SELECT 
                p.*,
                fp.fodselsnummer,
                fp.etternavn,
                fp.fornavn,
                fp.person_status_kode_id,
                fp.bostedsadresse_kommunenummer,
                fp.bostedsadresse_postnummer,
                jp.organisasjonsnummer,
                jp.organisasjonsform_kode,
                jp.slettet_dato,
                jp.forretningsadresse_adresselinje1,
                jp.forretningsadresse_postnummer,
                jp.forretningsadresse_poststed,
                CASE 
                    WHEN fp.id IS NOT NULL THEN 'FYSISK'
                    WHEN jp.id IS NOT NULL THEN 'JURIDISK'
                    ELSE 'UNKNOWN'
                END as person_type
            FROM matrikkel_personer p
            LEFT JOIN matrikkel_fysiske_personer fp ON p.id = fp.id
            LEFT JOIN matrikkel_juridiske_personer jp ON p.id = jp.id
            WHERE p.matrikkel_person_id = :matrikkel_person_id
        ";

        return $this->fetchOne($sql, ['matrikkel_person_id' => $matrikkelPersonId]);
    }

    /**
     * Find fysisk person by fødselsnummer
     */
    public function findByFodselsnummer(string $fodselsnummer): ?array
    {
        $sql = "
            SELECT 
                p.*,
                fp.*
            FROM matrikkel_personer p
            INNER JOIN matrikkel_fysiske_personer fp ON p.id = fp.id
            WHERE fp.fodselsnummer = :fodselsnummer
        ";

        return $this->fetchOne($sql, ['fodselsnummer' => $fodselsnummer]);
    }

    /**
     * Find juridisk person by organisasjonsnummer
     */
    public function findByOrganisasjonsnummer(string $organisasjonsnummer): ?array
    {
        $sql = "
            SELECT 
                p.*,
                jp.*
            FROM matrikkel_personer p
            INNER JOIN matrikkel_juridiske_personer jp ON p.id = jp.id
            WHERE jp.organisasjonsnummer = :organisasjonsnummer
        ";

        return $this->fetchOne($sql, ['organisasjonsnummer' => $organisasjonsnummer]);
    }

    /**
     * Search personer by name
     */
    public function searchByName(string $name, int $limit = 100): array
    {
        $sql = "
            SELECT 
                p.*,
                fp.etternavn,
                fp.fornavn,
                jp.organisasjonsnummer,
                CASE 
                    WHEN fp.id IS NOT NULL THEN 'FYSISK'
                    WHEN jp.id IS NOT NULL THEN 'JURIDISK'
                    ELSE 'UNKNOWN'
                END as person_type
            FROM matrikkel_personer p
            LEFT JOIN matrikkel_fysiske_personer fp ON p.id = fp.id
            LEFT JOIN matrikkel_juridiske_personer jp ON p.id = jp.id
            WHERE p.navn ILIKE :name
            ORDER BY p.navn
            LIMIT :limit
        ";

        return $this->fetchAll($sql, [
            'name' => '%' . $name . '%',
            'limit' => $limit
        ]);
    }

    /**
     * Find personer who own a specific matrikkelenhet
     */
    public function findByMatrikkelenhetId(int $matrikkelenhetId): array
    {
        $sql = "
            SELECT 
                p.*,
                fp.etternavn,
                fp.fornavn,
                jp.organisasjonsnummer,
                e.andel_teller,
                e.andel_nevner,
                e.dato_fra,
                e.tinglyst,
                CASE 
                    WHEN fp.id IS NOT NULL THEN 'FYSISK'
                    WHEN jp.id IS NOT NULL THEN 'JURIDISK'
                    ELSE 'UNKNOWN'
                END as person_type
            FROM matrikkel_eierforhold e
            INNER JOIN matrikkel_personer p ON 
                e.fysisk_person_id = p.id OR e.juridisk_person_entity_id = p.id
            LEFT JOIN matrikkel_fysiske_personer fp ON p.id = fp.id
            LEFT JOIN matrikkel_juridiske_personer jp ON p.id = jp.id
            WHERE e.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY e.andel_teller DESC
        ";

        return $this->fetchAll($sql, ['matrikkelenhet_id' => $matrikkelenhetId]);
    }

    /**
     * Count total personer
     */
    public function countAll(): int
    {
        return $this->fetchCount("SELECT COUNT(*) FROM matrikkel_personer");
    }

    /**
     * Count by person type
     */
    public function countByType(): array
    {
        $sql = "
            SELECT 
                COUNT(fp.id) as fysiske_personer,
                COUNT(jp.id) as juridiske_personer
            FROM matrikkel_personer p
            LEFT JOIN matrikkel_fysiske_personer fp ON p.id = fp.id
            LEFT JOIN matrikkel_juridiske_personer jp ON p.id = jp.id
        ";

        return $this->fetchOne($sql) ?? ['fysiske_personer' => 0, 'juridiske_personer' => 0];
    }
}
