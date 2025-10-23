<?php
/**
 * Debug script to check matrikkelenhet structure for bruksenheter
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Service\PdoFactory;

// Create clients directly
$storeClient = SoapClientFactory::create(StoreClient::class);
$db = PdoFactory::create();

// Get first 3 matrikkelenhet IDs from database
$stmt = $db->prepare("SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = 4601 LIMIT 3");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Testing " . count($rows) . " matrikkelenheter for bruksenheter...\n\n";

foreach ($rows as $row) {
    $matrikkelenhetId = $row['matrikkelenhet_id'];
    echo "=== Matrikkelenhet ID: $matrikkelenhetId ===\n";
    
    try {
        $obj = $storeClient->getObject(new MatrikkelenhetId($matrikkelenhetId));
        
        echo "Object type: " . get_class($obj) . "\n";
        echo "Object properties:\n";
        print_r(array_keys(get_object_vars($obj)));
        
        // Check for bruksenhet field
        if (isset($obj->bruksenhet)) {
            echo "\n✅ Has 'bruksenhet' property!\n";
            echo "bruksenhet type: " . gettype($obj->bruksenhet) . "\n";
            
            if (is_object($obj->bruksenhet)) {
                echo "bruksenhet properties:\n";
                print_r(get_object_vars($obj->bruksenhet));
            } else {
                echo "bruksenhet value:\n";
                print_r($obj->bruksenhet);
            }
        } else {
            echo "\n❌ No 'bruksenhet' property\n";
        }
        
        // Check for bygning field
        if (isset($obj->bygning)) {
            echo "\n✅ Has 'bygning' property!\n";
            echo "bygning type: " . gettype($obj->bygning) . "\n";
        }
        
        // Check for adresse field
        if (isset($obj->adresse)) {
            echo "\n✅ Has 'adresse' property!\n";
            echo "adresse type: " . gettype($obj->adresse) . "\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("-", 80) . "\n\n";
}
