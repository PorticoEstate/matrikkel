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
     * Get all available API endpoints
     */
    #[Route('/endpoints', name: 'endpoints', methods: ['GET'])]
    public function getEndpoints(): JsonResponse
    {
        $endpoints = [
            'health' => [
                'GET /api/v1/ping' => 'API health check'
            ],
            'address' => [
                'GET /api/v1/address/{id}' => 'Get address by ID',
                'GET /api/v1/address/search?q={query}' => 'Search addresses',
                'GET /api/v1/address/search/db?q={query}' => 'Search addresses in local database',
                'GET /api/v1/address/postal/{postnummer}' => 'Get postal area by postal code'
            ],
            'municipality' => [
                'GET /api/v1/municipality/{id}' => 'Get municipality by ID',
                'GET /api/v1/municipality/number/{number}' => 'Get municipality by number'
            ],
            'property_unit' => [
                'GET /api/v1/property-unit/{id}' => 'Get property unit by ID',
                'GET /api/v1/property-unit/address/{addressId}' => 'Get property units for address'
            ],
            'cadastral_unit' => [
                'GET /api/v1/cadastral-unit/{id}' => 'Get cadastral unit by ID',
                'GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}' => 'Get cadastral unit by matrikkel number',
                'GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}' => 'Get cadastral unit with festenummer',
                'GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}/{snr}' => 'Get cadastral unit with section number'
            ],
            'code_lists' => [
                'GET /api/v1/codelist' => 'Get all code lists',
                'GET /api/v1/codelist/{id}' => 'Get code list by ID (with codes)'
            ],
            'search' => [
                'GET /api/v1/search?q={query}&source=api&limit={number}&offset={start}' => 'Search using Matrikkel API (limit: 1-100 or -1 for all, offset: pagination start)',
                'GET /api/v1/search?q={query}&source=db' => 'Search using local database'
            ]
        ];

        return $this->jsonResponse([
            'title' => 'Matrikkel REST API',
            'version' => '1.0',
            'endpoints' => $endpoints
        ]);
    }

    // ==================== ADDRESS ENDPOINTS ====================

    /**
     * Get address by ID
     */
    #[Route('/address/{id}', name: 'address_get', methods: ['GET'], requirements: ['id' => '\d+'])]
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
     * Search addresses via API
     */
    #[Route('/address/search', name: 'address_search', methods: ['GET'])]
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
     * Search addresses in local database
     */
    #[Route('/address/search/db', name: 'address_search_db', methods: ['GET'])]
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
     * Get postal area by postal code
     */
    #[Route('/address/postal/{postnummer}', name: 'postal_area', methods: ['GET'], requirements: ['postnummer' => '\d+'])]
    public function getPostalArea(int $postnummer): JsonResponse
    {
        try {
            $postnummeromrade = $this->adresseService->getPostnummeromradeByNumber($postnummer);
            return $this->jsonResponse($postnummeromrade);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== MUNICIPALITY ENDPOINTS ====================

    /**
     * Get municipality by ID
     */
    #[Route('/municipality/{id}', name: 'municipality_get', methods: ['GET'], requirements: ['id' => '\d+'])]
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
     * Get municipality by number
     */
    #[Route('/municipality/number/{number}', name: 'municipality_by_number', methods: ['GET'], requirements: ['number' => '\d+'])]
    public function getMunicipalityByNumber(string $number): JsonResponse
    {
        try {
            $municipality = $this->kommuneService->getKommuneByNumber($number);
            return $this->jsonResponse($municipality);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== PROPERTY UNIT ENDPOINTS ====================

    /**
     * Get property unit by ID
     */
    #[Route('/property-unit/{id}', name: 'property_unit_get', methods: ['GET'], requirements: ['id' => '\d+'])]
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
     * Get property units for an address
     */
    #[Route('/property-unit/address/{addressId}', name: 'property_units_for_address', methods: ['GET'], requirements: ['addressId' => '\d+'])]
    public function getPropertyUnitsForAddress(int $addressId): JsonResponse
    {
        try {
            $propertyUnits = $this->bruksenhetService->getBruksenheterByAdresseId($addressId);
            
            // Convert property units to arrays for proper JSON serialization
            $convertedPropertyUnits = array_map(function($unit) {
                return $this->objectToArray($unit);
            }, $propertyUnits);
            
            return $this->jsonResponse([
                'address_id' => $addressId,
                'property_units' => $convertedPropertyUnits,
                'count' => count($propertyUnits)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== CADASTRAL UNIT ENDPOINTS ====================

    /**
     * Get cadastral unit by ID
     */
    #[Route('/cadastral-unit/{id}', name: 'cadastral_unit_get', methods: ['GET'], requirements: ['id' => '\d+'])]
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
     * Get cadastral unit by matrikkel number (knr-gnr/bnr)
     */
    #[Route('/cadastral-unit/{knr}/{gnr}/{bnr}', name: 'cadastral_unit_by_matrikkel', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+'])]
    public function getCadastralUnitByMatrikkel(int $knr, int $gnr, int $bnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr);
            return $this->jsonResponse([
                'matrikkel_number' => "$knr-$gnr/$bnr",
                'cadastral_unit' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Get cadastral unit by matrikkel number with festenummer (knr-gnr/bnr/fnr)
     */
    #[Route('/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}', name: 'cadastral_unit_with_feste', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+'])]
    public function getCadastralUnitWithFeste(int $knr, int $gnr, int $bnr, int $fnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr, $fnr);
            return $this->jsonResponse([
                'matrikkel_number' => "$knr-$gnr/$bnr/$fnr",
                'cadastral_unit' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Get cadastral unit by matrikkel number with section number (knr-gnr/bnr/fnr/snr)
     */
    #[Route('/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}/{snr}', name: 'cadastral_unit_with_section', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+', 'snr' => '\d+'])]
    public function getCadastralUnitWithSection(int $knr, int $gnr, int $bnr, int $fnr, int $snr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetService->getMatrikkelenhetByMatrikkel($knr, $gnr, $bnr, $fnr, $snr);
            return $this->jsonResponse([
                'matrikkel_number' => "$knr-$gnr/$bnr/$fnr/$snr",
                'cadastral_unit' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== CODE LIST ENDPOINTS ====================

    /**
     * Get code lists
     */
    #[Route('/codelist', name: 'codelists_get_all', methods: ['GET'])]
    public function getCodeLists(): JsonResponse
    {
        try {
            $codeLists = $this->kodelisteService->getKodelister();
            
            // Convert code lists to arrays for proper JSON serialization
            $convertedCodeLists = array_map(function($codeList) {
                return $this->objectToArray($codeList);
            }, $codeLists);
            
            return $this->jsonResponse([
                'code_lists' => $convertedCodeLists,
                'count' => count($codeLists)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get code list by ID
     */
    #[Route('/codelist/{id}', name: 'codelist_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getCodeList(int $id): JsonResponse
    {
        try {
            $codeList = $this->kodelisteService->getKodeliste($id, true);
            return $this->jsonResponse($codeList);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // ==================== GENERAL SEARCH ENDPOINT ====================

    /**
     * General search endpoint that supports both API and DB sources
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
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
                $paginationInfo['next_offset'] = $offset + $limit;
                $paginationInfo['has_more'] = count($results) === $limit; // If we got exactly the limit, there might be more
            } else {
                $paginationInfo['total_results'] = count($results);
                $paginationInfo['has_more'] = false; // We got everything
            }

            return $this->jsonResponse([
                'query' => $query,
                'source' => $source,
                'results' => $convertedResults,
                'count' => count($results),
                'pagination' => $paginationInfo
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
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