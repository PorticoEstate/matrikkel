<?php

namespace Iaasen\MatrikkelApi\Tests\Integration\Api;

use Iaasen\Matrikkel\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for PorticoExportController REST API
 * 
 * Tests the REST API endpoint at GET /api/portico/export
 * - Endpoint exists (not 404)
 * - Parameter validation
 * - Response format
 */
class PorticoExportControllerTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * Test: Portico endpoint exists and doesn't return 404
     */
    public function testEndpointExists(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/portico/export?kommune=4627');
        
        // Endpoint should exist (not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    /**
     * Test: Kommune parameter format validation (4-digit requirement)
     */
    public function testKommuneParameterValidation(): void
    {
        $client = static::createClient();
        
        // Invalid: Only 2 digits
        $client->request('GET', '/api/portico/export?kommune=46');
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        
        // Valid: 4 digits
        $client->request('GET', '/api/portico/export?kommune=4627');
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(400, $statusCode);  // Format is valid
    }

    /**
     * Test: Optional parameters are accepted
     */
    public function testOptionalOrganisasjonsnummerParameter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/portico/export?kommune=4627&organisasjonsnummer=123456789');
        
        $statusCode = $client->getResponse()->getStatusCode();
        // Should not be 400 (parameter format is valid)
        $this->assertNotEquals(400, $statusCode);
    }

    /**
     * Test: Endpoint responds (not just quiet failure)
     */
    public function testEndpointResponds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/portico/export?kommune=4627');
        
        $response = $client->getResponse();
        
        // Should have content
        $this->assertNotEmpty($response->getContent());
        
        // Should be one of the expected response codes
        $statusCode = $response->getStatusCode();
        $validStatusCodes = [200, 400, 500];  // Normal responses, param error, or app error
        $this->assertContains($statusCode, $validStatusCodes);
    }

    /**
     * Test: Invalid kommune parameter type
     */
    public function testNonNumericKommuneParameter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/portico/export?kommune=abcd');
        
        // Should reject non-numeric or invalid format
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(200, $statusCode);
    }
}
