<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\NedlastningClient;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$client = SoapClientFactory::create(NedlastningClient::class);

// Fetch one kommune
$result = $client->findObjekterEtterId(
    0,
    'Kommune',
    null,
    1
);

if (empty($result)) {
    die("No kommune found.\n");
}

$kommune = $result[0];
echo "Kommune Object Structure:\n";
print_r($kommune);

// Check for fylke info
echo "\nChecking for fylke info:\n";
if (isset($kommune->fylkeId)) {
    echo "fylkeId: ";
    print_r($kommune->fylkeId);
}

// Check if we can fetch the Fylke object
if (isset($kommune->fylkeId)) {
    $fylkeId = is_object($kommune->fylkeId) ? $kommune->fylkeId->value : $kommune->fylkeId;
    echo "\nTrying to fetch Fylke object with ID: $fylkeId\n";
    
    // We need StoreClient to fetch objects by ID
    $storeClient = SoapClientFactory::create(\Iaasen\Matrikkel\Client\StoreClient::class);
    try {
        $fylkeObjects = $storeClient->getObjects([new \Iaasen\Matrikkel\Client\FylkeId($fylkeId)]);
        if (!empty($fylkeObjects)) {
            echo "Fylke Object:\n";
            print_r($fylkeObjects[0]);
        }
    } catch (Exception $e) {
        echo "Error fetching Fylke: " . $e->getMessage() . "\n";
    }
}
