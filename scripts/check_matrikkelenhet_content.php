<?php
/**
 * Debug: Check actual matrikkelenhet content from database and API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Service\PdoFactory;

// Load environment from bash environment
$_ENV['MATRIKKELAPI_LOGIN'] = getenv('MATRIKKELAPI_LOGIN');
$_ENV['MATRIKKELAPI_PASSWORD'] = getenv('MATRIKKELAPI_PASSWORD');

// Create clients
$storeClient = SoapClientFactory::create(StoreClient::class);
$db = PdoFactory::create();

// Get 5 matrikkelenheter from database
$stmt = $db->prepare("SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = 4601 LIMIT 5");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Testing " . count($rows) . " matrikkelenheter\n\n";

foreach ($rows as $row) {
    $matrikkelenhetId = $row['matrikkelenhet_id'];
    echo "=== Matrikkelenhet ID: $matrikkelenhetId ===\n";
    
    try {
        $obj = $storeClient->getObject(new MatrikkelenhetId($matrikkelenhetId));
        
        // Check for bygningIds
        if (isset($obj->bygningIds) && isset($obj->bygningIds->item)) {
            $items = is_array($obj->bygningIds->item) ? $obj->bygningIds->item : [$obj->bygningIds->item];
            echo "✅ Has " . count($items) . " bygning(er):\n";
            foreach ($items as $item) {
                echo "  - Bygning ID: " . ($item->value ?? 'N/A') . "\n";
            }
        } else {
            echo "❌ No bygningIds field\n";
        }
        
        // Check for adresse
        if (isset($obj->adresse)) {
            echo "✅ Has adresse\n";
        }
        
        // Check teigMedPunkt field
        if (isset($obj->teigMedPunkt)) {
            echo "✅ Has teigMedPunkt (geometry)\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}
