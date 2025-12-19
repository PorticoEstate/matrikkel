#!/usr/bin/env php
<?php
/**
 * Debug script: Check bruksenheter for specific bygning
 * 
 * Usage: php scripts/debug_bruksenhet_bygning.php 257159547
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\BruksenhetClient;
use Iaasen\Matrikkel\Client\BygningId;
use Iaasen\Matrikkel\Client\SoapClientFactory;

// Get bygning ID from command line
$bygningId = $argv[1] ?? null;
if (!$bygningId) {
    echo "Usage: php scripts/debug_bruksenhet_bygning.php <bygning_id>\n";
    exit(1);
}

$bygningId = (int) $bygningId;

echo "=== Debug: Bruksenheter for bygning $bygningId ===\n\n";

// Initialize SOAP client
$factory = new SoapClientFactory();
$bruksenhetClient = $factory->create(BruksenhetClient::class);

echo "Step 1: Calling BruksenhetClient->findBruksenheterForByggList()...\n";

try {
    $bygningIdObject = new BygningId($bygningId);
    
    $result = $bruksenhetClient->findBruksenheterForByggList([
        'byggIds' => ['item' => [$bygningIdObject]]
    ]);
    
    echo "✓ API call successful\n\n";
    
    // Parse response
    $bruksenhetIds = [];
    
    if (isset($result->return) && isset($result->return->entry)) {
        $entries = is_array($result->return->entry) 
            ? $result->return->entry 
            : [$result->return->entry];
        
        echo "Number of entries in response: " . count($entries) . "\n";
        
        foreach ($entries as $entry) {
            $returnedBygningId = $entry->key->value ?? null;
            echo "\nEntry for bygning: $returnedBygningId\n";
            
            if ($returnedBygningId && isset($entry->value) && isset($entry->value->item)) {
                $bruksenhetIdObjects = is_array($entry->value->item)
                    ? $entry->value->item
                    : [$entry->value->item];
                
                echo "  Found " . count($bruksenhetIdObjects) . " bruksenhet IDs from API:\n";
                
                foreach ($bruksenhetIdObjects as $idx => $bruksenhetIdObj) {
                    $bruksenhetId = $bruksenhetIdObj->value ?? null;
                    if ($bruksenhetId) {
                        $bruksenhetIds[] = $bruksenhetId;
                        echo "  [$idx] Bruksenhet ID: $bruksenhetId\n";
                    }
                }
            }
        }
    } else {
        echo "⚠ No entries in response\n";
        echo "Response structure:\n";
        print_r($result);
    }
    
    echo "\n=== Summary ===\n";
    echo "Total bruksenheter from API: " . count($bruksenhetIds) . "\n";
    
    if (empty($bruksenhetIds)) {
        echo "⚠ No bruksenheter found!\n";
        exit(0);
    }
    
    // Check database
    echo "\nStep 2: Checking database...\n";
    
    $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
    $dbPort = $_ENV['DB_PORT'] ?? '5432';
    $dbName = $_ENV['DB_NAME'] ?? 'matrikkel';
    $dbUser = $_ENV['DB_USERNAME'] ?? 'matrikkel';
    $dbPass = $_ENV['DB_PASSWORD'] ?? '';
    
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $placeholders = implode(',', array_fill(0, count($bruksenhetIds), '?'));
    $stmt = $db->prepare(
        "SELECT bruksenhet_id, bygning_id, matrikkelenhet_id, lopenummer 
         FROM matrikkel_bruksenheter 
         WHERE bruksenhet_id IN ($placeholders)"
    );
    $stmt->execute($bruksenhetIds);
    $dbBruksenheter = $stmt->fetchAll();
    
    $dbBruksenhetIds = array_column($dbBruksenheter, 'bruksenhet_id');
    
    echo "Bruksenheter in database: " . count($dbBruksenhetIds) . "\n";
    
    if (count($dbBruksenhetIds) < count($bruksenhetIds)) {
        $missing = array_diff($bruksenhetIds, $dbBruksenhetIds);
        echo "\n⚠ MISSING from database (" . count($missing) . "):\n";
        foreach ($missing as $missingId) {
            echo "  - Bruksenhet ID: $missingId\n";
        }
    } else {
        echo "✓ All bruksenheter exist in database\n";
    }
    
    echo "\nStep 3: Database records for this bygning:\n";
    $stmt = $db->prepare(
        "SELECT bruksenhet_id, bygning_id, matrikkelenhet_id, lopenummer, uuid
         FROM matrikkel_bruksenheter 
         WHERE bygning_id = ?
         ORDER BY bruksenhet_id"
    );
    $stmt->execute([$bygningId]);
    $bygningBruksenheter = $stmt->fetchAll();
    
    echo "Total in DB for bygning $bygningId: " . count($bygningBruksenheter) . "\n";
    foreach ($bygningBruksenheter as $row) {
        echo sprintf(
            "  - ID: %d, matrikkelenhet: %d, lopenr: %s, uuid: %s\n",
            $row['bruksenhet_id'],
            $row['matrikkelenhet_id'],
            $row['lopenummer'] ?? 'null',
            $row['uuid'] ?? 'null'
        );
    }
    
    echo "\n=== Analysis ===\n";
    echo "API returns: " . count($bruksenhetIds) . " bruksenheter\n";
    echo "Database has: " . count($bygningBruksenheter) . " bruksenheter for this bygning\n";
    
    if (count($bruksenhetIds) > count($bygningBruksenheter)) {
        echo "❌ DISCREPANCY: " . (count($bruksenhetIds) - count($bygningBruksenheter)) . " bruksenheter missing from database\n";
    } elseif (count($bruksenhetIds) < count($bygningBruksenheter)) {
        echo "⚠ Database has MORE than API returned (possible duplicates or old data)\n";
    } else {
        echo "✓ Counts match\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
