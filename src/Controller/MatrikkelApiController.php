<?php
/**
 * REST API Controller for Matrikkel services
 * Provides JSON endpoints for all Matrikkel functionality
 */

namespace Iaasen\Matrikkel\Controller;

use Iaasen\Matrikkel\Service\AdresseService;
use Iaasen\Matrikkel\Service\BruksenhetService;
use Iaasen\Matrikkel\Service\KodelisteService;
use Iaasen\Matrikkel\Service\KommuneService;
use Iaasen\Matrikkel\Service\MatrikkelenhetService;
use Iaasen\Matrikkel\Service\MatrikkelsokService;
use Iaasen\Matrikkel\LocalDb\AdresseSokService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1', name: 'api_')]
class MatrikkelApiController extends AbstractController
{
    public function __construct(
        private AdresseService $adresseService,
        private BruksenhetService $bruksenhetService,
        private KodelisteService $kodelisteService,
        private KommuneService $kommuneService,
        private MatrikkelenhetService $matrikkelenhetService,
        private MatrikkelsokService $matrikkelsokService,
        private AdresseSokService $adresseSokService
    ) {}

    /**
     * Get the complete list of available API endpoints
     * Centralized definition for easy maintenance
     */
    private function getAvailableEndpoints(): array
    {
        return [
            'health' => [
                'GET /api/v1/ping' => 'API health check'
            ],
            'adresse' => [
                'GET /api/v1/adresse/{id}' => 'Hent adresse på ID',
                'GET /api/v1/adresse/sok?q={query}' => 'Søk adresser',
                'GET /api/v1/adresse/sok/db?q={query}' => 'Søk adresser i lokal database',
                'GET /api/v1/adresse/postnummer/{postnummer}' => 'Hent postnummerområde'
            ],
            'kommune' => [
                'GET /api/v1/kommune/{id}' => 'Hent kommune på ID',
                'GET /api/v1/kommune/nummer/{nummer}' => 'Hent kommune på kommunenummer'
            ],
            'bruksenhet' => [
                'GET /api/v1/bruksenhet/{id}' => 'Hent bruksenhet på ID',
                'GET /api/v1/bruksenhet/adresse/{adresseId}' => 'Hent bruksenheter for adresse'
            ],
            'matrikkelenhet' => [
                'GET /api/v1/matrikkelenhet/{id}' => 'Hent matrikkelenhet på ID',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}' => 'Hent matrikkelenhet på matrikkelnummer',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}' => 'Hent matrikkelenhet med festenummer',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}/{snr}' => 'Hent matrikkelenhet med seksjonsnummer'
            ],
            'kodeliste' => [
                'GET /api/v1/kodeliste' => 'Hent alle kodelister',
                'GET /api/v1/kodeliste/{id}' => 'Hent kodeliste på ID (med koder)'
            ],
            'sok' => [
                'GET /api/v1/sok?q={query}&source=api&limit={number}&offset={start}' => 'Søk i Matrikkel API (limit: 1-100 eller -1 for alle, offset: paginering)',
                'GET /api/v1/sok?q={query}&source=db' => 'Søk i lokal database'
            ]
        ];
    }

    /**
     * API Health Check / Ping
     */
    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        try {
            $this->kommuneService->getKommuneById(5006); // Test with a known municipality
            return $this->jsonResponse(['status' => 'success', 'message' => 'API connection successful']);
        } catch (\Exception $e) {
            return $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent alle tilgjengelige API-endepunkter
     */
    #[Route('/endpoints', name: 'endpoints', methods: ['GET'])]
    public function getEndpoints(): JsonResponse
    {
        return $this->jsonResponse([
            'tittel' => 'Matrikkel REST API',
            'versjon' => '1.0',
            'endepunkter' => $this->getAvailableEndpoints()
        ]);
    }

    // ==================== ADRESSE ENDPOINTS ====================

    /**
     * Hent adresse på ID
     */
    #[Route('/adresse/{id}', name: 'adresse_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAddress(int $id): JsonResponse
    {
        try {
            $address = $this->adresseService->getAddressById($id);
            return $this->jsonResponse($address);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Søk adresser via API
     */
    #[Route('/adresse/sok', name: 'adresse_sok', methods: ['GET'])]
    public function searchAddresses(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (empty($query)) {
            return $this->jsonResponse(['error' => 'Query parameter "q" is required'], 400);
        }

        try {
            $addresses = $this->adresseService->searchAddress($query);
            
            // Convert addresses to arrays for proper JSON serialization
            $convertedAddresses = array_map(function($address) {
                return $this->objectToArray($address);
            }, $addresses);
            
            return $this->jsonResponse([
                'query' => $query,
                'results' => $convertedAddresses,
                'count' => count($addresses)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Søk adresser i lokal database
     */
    #[Route('/adresse/sok/db', name: 'adresse_sok_db', methods: ['GET'])]
    public function searchAddressesInDb(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (empty($query)) {
            return $this->jsonResponse(['error' => 'Query parameter "q" is required'], 400);
        }

        try {
            $addresses = $this->adresseSokService->search($query);
            return $this->jsonResponse([
                'query' => $query,
                'source' => 'local_database',
                'results' => $addresses,
                'count' => count($addresses)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent postnummerområde
     */
    #[Route('/adresse/postnummer/{postnummer}', name: 'postnummer_hent', methods: ['GET'], requirements: ['postnummer' => '\d+'])]
    public function getPostalArea(int $postnummer): JsonResponse
    {
        try {
            $postnummeromrade = $this->adresseService->getPostnummeromradeByNumber($postnummer);
            return $this->jsonResponse($postnummeromrade);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== KOMMUNE ENDPOINTS ====================

    /**
     * Hent kommune på ID
     */
    #[Route('/kommune/{id}', name: 'kommune_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getMunicipality(int $id): JsonResponse
    {
        try {
            $municipality = $this->kommuneService->getKommuneById($id);
            return $this->jsonResponse($municipality);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Hent kommune på kommunenummer
     */
    #[Route('/kommune/nummer/{nummer}', name: 'kommune_etter_nummer', methods: ['GET'], requirements: ['nummer' => '\d+'])]
    public function getMunicipalityByNumber(string $nummer): JsonResponse
    {
        try {
            $municipality = $this->kommuneService->getKommuneByNumber($nummer);
            return $this->jsonResponse($municipality);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== BRUKSENHET ENDPOINTS ====================

    /**
     * Hent bruksenhet på ID
     */
    #[Route('/bruksenhet/{id}', name: 'bruksenhet_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getPropertyUnit(int $id): JsonResponse
    {
        try {
            $propertyUnit = $this->bruksenhetService->getBruksenhetById($id);
            return $this->jsonResponse($propertyUnit);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Hent bruksenheter for adresse
     */
    #[Route('/bruksenhet/adresse/{adresseId}', name: 'bruksenheter_for_adresse', methods: ['GET'], requirements: ['adresseId' => '\d+'])]
    public function getPropertyUnitsForAddress(int $adresseId): JsonResponse
    {
        try {
            $propertyUnits = $this->bruksenhetService->getBruksenheterByAdresseId($adresseId);
            
            // Convert property units to arrays for proper JSON serialization
            $convertedPropertyUnits = array_map(function($unit) {
                return $this->objectToArray($unit);
            }, $propertyUnits);
            
            return $this->jsonResponse([
                'adresse_id' => $adresseId,
                'bruksenheter' => $convertedPropertyUnits,
                'antall' => count($propertyUnits)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== MATRIKKELENHET ENDPOINTS ====================

    /**
     * Hent matrikkelenhet på ID
     */
    #[Route('/matrikkelenhet/{id}', name: 'matrikkelenhet_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getCadastralUnit(int $id): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetById($id);
            return $this->jsonResponse($cadastralUnit);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Hent matrikkelenhet på matrikkelnummer (knr-gnr/bnr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}', name: 'matrikkelenhet_etter_matrikkelnummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+'])]
    public function getCadastralUnitByMatrikkel(int $knr, int $gnr, int $bnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr);
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Hent matrikkelenhet med festenummer (knr-gnr/bnr/fnr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}', name: 'matrikkelenhet_med_festenummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+'])]
    public function getCadastralUnitWithFeste(int $knr, int $gnr, int $bnr, int $fnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr, $fnr);
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr/$fnr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Hent matrikkelenhet med seksjonsnummer (knr-gnr/bnr/fnr/snr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}/{snr}', name: 'matrikkelenhet_med_seksjonsnummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+', 'snr' => '\d+'])]
    public function getCadastralUnitWithSection(int $knr, int $gnr, int $bnr, int $fnr, int $snr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr, $fnr, $snr);
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr/$fnr/$snr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== KODELISTE ENDPOINTS ====================

    /**
     * Hent alle kodelister
     */
    #[Route('/kodeliste', name: 'kodelister_hent_alle', methods: ['GET'])]
    public function getCodeLists(): JsonResponse
    {
        try {
            $codeLists = $this->kodelisteService->getKodelister();
            
            // Convert code lists to arrays for proper JSON serialization
            $convertedCodeLists = array_map(function($codeList) {
                return $this->objectToArray($codeList);
            }, $codeLists);
            
            return $this->jsonResponse([
                'kodelister' => $convertedCodeLists,
                'antall' => count($codeLists)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent kodeliste på ID
     */
    #[Route('/kodeliste/{id}', name: 'kodeliste_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getCodeList(int $id): JsonResponse
    {
        try {
            $codeList = $this->kodelisteService->getKodeliste($id, true);
            return $this->jsonResponse($codeList);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== SØK ENDPOINT ====================

    /**
     * Generelt søk som støtter både API og DB kilder
     */
    #[Route('/sok', name: 'sok', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $source = $request->query->get('source', 'api'); // 'api' or 'db'
        $limit = (int) $request->query->get('limit', 20); // Default to 20, max 100
        $offset = (int) $request->query->get('offset', 0); // Start position for pagination

        if (empty($query)) {
            return $this->jsonResponse(['error' => 'Query parameter "q" is required'], 400);
        }

        if (!in_array($source, ['api', 'db'])) {
            return $this->jsonResponse(['error' => 'Source parameter must be "api" or "db"'], 400);
        }

        // Handle special case where limit=-1 means get all results
        $getAllResults = ($limit === -1);
        
        // Limit the maximum number of results per request to prevent abuse (unless -1 for all)
        if (!$getAllResults) {
            $limit = min(max($limit, 1), 100);
        }
        $offset = max($offset, 0);

        try {
            if ($source === 'api') {
                $results = $this->matrikkelsokService->searchAddresses($query, $limit, $offset);
            } else {
                $results = $this->adresseSokService->search($query);
            }

            // Convert results to arrays for proper JSON serialization
            $convertedResults = array_map(function($result) {
                return $this->objectToArray($result);
            }, $results);

            // Prepare pagination metadata
            $paginationInfo = [
                'offset' => $offset,
                'limit' => $getAllResults ? -1 : $limit,
            ];
            
            // Only add next_offset and has_more if not getting all results
            if (!$getAllResults) {
                $paginationInfo['neste_offset'] = $offset + $limit;
                $paginationInfo['har_flere'] = count($results) === $limit; // If we got exactly the limit, there might be more
            } else {
                $paginationInfo['totalt_antall'] = count($results);
                $paginationInfo['har_flere'] = false; // We got everything
            }

            return $this->jsonResponse([
                'query' => $query,
                'kilde' => $source,
                'resultater' => $convertedResults,
                'antall' => count($results),
                'paginering' => $paginationInfo
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ==================== FALLBACK / CATCH-ALL ====================

    /**
     * Fallback-rute for ugyldige API-stier
     * Viser tilgjengelige endpoints når man aksesserer ikke-eksisterende ruter
     */
    #[Route('/{path}', name: 'api_fallback', requirements: ['path' => '.*'], priority: -1)]
    public function fallback(string $path): JsonResponse
    {
        return $this->json([
            'feil' => 'Rute ikke funnet',
            'forespurt_sti' => '/api/v1/' . $path,
            'melding' => 'Det forespurte API-endepunktet eksisterer ikke. Se tilgjengelige endepunkter nedenfor.',
            'tilgjengelige_endepunkter' => $this->getAvailableEndpoints(),
            'hint' => 'Besøk /api/v1/endpoints for full API-dokumentasjon'
        ], 404);
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Helper method to create consistent JSON responses
     */
    private function jsonResponse($data, int $status = 200): JsonResponse
    {
        if ($data === null) {
            return new JsonResponse(['error' => 'Resource not found'], 404);
        }

        // Convert objects to arrays for JSON serialization
        if (is_object($data) || (is_array($data) && !empty($data) && is_object($data[0] ?? null))) {
            try {
                // Use simple object to array conversion
                $convertedData = $this->objectToArray($data);
                
                return new JsonResponse([
                    'data' => $convertedData,
                    'timestamp' => date('c'),
                    'status' => $status < 400 ? 'success' : 'error'
                ], $status);
            } catch (\Exception $e) {
                // Fallback to basic array conversion
                return new JsonResponse([
                    'data' => $this->objectToArray($data),
                    'timestamp' => date('c'),
                    'status' => $status < 400 ? 'success' : 'error'
                ], $status);
            }
        }

        return new JsonResponse([
            'data' => $data,
            'timestamp' => date('c'),
            'status' => $status < 400 ? 'success' : 'error'
        ], $status);
    }

    /**
     * Convert objects to arrays recursively for JSON output
     */
    private function objectToArray($data): mixed
    {
        if (is_object($data)) {
            $array = [];
            
            // Handle DateTime objects specially
            if ($data instanceof \DateTime || $data instanceof \Iaasen\DateTime) {
                return $data->format('c');
            }
            
            // For entities, try to use getter methods first
            $reflection = new \ReflectionClass($data);
            
            // Get all properties from this class and all parent classes
            $properties = [];
            $currentClass = $reflection;
            
            while ($currentClass) {
                $properties = array_merge($properties, $currentClass->getProperties());
                $currentClass = $currentClass->getParentClass();
            }
            
            foreach ($properties as $property) {
                $name = $property->getName();
                
                // Skip internal/system properties
                if (str_starts_with($name, '_') || str_starts_with($name, 'throwException') || $name === 'docBlockData') {
                    continue;
                }
                
                try {
                    $property->setAccessible(true);
                    
                    // Check if property is initialized
                    if (!$property->isInitialized($data)) {
                        continue;
                    }
                    
                    $value = $property->getValue($data);
                    
                    // Only include non-null values to reduce clutter
                    if ($value !== null) {
                        $array[$name] = $this->objectToArray($value);
                    }
                } catch (\Exception $e) {
                    // Skip properties that can't be accessed
                    continue;
                }
            }
            
            // If we got no properties, try get_object_vars as fallback
            if (empty($array)) {
                $publicVars = get_object_vars($data);
                foreach ($publicVars as $key => $value) {
                    if (!str_starts_with($key, '_') && $value !== null) {
                        $array[$key] = $this->objectToArray($value);
                    }
                }
            }
            
            // Add class name for debugging if needed (but not for every result to avoid clutter)
            // Commented out to keep JSON responses clean
            // if (count($array) < 5) { 
            //     $array['_class'] = get_class($data);
            // }
            
            return $array;
        }

        if (is_array($data)) {
            return array_map([$this, 'objectToArray'], $data);
        }

        return $data;
    }
}