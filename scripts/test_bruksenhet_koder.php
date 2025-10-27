<?php
/**
 * Test script to check bruksenhet kode structure from API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env file
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\BruksenhetId;

// Create StoreClient
$storeClient = SoapClientFactory::create(StoreClient::class);

// Get one bruksenhet ID from database
$db = new PDO(
    sprintf(
        "pgsql:host=%s;port=%s;dbname=%s",
        $_ENV['DB_HOST'],
        $_ENV['DB_PORT'],
        $_ENV['DB_NAME']
    ),
    $_ENV['DB_USERNAME'],
    $_ENV['DB_PASSWORD']
);

$stmt = $db->prepare("SELECT bruksenhet_id FROM matrikkel_bruksenheter LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "No bruksenheter found in database!\n";
    exit(1);
}

$bruksenhetId = $row['bruksenhet_id'];
echo "Testing bruksenhet ID: $bruksenhetId\n\n";

try {
    $bruksenhet = $storeClient->getObject(new BruksenhetId($bruksenhetId));
    
    echo "=== Bruksenhet Object Structure ===\n";
    echo "Object type: " . get_class($bruksenhet) . "\n\n";
    
    // Check bruksenhetType
    if (isset($bruksenhet->bruksenhetType)) {
        echo "bruksenhetType exists!\n";
        echo "Type: " . gettype($bruksenhet->bruksenhetType) . "\n";
        
        if (is_object($bruksenhet->bruksenhetType)) {
            echo "bruksenhetType properties:\n";
            print_r(get_object_vars($bruksenhet->bruksenhetType));
            
            if (isset($bruksenhet->bruksenhetType->kodeId)) {
                echo "\nkodeId found: " . $bruksenhet->bruksenhetType->kodeId . "\n";
            }
            if (isset($bruksenhet->bruksenhetType->value)) {
                echo "value found: " . $bruksenhet->bruksenhetType->value . "\n";
            }
        } else {
            echo "bruksenhetType value: " . $bruksenhet->bruksenhetType . "\n";
        }
    } else {
        echo "❌ No bruksenhetType property\n";
    }
    
    echo "\n";
    
    // Check etasjeplan
    if (isset($bruksenhet->etasjeplan)) {
        echo "etasjeplan exists!\n";
        echo "Type: " . gettype($bruksenhet->etasjeplan) . "\n";
        
        if (is_object($bruksenhet->etasjeplan)) {
            echo "etasjeplan properties:\n";
            print_r(get_object_vars($bruksenhet->etasjeplan));
            
            if (isset($bruksenhet->etasjeplan->kodeId)) {
                echo "\nkodeId found: " . $bruksenhet->etasjeplan->kodeId . "\n";
            }
            if (isset($bruksenhet->etasjeplan->value)) {
                echo "value found: " . $bruksenhet->etasjeplan->value . "\n";
            }
        } else {
            echo "etasjeplan value: " . $bruksenhet->etasjeplan . "\n";
        }
    } else {
        echo "❌ No etasjeplan property\n";
    }
    
    echo "\n=== All properties ===\n";
    foreach (get_object_vars($bruksenhet) as $key => $value) {
        echo "- $key: " . gettype($value);
        if (is_object($value) && isset($value->kodeId)) {
            echo " (has kodeId: " . $value->kodeId . ")";
        }
        if (is_object($value) && isset($value->value)) {
            echo " (has value: " . $value->value . ")";
        }
        echo "\n";
    }
    
    echo "\n=== Checking specific kode fields ===\n";
    
    // bruksenhetstypeKodeId
    if (isset($bruksenhet->bruksenhetstypeKodeId)) {
        echo "\nbruksenhetstypeKodeId:\n";
        print_r($bruksenhet->bruksenhetstypeKodeId);
    }
    
    // etasjeplanKodeId
    if (isset($bruksenhet->etasjeplanKodeId)) {
        echo "\netasjeplanKodeId:\n";
        print_r($bruksenhet->etasjeplanKodeId);
    }
    
    // kjokkentilgangId
    if (isset($bruksenhet->kjokkentilgangId)) {
        echo "\nkjokkentilgangId:\n";
        print_r($bruksenhet->kjokkentilgangId);
    }
    
    // kostraFunksjonKodeId
    if (isset($bruksenhet->kostraFunksjonKodeId)) {
        echo "\nkostraFunksjonKodeId:\n";
        print_r($bruksenhet->kostraFunksjonKodeId);
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
