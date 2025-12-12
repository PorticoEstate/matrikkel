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
    public function export(int| null $kommune = null, string | null $organisasjonsnummer = null): array
    {
        // Fetch matrikkelenheter with kommune filter
        if ($kommune) {
            $matrikkelenheter = $this->matrikkelenhetRepository->findByKommunenummer($kommune, 10000);
        } else {
            // If no kommune specified, use search with empty criteria to get all
            $matrikkelenheter = $this->matrikkelenhetRepository->search([], 10000);
        }

        // Filter by organisasjonsnummer if specified
        if ($organisasjonsnummer && !empty($matrikkelenheter)) {
            $matrikkelenheter = $this->filterByOrganisasjonsnummer($matrikkelenheter, $organisasjonsnummer);
        }

        // Build hierarchy and filter out properties without buildings
        $eiendommer = [];
        foreach ($matrikkelenheter as $matr) {
            $eiendom = $this->buildEiendomHierarchy($matr);
            
            // Only include eiendommer that have buildings
            if (!empty($eiendom['bygg'])) {
                $eiendommer[] = $eiendom;
            }
        }

        return [
            'eiendommer' => $eiendommer,
            'count' => count($eiendommer),
        ];
    }

    /**
     * Filter matrikkelenheter by organisasjonsnummer (owner)
     * 
     * @param array $matrikkelenheter
     * @param string $organisasjonsnummer
     * @return array
     */
    private function filterByOrganisasjonsnummer(array $matrikkelenheter, string $organisasjonsnummer): array
    {
        $filtered = [];
        
        foreach ($matrikkelenheter as $matr) {
            $matrikkelId = (int)$matr['matrikkelenhet_id'];
            // Check if this matrikkelenhet has ownership by specified organisasjonsnummer
            $eierforhold = $this->matrikkelenhetRepository->findWithEierforhold($matrikkelId);
            
            // findWithEierforhold returns array with eierforhold data
            // Check if any eierforhold matches the organisasjonsnummer
            $hasOwner = false;
            if (isset($eierforhold['eierforhold']) && is_array($eierforhold['eierforhold'])) {
                foreach ($eierforhold['eierforhold'] as $eierfh) {
                    if (isset($eierfh['organisasjonsnummer']) && $eierfh['organisasjonsnummer'] === $organisasjonsnummer) {
                        $hasOwner = true;
                        break;
                    }
                }
            }
            
            if ($hasOwner) {
                $filtered[] = $matr;
            }
        }
        
        return $filtered;
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
			'antall_etasjer' => $bygning['antall_etasjer'] ?? null,
			'bruksareal' => $bygning['bruksareal'] ?? null,
			'byggeaar' => $bygning['byggeaar'] ?? null,
			'antall_etasjer' => $bygning['antall_etasjer'] ?? null,
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
            'adressekode' => $inngang['adressekode'] ?? null,
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
