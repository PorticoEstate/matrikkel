<?php
/**
 * Debug: Inspect actual structure of Matrikkelenhet objects from StoreClient
 * 
 * This script fetches 5 matrikkelenheter from the database and then
 * retrieves them via StoreClient to see what properties are actually present.
 * 
 * Usage: php scripts/debug_matrikkelenhet_structure.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Service\PdoFactory;

// Load environment from .env file if not already in $_ENV
if (empty($_ENV['DB_USERNAME']) || empty($_ENV['MATRIKKELAPI_LOGIN'])) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
}

echo "\n=== Matrikkelenhet Structure Inspector ===\n\n";

// Get database connection
try {
    $db = PdoFactory::create();
    echo "[OK] Database connected\n";
} catch (Exception $e) {
    echo "[FATAL] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Get 5 matrikkelenheter from database
echo "\n1. Fetching 5 matrikkelenheter from database...\n";
$stmt = $db->prepare("SELECT matrikkelenhet_id, kommunenummer, gardsnummer, bruksnummer 
                      FROM matrikkel_matrikkelenheter 
                      WHERE kommunenummer = 4601 
                      LIMIT 5");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "[ERROR] No matrikkelenheter found in database!\n";
    exit(1);
}

echo "[OK] Found " . count($rows) . " matrikkelenheter:\n";
foreach ($rows as $row) {
    echo "  - {$row['matrikkelenhet_id']} (gnr: {$row['gardsnummer']}, bnr: {$row['bruksnummer']})\n";
}

// Create StoreClient
echo "\n2. Creating StoreClient...\n";
try {
    $factory = new SoapClientFactory(
        $_ENV['MATRIKKELAPI_LOGIN'],
        $_ENV['MATRIKKELAPI_PASSWORD']
    );
    $storeClient = new StoreClient($factory);
    echo "[OK] StoreClient created\n";
} catch (Exception $e) {
    echo "[FATAL] Failed to create StoreClient: " . $e->getMessage() . "\n";
    exit(1);
}

// Fetch objects from StoreService
echo "\n3. Fetching objects from StoreService...\n";
$matrikkelenhetIds = array_map(
    fn($row) => new MatrikkelenhetId($row['matrikkelenhet_id']),
    $rows
);

try {
    $objects = $storeClient->getObjects($matrikkelenhetIds);
    echo "[OK] Received " . count($objects) . " objects\n";
} catch (Exception $e) {
    echo "[FATAL] Failed to fetch objects: " . $e->getMessage() . "\n";
    exit(1);
}

// Inspect structure
echo "\n4. Inspecting object structure...\n";
echo "=" . str_repeat("=", 80) . "\n";

foreach ($objects as $index => $obj) {
    echo "\nObject #" . ($index + 1) . ":\n";
    echo str_repeat("-", 80) . "\n";
    
    // Basic info
    if (isset($obj->matrikkelenhetId)) {
        echo "ID: " . $obj->matrikkelenhetId->value . "\n";
    }
    if (isset($obj->kommunenummer)) {
        echo "Kommune: " . $obj->kommunenummer->value . "\n";
    }
    if (isset($obj->gardsnummer)) {
        echo "Gårdsnummer: " . $obj->gardsnummer . "\n";
    }
    if (isset($obj->bruksnummer)) {
        echo "Bruksnummer: " . $obj->bruksnummer . "\n";
    }
    
    // List ALL properties
    echo "\nAll properties:\n";
    $properties = get_object_vars($obj);
    foreach ($properties as $name => $value) {
        $type = gettype($value);
        if (is_object($value)) {
            $type = get_class($value);
        } elseif (is_array($value)) {
            $type = "array(" . count($value) . ")";
        }
        echo "  - $name: $type\n";
        
        // Show nested structure for eierforhold if present
        if ($name === 'eierforhold') {
            if (is_array($value)) {
                echo "    EIERFORHOLD FOUND (array with " . count($value) . " items):\n";
                foreach ($value as $i => $eier) {
                    echo "      [$i] " . (is_object($eier) ? get_class($eier) : gettype($eier)) . "\n";
                    if (is_object($eier)) {
                        foreach (get_object_vars($eier) as $prop => $val) {
                            echo "        - $prop: " . (is_object($val) ? get_class($val) : gettype($val)) . "\n";
                        }
                    }
                }
            } elseif (is_object($value)) {
                echo "    EIERFORHOLD FOUND (single object):\n";
                foreach (get_object_vars($value) as $prop => $val) {
                    echo "      - $prop: " . (is_object($val) ? get_class($val) : gettype($val)) . "\n";
                }
            } else {
                echo "    EIERFORHOLD: " . var_export($value, true) . "\n";
            }
        }
    }
    
    echo str_repeat("-", 80) . "\n";
}

// Summary
echo "\n=== SUMMARY ===\n";
echo "Total objects inspected: " . count($objects) . "\n";

$hasEierforhold = 0;
foreach ($objects as $obj) {
    if (isset($obj->eierforhold)) {
        $hasEierforhold++;
    }
}

echo "Objects with eierforhold property: $hasEierforhold / " . count($objects) . "\n";

if ($hasEierforhold === 0) {
    echo "\n[WARNING] NO eierforhold property found in any object!\n";
    echo "This explains why PersonImportService finds 0 eierforhold.\n";
    echo "\nPossible reasons:\n";
    echo "  1. Eierforhold is stored separately (not in Matrikkelenhet object)\n";
    echo "  2. API version or account permissions don't include eierforhold\n";
    echo "  3. Need to call different service/operation to get eierforhold\n";
} else {
    echo "\n[OK] Eierforhold data is present in some objects.\n";
}

echo "\n";
