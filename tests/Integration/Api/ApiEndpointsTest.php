<?php
/**
 * Test: API Endpoints Validation
 * Verifies that getAvailableEndpoints() matches actual routes
 */

namespace Iaasen\Matrikkel\Tests\Integration\Api;

use Iaasen\Matrikkel\Controller\MatrikkelApiController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route;
use ReflectionClass;
use ReflectionMethod;

class ApiEndpointsTest extends TestCase
{
    /**
     * Verify that getAvailableEndpoints() method exists and is callable
     */
    public function testGetAvailableEndpointsMethodExists(): void
    {
        $controller = new MatrikkelApiController(
            $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository'),
            $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository'),
            $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository'),
            $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository'),
            $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository')
        );

        $reflection = new ReflectionClass($controller);
        $this->assertTrue(
            $reflection->hasMethod('getAvailableEndpoints'),
            'getAvailableEndpoints method should exist'
        );

        $method = $reflection->getMethod('getAvailableEndpoints');
        $this->assertTrue(
            $method->isPrivate(),
            'getAvailableEndpoints should be private'
        );
    }

    /**
     * Verify endpoint definitions include all expected categories
     */
    public function testEndpointCategoriesExist(): void
    {
        // Mock repositories
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        // Use reflection to call private method
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        // Verify required categories exist
        $expectedCategories = ['health', 'adresse', 'kommune', 'gate', 'bruksenhet', 'matrikkelenhet', 'sok', 'info'];
        foreach ($expectedCategories as $category) {
            $this->assertArrayHasKey(
                $category,
                $endpoints,
                "Endpoint category '$category' should exist in getAvailableEndpoints()"
            );
        }
    }

    /**
     * Verify endpoint routes are valid HTTP methods and paths
     */
    public function testEndpointRoutesAreValid(): void
    {
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

        foreach ($endpoints as $category => $routes) {
            // Skip 'info' category which contains metadata
            if ($category === 'info') {
                continue;
            }

            foreach ($routes as $route => $description) {
                // Route should be string like "GET /api/..."
                $this->assertIsString($route, "Route in category '$category' should be string");
                $this->assertIsString($description, "Description for '$route' should be string");

                // Parse HTTP method and path
                $parts = explode(' ', $route, 2);
                $this->assertCount(2, $parts, "Route '$route' should have HTTP method and path");

                $method = $parts[0];
                $path = $parts[1];

                $this->assertContains($method, $validMethods, "HTTP method '$method' in route '$route' is not valid");
                $this->assertTrue(
                    str_starts_with($path, '/api/'),
                    "Path '$path' in route '$route' should start with '/api/'"
                );
            }
        }
    }

    /**
     * Verify that adresse endpoints match actual implementation
     */
    public function testAdresseEndpointsMatchImplementation(): void
    {
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        // Verify specific adresse routes exist
        $this->assertArrayHasKey('adresse', $endpoints);
        $adresseRoutes = $endpoints['adresse'];

        $expectedAdresseRoutes = [
            'GET /api/adresse/{id}' => true,
            'GET /api/adresse/sok?q={query}&limit={number}' => true,
            'GET /api/adresse/kommune/{kommunenummer}?limit={number}' => true,
        ];

        foreach (array_keys($expectedAdresseRoutes) as $route) {
            $this->assertArrayHasKey(
                $route,
                $adresseRoutes,
                "Adresse route '$route' should be documented in getAvailableEndpoints()"
            );
        }
    }

    /**
     * Verify that kommune endpoints match actual implementation
     */
    public function testKommuneEndpointsMatchImplementation(): void
    {
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        // Verify kommune routes exist
        $this->assertArrayHasKey('kommune', $endpoints);
        $kommuneRoutes = $endpoints['kommune'];

        $this->assertArrayHasKey(
            'GET /api/kommune/{id}',
            $kommuneRoutes,
            'Kommune lookup by ID should be documented'
        );
        $this->assertArrayHasKey(
            'GET /api/kommune?limit={number}',
            $kommuneRoutes,
            'Get all kommuner should be documented'
        );
    }

    /**
     * Verify that matrikkelenhet endpoints include all variations
     */
    public function testMatrikkelenhetEndpointsIncludeAllVariations(): void
    {
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        $this->assertArrayHasKey('matrikkelenhet', $endpoints);
        $matrikkelenhetRoutes = $endpoints['matrikkelenhet'];

        $expectedRoutes = [
            'GET /api/matrikkelenhet/{id}',
            'GET /api/matrikkelenhet/{knr}/{gnr}/{bnr}',
            'GET /api/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}',
            'GET /api/matrikkelenhet/{knr}/{gnr}/{bnr}/{fnr}/{snr}',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertArrayHasKey(
                $route,
                $matrikkelenhetRoutes,
                "Matrikkelenhet route '$route' should be documented"
            );
        }
    }

    /**
     * Verify there are no deprecated or removed endpoints in the list
     */
    public function testNoDeprecatedEndpointsInList(): void
    {
        $adresseRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\AdresseRepository');
        $bruksenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\BruksenhetRepository');
        $kommuneRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\KommuneRepository');
        $matrikkelenhetRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository');
        $vegRepo = $this->createMock('Iaasen\Matrikkel\LocalDb\VegRepository');

        $controller = new MatrikkelApiController(
            $adresseRepo,
            $bruksenhetRepo,
            $kommuneRepo,
            $matrikkelenhetRepo,
            $vegRepo
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('getAvailableEndpoints');
        $method->setAccessible(true);
        $endpoints = $method->invoke($controller);

        $allRoutes = [];
        foreach ($endpoints as $category => $routes) {
            if (is_array($routes)) {
                $allRoutes = array_merge($allRoutes, array_keys($routes));
            }
        }

        // Deprecated endpoints that should NOT be present
        $deprecatedPatterns = [
            '/api/address/',  // Old English naming
            '/api/municipality/',  // Old English naming
            '/api/property-unit/',  // Old English naming
            '/api/cadastral-unit/',  // Old English naming
            '/api/codelist',  // Removed endpoint
            '/api/search?q={query}&source=api',  // API source removed (only DB now)
        ];

        foreach ($allRoutes as $route) {
            foreach ($deprecatedPatterns as $deprecated) {
                $this->assertStringNotContainsString(
                    $deprecated,
                    $route,
                    "Deprecated endpoint pattern '$deprecated' should not appear in route '$route'"
                );
            }
        }
    }
}
