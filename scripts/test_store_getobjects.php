<?php
/**
 * Test StoreClient.getObjects() (batch mode) to see if eierforhold is present
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;

// Load environment
if (empty($_ENV['MATRIKKELAPI_LOGIN'])) {
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

echo "\n=== StoreClient.getObjects() Test ===\n\n";

// Create StoreClient
$storeClient = SoapClientFactory::create(StoreClient::class);
echo "[OK] StoreClient created\n\n";

// Test with 3 matrikkelenheter
$ids = [
    new MatrikkelenhetId(255758146),
    new MatrikkelenhetId(255758147),
    new MatrikkelenhetId(255758148),
];

echo "Fetching " . count($ids) . " matrikkelenheter via getObjects()...\n";
$objects = $storeClient->getObjects($ids);
echo "[OK] Received " . count($objects) . " objects\n\n";

// Inspect each object
foreach ($objects as $index => $obj) {
    $id = isset($obj->id) && is_object($obj->id) && isset($obj->id->value) ? $obj->id->value : 'unknown';
    echo "Object #" . ($index + 1) . " (ID: $id):\n";
    
    $hasEierforhold = isset($obj->eierforhold);
    echo "  - Has eierforhold property: " . ($hasEierforhold ? "YES" : "NO") . "\n";
    
    if ($hasEierforhold) {
        $eierforhold = $obj->eierforhold;
        echo "  - eierforhold type: " . (is_object($eierforhold) ? "object" : gettype($eierforhold)) . "\n";
        
        if (is_object($eierforhold) && isset($eierforhold->item)) {
            echo "  - eierforhold.item exists: YES\n";
            $item = $eierforhold->item;
            echo "  - eierforhold.item type: " . (is_object($item) ? "object" : (is_array($item) ? "array[" . count($item) . "]" : gettype($item))) . "\n";
            
            if (is_object($item) && isset($item->eierId)) {
                $eierId = is_object($item->eierId) && isset($item->eierId->value) ? $item->eierId->value : 'N/A';
                echo "  - eierId: $eierId\n";
            } elseif (is_array($item) && count($item) > 0) {
                foreach ($item as $i => $eier) {
                    if (is_object($eier) && isset($eier->eierId)) {
                        $eierId = is_object($eier->eierId) && isset($eier->eierId->value) ? $eier->eierId->value : 'N/A';
                        echo "  - eierId[$i]: $eierId\n";
                    }
                }
            }
        } else {
            echo "  - eierforhold.item: NOT FOUND\n";
        }
    }
    echo "\n";
}

echo "=== SUMMARY ===\n";
$withEierforhold = 0;
foreach ($objects as $obj) {
    if (isset($obj->eierforhold) && isset($obj->eierforhold->item)) {
        $withEierforhold++;
    }
}
echo "Objects with eierforhold: $withEierforhold / " . count($objects) . "\n\n";
