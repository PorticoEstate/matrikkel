<?php
/**
 * PorticoExportService - Build hierarchical JSON export for Portico
 * 
 * Constructs a 4-level nested JSON structure from organized hierarchy:
 * {
 *   "eiendommer": [
 *     {
 *       "lokasjonskode": "5000",
 *       "matrikkelenhet_id": 12345,
 *       "bygg": [
 *         {
 *           "lokasjonskode": "5000-01",
 *           "bygning_id": 67890,
 *           "innganger": [
 *             {
 *               "lokasjonskode": "5000-01-01",
 *               "inngang_id": 99999,
 *               "husnummer": 10,
 *               "bokstav": "A",
 *               "bruksenheter": [
 *                 {
 *                   "lokasjonskode": "5000-01-01-001",
 *                   "bruksenhet_id": 54321,
 *                   "etasjenummer": 1,
 *                   "lopenummer_i_inngang": 1
 *                 }
 *               ]
 *             }
 *           ]
 *         }
 *       ]
 *     }
 *   ]
 * }
 * 
 * @author Sigurd Nes
 * @date 2025-10-28
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\LocalDb\BruksenhetRepository;
use Iaasen\Matrikkel\LocalDb\BygningRepository;
use Iaasen\Matrikkel\LocalDb\InngangRepository;
use Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository;

class PorticoExportService
{
    public function __construct(
        private MatrikkelenhetRepository $matrikkelenhetRepository,
        private BygningRepository $bygningRepository,
        private InngangRepository $inngangRepository,
        private BruksenhetRepository $bruksenhetRepository,
    ) {}

    /**
     * Export hierarchy as nested JSON structure
     * 
     * @param int $kommune (optional filter)
     * @param string|null $organisasjonsnummer (optional filter on owner)
     * @return array
     */
    public function export(int $kommune = null, string $organisasjonsnummer = null): array
    {
        // Fetch matrikkelenheter (with optional filters)
        $sql = 'SELECT DISTINCT me.* FROM matrikkel_matrikkelenheter me';
        $params = [];

        // Add kommune filter
        if ($kommune) {
            $sql .= ' WHERE me.kommunenummer = ?';
            $params[] = $kommune;
        }

        // Add organisasjonsnummer filter (via eierforhold join)
        if ($organisasjonsnummer) {
            $sql .= (strpos($sql, 'WHERE') ? ' AND' : ' WHERE') . ' EXISTS (
                SELECT 1 FROM matrikkel_eierforhold eo
                JOIN matrikkel_juridiske_personer jp ON eo.juridisk_person_entity_id = jp.id
                WHERE eo.matrikkelenhet_id = me.matrikkelenhet_id
                AND jp.organisasjonsnummer = ?
            )';
            $params[] = $organisasjonsnummer;
        }

        $sql .= ' ORDER BY me.matrikkelenhet_id ASC';
        
        $matrikkelenheter = $this->matrikkelenhetRepository->fetchAll($sql, $params);

        // Build hierarchy
        $eiendommer = [];
        foreach ($matrikkelenheter as $matr) {
            $eiendommer[] = $this->buildEiendomHierarchy($matr);
        }

        return [
            'eiendommer' => $eiendommer,
            'count' => count($eiendommer),
        ];
    }

    /**
     * Build single eiendom (property) with full hierarchy
     */
    private function buildEiendomHierarchy(array $matr): array
    {
        $matrikkelId = (int)$matr['matrikkelenhet_id'];

        // Fetch buildings for this property
        $bygg = [];
        $bygninger = $this->bygningRepository->getBygningerForEiendom($matrikkelId);
        foreach ($bygninger as $bygning) {
            $bygg[] = $this->buildByggHierarchy($bygning);
        }

        return [
            'lokasjonskode' => $matr['lokasjonskode_eiendom'] ?? null,
            'matrikkelenhet_id' => $matrikkelId,
            'matrikkelnummer_tekst' => $matr['matrikkelnummer_tekst'] ?? null,
            'kommunenummer' => (int)$matr['kommunenummer'],
            'areal' => $matr['historisk_oppgitt_areal'] ?? null,
            'bygg' => $bygg,
        ];
    }

    /**
     * Build single bygg (building) with innganger and bruksenheter
     */
    private function buildByggHierarchy(array $bygning): array
    {
        $bygningId = (int)$bygning['bygning_id'];

        // Fetch entrances (innganger) for this building
        $innganger = [];
        $bygningenInnganger = $this->inngangRepository->findByBygningId($bygningId);
        foreach ($bygningenInnganger as $inngang) {
            $innganger[] = $this->buildInngangHierarchy($inngang);
        }

        return [
            'lokasjonskode' => $bygning['lokasjonskode_bygg'] ?? null,
            'bygning_id' => $bygningId,
            'matrikkel_bygning_nummer' => $bygning['matrikkel_bygning_nummer'] ?? null,
            'lopenummer_i_eiendom' => (int)$bygning['lopenummer_i_eiendom'] ?? null,
            'bygningstype_kode_id' => $bygning['bygningstype_kode_id'] ?? null,
            'innganger' => $innganger,
        ];
    }

    /**
     * Build single inngang (entrance) with bruksenheter
     */
    private function buildInngangHierarchy(array $inngang): array
    {
        $inngangId = (int)$inngang['inngang_id'];

        // Fetch units (bruksenheter) for this entrance
        $bruksenheter = [];
        $enheter = $this->bruksenhetRepository->findByInngangId($inngangId);
        foreach ($enheter as $enhet) {
            $bruksenheter[] = $this->buildBruksenhetNode($enhet);
        }

        return [
            'lokasjonskode' => $inngang['lokasjonskode_inngang'] ?? null,
            'inngang_id' => $inngangId,
            'husnummer' => (int)$inngang['husnummer'] ?? null,
            'bokstav' => $inngang['bokstav'] ?? null,
            'veg_id' => $inngang['veg_id'] ?? null,
            'lopenummer_i_bygg' => (int)$inngang['lopenummer_i_bygg'] ?? null,
            'bruksenheter' => $bruksenheter,
        ];
    }

    /**
     * Build single bruksenhet (dwelling unit) node
     */
    private function buildBruksenhetNode(array $enhet): array
    {
        return [
            'lokasjonskode' => $enhet['lokasjonskode_bruksenhet'] ?? null,
            'bruksenhet_id' => (int)$enhet['bruksenhet_id'],
            'lopenummer_i_inngang' => (int)$enhet['lopenummer_i_inngang'] ?? null,
            'bruksenhettype_kode_id' => $enhet['bruksenhettype_kode_id'] ?? null,
            'etasjenummer' => $enhet['etasjenummer'] ?? null,
            'antall_rom' => $enhet['antall_rom'] ?? null,
            'bruksareal' => $enhet['bruksareal'] ?? null,
        ];
    }
}
