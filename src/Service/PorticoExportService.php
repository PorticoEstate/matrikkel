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
use Iaasen\Matrikkel\LocalDb\VegRepository;

class PorticoExportService
{
    public function __construct(
        private MatrikkelenhetRepository $matrikkelenhetRepository,
        private BygningRepository $bygningRepository,
        private InngangRepository $inngangRepository,
        private BruksenhetRepository $bruksenhetRepository,
        private VegRepository $vegRepository,
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

        // Group matrikkelenheter by kommune/gnr/bnr (ignore fnr/snr)
        // Multiple fnr/snr within same gnr/bnr are treated as single property
        $groups = $this->groupByMatrikkelBase($matrikkelenheter);

        // Build hierarchy and assign sequential lokasjonskoder
        $eiendommer = [];
        $lokasjonskodeCounter = 5000;
        
        foreach ($groups as $groupMatrikkelenheter) {
            $eiendom = $this->buildEiendomHierarchyFromGroup($groupMatrikkelenheter, $lokasjonskodeCounter);
            
            // Only include eiendommer that have buildings
            if (!empty($eiendom['bygg'])) {
                $eiendommer[] = $eiendom;
                $lokasjonskodeCounter++;
            }
        }

        // Fetch streets (gater) for the kommune if provided
        $gater = [];
        if ($kommune) {
            $gater = $this->vegRepository->findByKommunenummer($kommune);
        }

        return [
            'eiendommer' => $eiendommer,
            'gater' => $gater,
            'count' => count($eiendommer),
        ];
    }

    /**
     * Group matrikkelenheter by kommune/gardsnummer/bruksnummer
     * Treats multiple festenummer/seksjonsnummer within same gnr/bnr as one group
     * 
     * @param array $matrikkelenheter
     * @return array Array of groups, each group is array of matrikkelenheter
     */
    private function groupByMatrikkelBase(array $matrikkelenheter): array
    {
        $groups = [];
        
        foreach ($matrikkelenheter as $matr) {
            // Create group key from kommune/gnr/bnr only (ignore fnr/snr)
            $groupKey = sprintf(
                '%d_%d_%d',
                (int)$matr['kommunenummer'],
                (int)$matr['gardsnummer'],
                (int)$matr['bruksnummer']
            );
            
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            
            $groups[$groupKey][] = $matr;
        }
        
        return array_values($groups); // Reset array keys
    }

