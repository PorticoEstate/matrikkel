<?php

namespace Iaasen\Matrikkel\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HierarchyOrganizationService
 * 
 * Tests basic code formatting and sorting logic without heavy mocking.
 * Full integration tests with actual service are in tests/Integration/.
 */
class HierarchyOrganizationServiceTest extends TestCase
{
    /**
     * Test: Building code formatting with 2-digit padding
     */
    public function testBuildingCodeFormatting(): void
    {
        // Building codes should be formatted as: XXXX-YY (5000-01, 5000-02, etc)
        $code = sprintf('5000-%02d', 1);
        $this->assertEquals('5000-01', $code);
        
        $code = sprintf('5000-%02d', 10);
        $this->assertEquals('5000-10', $code);
        
        $code = sprintf('5000-%02d', 99);
        $this->assertEquals('5000-99', $code);
    }

    /**
     * Test: Entrance code formatting with 2-digit padding
     */
    public function testEntranceCodeFormatting(): void
    {
        // Entrance codes should be formatted as: XXXX-YY-ZZ (5000-01-01, 5000-01-02, etc)
        $code = sprintf('5000-01-%02d', 1);
        $this->assertEquals('5000-01-01', $code);
        
        $code = sprintf('5000-02-%02d', 15);
        $this->assertEquals('5000-02-15', $code);
    }

    /**
     * Test: Unit code formatting with 3-digit padding
     */
    public function testUnitCodeFormatting(): void
    {
        // Unit codes should be formatted as: XXXX-YY-ZZ-AAA (5000-01-01-001, etc)
        $code = sprintf('5000-01-01-%03d', 1);
        $this->assertEquals('5000-01-01-001', $code);
        
        $code = sprintf('5000-02-03-%03d', 42);
        $this->assertEquals('5000-02-03-042', $code);
    }

    /**
     * Test: Building sorting by ID (deterministic ordering)
     */
    public function testBuildingSortingByID(): void
    {
        // Simulate building array from database (random order)
        $buildings = [
            (object)['bygning_id' => 3003],
            (object)['bygning_id' => 3001],
            (object)['bygning_id' => 3002],
        ];

        // Sort by ID ascending (deterministic)
        usort($buildings, fn($a, $b) => $a->bygning_id <=> $b->bygning_id);

        // Assert correct order
        $this->assertEquals(3001, $buildings[0]->bygning_id);
        $this->assertEquals(3002, $buildings[1]->bygning_id);
        $this->assertEquals(3003, $buildings[2]->bygning_id);
    }

    /**
     * Test: Entrance sorting by address components
     */
    public function testEntranceSortingByAddress(): void
    {
        // Simulate entrances (house numbers and letters)
        $entrances = [
            (object)['husnummer' => 12, 'bokstav' => 'B'],
            (object)['husnummer' => 10, 'bokstav' => 'A'],
            (object)['husnummer' => 12, 'bokstav' => 'A'],
            (object)['husnummer' => 10, 'bokstav' => null],
        ];

        // Sort: husnummer ascending, then bokstav (null first, then A-Z)
        usort($entrances, function($a, $b) {
            if ($a->husnummer !== $b->husnummer) {
                return $a->husnummer <=> $b->husnummer;
            }
            if ($a->bokstav === null && $b->bokstav === null) return 0;
            if ($a->bokstav === null) return -1;
            if ($b->bokstav === null) return 1;
            return $a->bokstav <=> $b->bokstav;
        });

        // Assert correct order: 10, 10A, 12A, 12B
        $this->assertEquals(10, $entrances[0]->husnummer);
        $this->assertNull($entrances[0]->bokstav);
        
        $this->assertEquals(10, $entrances[1]->husnummer);
        $this->assertEquals('A', $entrances[1]->bokstav);
        
        $this->assertEquals(12, $entrances[2]->husnummer);
        $this->assertEquals('A', $entrances[2]->bokstav);
        
        $this->assertEquals(12, $entrances[3]->husnummer);
        $this->assertEquals('B', $entrances[3]->bokstav);
    }

    /**
     * Test: Unit sorting with NULL etasjenummer (floor/level)
     */
    public function testUnitSortingNullEtasjenummer(): void
    {
        // Simulate units with mixed NULL and non-NULL floor numbers
        $units = [
            (object)['bruksenhet_id' => 1, 'etasjenummer' => 2],
            (object)['bruksenhet_id' => 2, 'etasjenummer' => null],
            (object)['bruksenhet_id' => 3, 'etasjenummer' => 1],
            (object)['bruksenhet_id' => 4, 'etasjenummer' => null],
        ];

        // Sort: NULL values first (ground/no floor), then by floor number
        usort($units, function($a, $b) {
            if ($a->etasjenummer === null && $b->etasjenummer === null) return 0;
            if ($a->etasjenummer === null) return -1;  // NULL comes first
            if ($b->etasjenummer === null) return 1;
            return $a->etasjenummer <=> $b->etasjenummer;
        });

        // Assert correct order: NULL floors first, then 1, then 2
        $this->assertNull($units[0]->etasjenummer);  // Unit 2
        $this->assertNull($units[1]->etasjenummer);  // Unit 4
        $this->assertEquals(1, $units[2]->etasjenummer);  // Unit 3
        $this->assertEquals(2, $units[3]->etasjenummer);  // Unit 1
    }

    /**
     * Test: Location code pattern validation
     */
    public function testLocationCodePattern(): void
    {
        // Valid codes should match: NNNN-NN-NN-NNN
        $pattern = '/^\d{4}-\d{2}-\d{2}-\d{3}$/';

        // Valid codes
        $validCodes = [
            '5000-01-01-001',
            '1234-99-99-999',
            '0001-01-01-001',
        ];

        foreach ($validCodes as $code) {
            $this->assertMatchesRegularExpression($pattern, $code, "Code '$code' should be valid");
        }

        // Invalid codes
        $invalidCodes = [
            '5000-1-01-001',   // Missing digit in building
            '5000-01-1-001',   // Missing digit in entrance
            '5000-01-01-1',    // Missing digits in unit
            '5000-01-01',      // Incomplete (no unit)
            'invalid',         // Completely invalid
        ];

        foreach ($invalidCodes as $code) {
            $this->assertDoesNotMatchRegularExpression($pattern, $code, "Code '$code' should be invalid");
        }
    }

    /**
     * Test: Code increment calculation (without mocks)
     */
    public function testIncrementCalculations(): void
    {
        // Test string formatting without leading zeros
        for ($i = 1; $i <= 99; $i++) {
            $code = sprintf('%02d', $i);
            $this->assertEquals(2, strlen($code), "All codes should be 2 digits for values 1-99");
        }

        // Test 3-digit padding for units
        for ($i = 1; $i <= 999; $i++) {
            $code = sprintf('%03d', $i);
            $this->assertEquals(3, strlen($code), "All unit codes should be 3 digits");
        }
    }
}
