<?php
/**
 * REST API Controller for Matrikkel services
 * Provides JSON endpoints serving data from PostgreSQL database
 * 
 * REFACTORED: Now uses Repository pattern to query local database
 * instead of calling SOAP services to external Matrikkel API
 */

namespace Iaasen\Matrikkel\Controller;

use Iaasen\Matrikkel\LocalDb\AdresseRepository;
use Iaasen\Matrikkel\LocalDb\BruksenhetRepository;
use Iaasen\Matrikkel\LocalDb\BygningRepository;
use Iaasen\Matrikkel\LocalDb\KommuneRepository;
use Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository;
use Iaasen\Matrikkel\LocalDb\PersonRepository;
use Iaasen\Matrikkel\LocalDb\VegRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1', name: 'api_')]
class MatrikkelApiController extends AbstractController
{
    public function __construct(
        private AdresseRepository $adresseRepository,
        private BruksenhetRepository $bruksenhetRepository,
        private BygningRepository $bygningRepository,
        private KommuneRepository $kommuneRepository,
        private MatrikkelenhetRepository $matrikkelenhetRepository,
        private PersonRepository $personRepository,
        private VegRepository $vegRepository
    ) {}

    /**
     * Get the complete list of available API endpoints
     * Centralized definition for easy maintenance
     */
    private function getAvailableEndpoints(): array
    {
        return [
            'health' => [
                'GET /api/v1/ping' => 'API health check (database connection test)'
            ],
            'adresse' => [
                'GET /api/v1/adresse/{id}' => 'Hent adresse på ID',
                'GET /api/v1/adresse/sok?q={query}&limit={number}' => 'Søk adresser (database)',
                'GET /api/v1/adresse/sok/db?q={query}' => 'Søk adresser i lokal database (alias)',
                'GET /api/v1/adresse/kommune/{kommunenummer}?limit={number}' => 'Hent adresser i kommune',
                'GET /api/v1/adresse/kommune/{kommunenummer}/{bygningsnummer}?limit={number}' => 'Hent adresser i kommune for bygningsnummer'
            ],
            'kommune' => [
                'GET /api/v1/kommune/{id}' => 'Hent kommune på kommunenummer',
                'GET /api/v1/kommune?limit={number}' => 'Hent alle kommuner'
            ],
            'gate' => [
                'GET /api/v1/gate/{kommunenr}' => 'Hent alle gater i kommune',
                'GET /api/v1/gate/{kommunenr}/{adressekode}' => 'Hent spesifikk gate'
            ],
            'bruksenhet' => [
                'GET /api/v1/bruksenhet/{id}' => 'Hent bruksenhet på ID',
                'GET /api/v1/bruksenhet/adresse/{adresseId}' => 'Hent bruksenheter for adresse',
                'GET /api/v1/bruksenhet/bygning/{bygningId}' => 'Hent bruksenheter for bygning'
            ],
            'matrikkelenhet' => [
                'GET /api/v1/matrikkelenhet/{id}' => 'Hent matrikkelenhet på ID',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}' => 'Hent matrikkelenhet på matrikkelnummer',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}' => 'Hent matrikkelenhet med festenummer',
                'GET /api/v1/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}/{snr}' => 'Hent matrikkelenhet med seksjonsnummer'
            ],
            'sok' => [
                'GET /api/v1/sok?q={query}&limit={number}' => 'Søk i database (adresser og matrikkelenheter)'
            ],
            'info' => [
                'Note' => 'All data is served from local PostgreSQL database populated by Phase1/Phase2 imports',
                'Database' => 'PostgreSQL at 10.0.2.15:5435'
            ]
        ];
    }

    /**
     * API Health Check / Ping
     * Tests database connection by querying a known kommune
     */
    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        try {
            $kommune = $this->kommuneRepository->findById(5006); // Trondheim
            if ($kommune) {
                return $this->jsonResponse([
                    'status' => 'success', 
                    'message' => 'Database connection successful',
                    'database' => 'PostgreSQL',
                    'test_kommune' => $kommune['kommunenavn']
                ]);
            }
            return $this->jsonResponse([
                'status' => 'warning',
                'message' => 'Database connected but test kommune not found'
            ], 200);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'status' => 'error', 
                'message' => 'Database connection failed: ' . $e->getMessage()
            ], 500);
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
            $address = $this->adresseRepository->findById($id);
            if (!$address) {
                return $this->jsonResponse(['error' => 'Address not found'], 404);
            }
            return $this->jsonResponse($address);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Søk adresser
     */
    #[Route('/adresse/sok', name: 'adresse_sok', methods: ['GET'])]
    public function searchAddresses(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (empty($query)) {
            return $this->jsonResponse(['error' => 'Query parameter "q" is required'], 400);
        }

        $limit = (int) $request->query->get('limit', 100);

        try {
            $addresses = $this->adresseRepository->search($query, $limit);
            return $this->jsonResponse([
                'query' => $query,
                'source' => 'database',
                'results' => $addresses,
                'count' => count($addresses)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Søk adresser i lokal database (alias for backward compatibility)
     */
    #[Route('/adresse/sok/db', name: 'adresse_sok_db', methods: ['GET'])]
    public function searchAddressesInDb(Request $request): JsonResponse
    {
        // Now this is the same as searchAddresses since we use database for all queries
        return $this->searchAddresses($request);
    }

    /**
     * Hent adresser i kommune
     */
    #[Route('/adresse/kommune/{kommunenummer}', name: 'adresse_kommune', methods: ['GET'], requirements: ['kommunenummer' => '\d+'])]
    public function getAddressesByKommune(int $kommunenummer, Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 1000);

        try {
            $addresses = $this->adresseRepository->findByKommunenummer($kommunenummer, $limit);
            return $this->jsonResponse([
                'kommunenummer' => $kommunenummer,
                'addresses' => $addresses,
                'count' => count($addresses)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent adresser i kommune filtrert på bygningsnummer
     */
    #[Route('/adresse/kommune/{kommunenummer}/{bygningsnummer}', name: 'adresse_kommune_bygning', methods: ['GET'], requirements: ['kommunenummer' => '\d+', 'bygningsnummer' => '\d+'])]
    public function getAddressesByKommuneAndBygning(int $kommunenummer, int $bygningsnummer, Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 1000);

        try {
            $addresses = $this->adresseRepository->findByKommunenummerAndBygningsnummer($kommunenummer, $bygningsnummer, $limit);
            return $this->jsonResponse([
                'kommunenummer' => $kommunenummer,
                'bygningsnummer' => $bygningsnummer,
                'addresses' => $addresses,
                'count' => count($addresses)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ==================== KOMMUNE ENDPOINTS ====================

    /**
     * Hent kommune på ID (kommunenummer)
     */
    #[Route('/kommune/{id}', name: 'kommune_hent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getMunicipality(int $id): JsonResponse
    {
        try {
            $municipality = $this->kommuneRepository->findById($id);
            if (!$municipality) {
                return $this->jsonResponse(['error' => 'Municipality not found'], 404);
            }
            return $this->jsonResponse($municipality);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Hent alle kommuner
     */
    #[Route('/kommune', name: 'kommune_alle', methods: ['GET'])]
    public function getAllMunicipalities(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 1000);

        try {
            $municipalities = $this->kommuneRepository->findAll($limit);
            return $this->jsonResponse([
                'municipalities' => $municipalities,
                'count' => count($municipalities)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
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
            $propertyUnit = $this->bruksenhetRepository->findById($id);
            if (!$propertyUnit) {
                return $this->jsonResponse(['error' => 'Property unit not found'], 404);
            }
            return $this->jsonResponse($propertyUnit);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent bruksenheter for adresse
     */
    #[Route('/bruksenhet/adresse/{adresseId}', name: 'bruksenheter_for_adresse', methods: ['GET'], requirements: ['adresseId' => '\d+'])]
    public function getPropertyUnitsForAddress(int $adresseId): JsonResponse
    {
        try {
            $propertyUnits = $this->bruksenhetRepository->findByAdresseId($adresseId);
            return $this->jsonResponse([
                'adresse_id' => $adresseId,
                'bruksenheter' => $propertyUnits,
                'antall' => count($propertyUnits)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent bruksenheter for bygning
     */
    #[Route('/bruksenhet/bygning/{bygningId}', name: 'bruksenheter_for_bygning', methods: ['GET'], requirements: ['bygningId' => '\d+'])]
    public function getPropertyUnitsForBuilding(int $bygningId): JsonResponse
    {
        try {
            $propertyUnits = $this->bruksenhetRepository->findByBygningId($bygningId);
            return $this->jsonResponse([
                'bygning_id' => $bygningId,
                'bruksenheter' => $propertyUnits,
                'antall' => count($propertyUnits)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
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
            $cadastralUnit = $this->matrikkelenhetRepository->findById($id);
            if (!$cadastralUnit) {
                return $this->jsonResponse(['error' => 'Matrikkelenhet not found'], 404);
            }
            return $this->jsonResponse($cadastralUnit);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent matrikkelenhet på matrikkelnummer (knr-gnr/bnr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}', name: 'matrikkelenhet_etter_matrikkelnummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+'])]
    public function getCadastralUnitByMatrikkel(int $knr, int $gnr, int $bnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetRepository->findByMatrikkelNummer($knr, $gnr, $bnr);
            if (!$cadastralUnit) {
                return $this->jsonResponse(['error' => 'Matrikkelenhet not found'], 404);
            }
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent matrikkelenhet med festenummer (knr-gnr/bnr/fnr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}', name: 'matrikkelenhet_med_festenummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+'])]
    public function getCadastralUnitWithFeste(int $knr, int $gnr, int $bnr, int $fnr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetRepository->findByMatrikkelNummer($knr, $gnr, $bnr, $fnr);
            if (!$cadastralUnit) {
                return $this->jsonResponse(['error' => 'Matrikkelenhet not found'], 404);
            }
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr/$fnr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Hent matrikkelenhet med seksjonsnummer (knr-gnr/bnr/fnr/snr)
     */
    #[Route('/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}/{snr}', name: 'matrikkelenhet_med_seksjonsnummer', methods: ['GET'], requirements: ['knr' => '\d+', 'gnr' => '\d+', 'bnr' => '\d+', 'fnr' => '\d+', 'snr' => '\d+'])]
    public function getCadastralUnitWithSection(int $knr, int $gnr, int $bnr, int $fnr, int $snr): JsonResponse
    {
        try {
            $cadastralUnit = $this->matrikkelenhetRepository->findByMatrikkelNummer($knr, $gnr, $bnr, $fnr, $snr);
            if (!$cadastralUnit) {
                return $this->jsonResponse(['error' => 'Matrikkelenhet not found'], 404);
            }
            return $this->jsonResponse([
                'matrikkelnummer' => "$knr-$gnr/$bnr/$fnr/$snr",
                'matrikkelenhet' => $cadastralUnit
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ==================== KODELISTE ENDPOINTS ====================
    // NOTE: Kodeliste endpoints removed - kodelister are now stored as IDs in database
    // For kode descriptions, refer to Matrikkel API documentation or create a local lookup table

    // ==================== SØK ENDPOINT ====================

    /**
     * Generelt søk - søker i database
     */
    #[Route('/sok', name: 'sok', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = (int) $request->query->get('limit', 100);

        if (empty($query)) {
            return $this->jsonResponse(['error' => 'Query parameter "q" is required'], 400);
        }

        $limit = min(max($limit, 1), 1000); // Max 1000 results

        try {
            // Search in different tables
            $addresses = $this->adresseRepository->search($query, min($limit, 100));
            $matrikkelenheter = $this->matrikkelenhetRepository->search([
                'bruksnavn' => $query
            ], min($limit, 100));

            return $this->jsonResponse([
                'query' => $query,
                'source' => 'database',
                'results' => [
                    'adresser' => $addresses,
                    'matrikkelenheter' => $matrikkelenheter
                ],
                'count' => [
                    'adresser' => count($addresses),
                    'matrikkelenheter' => count($matrikkelenheter),
                    'total' => count($addresses) + count($matrikkelenheter)
                ]
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

    /**
     * Get all streets in a municipality
     */
    #[Route('/gate/{kommunenr}', name: 'gate_kommune', methods: ['GET'])]
    public function getVegerByKommune(int $kommunenr): JsonResponse
    {
        $veger = $this->vegRepository->findByKommunenummer($kommunenr);
        return $this->jsonResponse($veger);
    }

    /**
     * Get a specific street by municipality and street code
     */
    #[Route('/gate/{kommunenr}/{adressekode}', name: 'gate_detalj', methods: ['GET'])]
    public function getVeg(int $kommunenr, int $adressekode): JsonResponse
    {
        $veg = $this->vegRepository->findByKommunenummerAndAdressekode($kommunenr, $adressekode);
        if (!$veg) {
            return $this->jsonResponse(null, 404);
        }
        return $this->jsonResponse($veg);
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
            if ($data instanceof \DateTime ) {
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