    /**
     * Build eiendom from a group of matrikkelenheter (may have multiple fnr/snr)
     * 
     * @param array $groupMatrikkelenheter
     * @param int $lokasjonskode
     * @return array
     */
    private function buildEiendomHierarchyFromGroup(array $groupMatrikkelenheter, int $lokasjonskode): array
    {
        // Use first matrikkelenhet as base for property info
        $baseMat = $groupMatrikkelenheter[0];
        $baseMatrikkelId = (int)$baseMat['matrikkelenhet_id'];

        // Collect all bygninger for all matrikkelenheter in group
        $bygninger = [];
        $processedBygninger = []; // Track bygning_id to avoid duplicates
        
        foreach ($groupMatrikkelenheter as $matr) {
            $matrikkelId = (int)$matr['matrikkelenhet_id'];
            $matrBygninger = $this->bygningRepository->getBygningerForEiendom($matrikkelId);
            
            foreach ($matrBygninger as $bygning) {
                $bygningId = (int)$bygning['bygning_id'];
                // Avoid duplicate buildings
                if (!isset($processedBygninger[$bygningId])) {
                    $bygninger[] = $bygning;
                    $processedBygninger[$bygningId] = true;
                }
            }
        }

        // Build bygg hierarchy from collected buildings
        $bygg = [];
        $byggSekvens = 1;
        foreach ($bygninger as $bygning) {
            $byggKode = sprintf('%d-%02d', $lokasjonskode, $byggSekvens);
            $bygg[] = $this->buildByggHierarchy($bygning, $lokasjonskode, $byggSekvens);
            $byggSekvens++;
        }

        return [
            'lokasjonskode' => (string)$lokasjonskode,
            'matrikkelenhet_id' => $baseMatrikkelId,
            'matrikkelnummer_tekst' => $baseMat['matrikkelnummer_tekst'] ?? null,
            'kommunenummer' => (int)$baseMat['kommunenummer'],
            'areal' => $baseMat['historisk_oppgitt_areal'] ?? null,
            'bygg' => $bygg,
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
     * lokasjonskode format: {eiendom_kode}-{bygg_sekvens:02d}
     */
    private function buildByggHierarchy(array $bygning, ?int $eiendomKode = null, ?int $sekvens = null): array
    {
        $bygningId = (int)$bygning['bygning_id'];

        // Generate lokasjonskode if eiendomKode and sekvens provided
        if ($eiendomKode !== null && $sekvens !== null) {
            $lokasjonskodeGenerated = sprintf('%d-%02d', $eiendomKode, $sekvens);
        } else {
            $lokasjonskodeGenerated = $bygning['lokasjonskode_bygg'] ?? null;
        }

        // Fetch entrances (innganger) for this building
        $innganger = [];
        $bygningenInnganger = $this->inngangRepository->findByBygningId($bygningId);
        $inngangSekvens = 1;
        foreach ($bygningenInnganger as $inngang) {
            $innganger[] = $this->buildInngangHierarchy($inngang, $lokasjonskodeGenerated, $inngangSekvens);
            $inngangSekvens++;
        }

        return [
            'lokasjonskode' => $lokasjonskodeGenerated,
            'bygning_id' => $bygningId,
            'matrikkel_bygning_nummer' => $bygning['matrikkel_bygning_nummer'] ?? null,
            'lopenummer_i_eiendom' => (int)$bygning['lopenummer_i_eiendom'] ?? null,
            'bygningstype_kode_id' => $bygning['bygningstype_kode_id'] ?? null,
            'antall_etasjer' => $bygning['antall_etasjer'] ?? null,
            'bruksareal' => $bygning['bruksareal'] ?? null,
            'byggeaar' => $bygning['byggeaar'] ?? null,
            'representasjonspunkt_x' => $bygning['representasjonspunkt_x'] ?? null,
            'representasjonspunkt_y' => $bygning['representasjonspunkt_y'] ?? null,
            'innganger' => $innganger,
        ];
    }

    /**
     * Build single inngang (entrance) with bruksenheter
     * lokasjonskode format: {bygg_kode}-{inngang_sekvens:02d}
     * 
     * Synthetic entrances (veg_id=null, husnummer=0) are marked with is_synthetic=true
     */
    private function buildInngangHierarchy(array $inngang, ?string $byggKode = null, ?int $sekvens = null): array
    {
        $inngangId = (int)$inngang['inngang_id'];
        $vegId = $inngang['veg_id'] ?? null;
        $husnummer = (int)($inngang['husnummer'] ?? null);

        // Detect synthetic entrance: veg_id=null and husnummer=0
        $isSynthetic = ($vegId === null && $husnummer === 0);

        // Generate lokasjonskode if byggKode and sekvens provided
        if ($byggKode !== null && $sekvens !== null) {
            $lokasjonskodeGenerated = sprintf('%s-%02d', $byggKode, $sekvens);
        } else {
            $lokasjonskodeGenerated = $inngang['lokasjonskode_inngang'] ?? null;
        }

        // Fetch units (bruksenheter) for this entrance
        $bruksenheter = [];
        $enheter = $this->bruksenhetRepository->findByInngangId($inngangId);
        $bruksenhetSekvens = 1;
        foreach ($enheter as $enhet) {
            $bruksenheter[] = $this->buildBruksenhetNode($enhet, $lokasjonskodeGenerated, $bruksenhetSekvens);
            $bruksenhetSekvens++;
        }

        // For synthetic entrances, provide a display label
        $gatenavn = $inngang['gatenavn'] ?? null;
        if ($isSynthetic) {
            $gatenavn = '[Ingen gateadresse]';
        }

        return [
            'lokasjonskode' => $lokasjonskodeGenerated,
            'inngang_id' => $inngangId,
			'gatenavn' => $gatenavn,
            'husnummer' => !$isSynthetic ? (int)$inngang['husnummer'] ?? null : null,
            'bokstav' => !$isSynthetic ? $inngang['bokstav'] ?? null : null,
            'veg_id' => $vegId,
            'adressekode' => $inngang['adressekode'] ?? null,
            'lopenummer_i_bygg' => (int)$inngang['lopenummer_i_bygg'] ?? null,
            'is_synthetic' => $isSynthetic,
            'bruksenheter' => $bruksenheter,
        ];
    }

    /**
     * Build single bruksenhet (dwelling unit) node
     * lokasjonskode format: {inngang_kode}-{bruksenhet_sekvens:03d}
     */
    private function buildBruksenhetNode(array $enhet, ?string $inngangKode = null, ?int $sekvens = null): array
    {
        // Generate lokasjonskode if inngangKode and sekvens provided
        if ($inngangKode !== null && $sekvens !== null) {
            $lokasjonskodeGenerated = sprintf('%s-%03d', $inngangKode, $sekvens);
        } else {
            $lokasjonskodeGenerated = $enhet['lokasjonskode_bruksenhet'] ?? null;
        }

        return [
            'lokasjonskode' => $lokasjonskodeGenerated,
            'bruksenhet_id' => (int)$enhet['bruksenhet_id'],
            'lopenummer_i_inngang' => (int)$enhet['lopenummer_i_inngang'] ?? null,
            'bruksenhettype_kode_id' => $enhet['bruksenhettype_kode_id'] ?? null,
            'etasjeplan_kode_id' => $enhet['etasjeplan_kode_id'] ?? null,
            'etasjenummer' => $enhet['etasjenummer'] ?? null,
            'antall_rom' => $enhet['antall_rom'] ?? null,
            'bruksareal' => $enhet['bruksareal'] ?? null,
        ];
    }

    /**
     * Export hierarchy as Excel spreadsheet
     * 
     * @param int|null $kommune (optional filter)
     * @param string|null $organisasjonsnummer (optional filter on owner)
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function exportAsSpreadsheet(int|null $kommune = null, string|null $organisasjonsnummer = null): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // Get the hierarchical data
        $exportData = $this->export($kommune, $organisasjonsnummer);
        
        // Use ExcelExportService to create spreadsheet
        $excelExporter = new ExcelExportService();
        $spreadsheet = $excelExporter->export($exportData);
        
        return $spreadsheet;
    }
}
