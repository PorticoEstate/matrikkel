<?php

require __DIR__ . '/../vendor/autoload.php';

// Load .env file
$dotenv = Symfony\Component\Dotenv\Dotenv::class;
if (class_exists($dotenv)) {
    (new $dotenv())->bootEnv(__DIR__ . '/../.env');
}

use Iaasen\Matrikkel\Client\BygningClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;

// Initialize BygningClient
$factory = new \Iaasen\Matrikkel\Client\SoapClientFactory();
$client = $factory->create(\Iaasen\Matrikkel\Client\BygningClient::class);

// Test with a single matrikkelenhet
$matrikkelenhetIds = [255758149]; // From our test data

$matrikkelenhetIdObjects = array_map(
    fn($id) => new MatrikkelenhetId($id),
    $matrikkelenhetIds
);

echo "Testing BygningClient.findByggForMatrikkelenheter()...\n\n";

try {
    $result = $client->findByggForMatrikkelenheter([
        'matrikkelenhetIdList' => ['item' => $matrikkelenhetIdObjects]
    ]);
    
    echo "=== FULL RESPONSE ===\n";
    print_r($result);
    
    if (isset($result->return)) {
        echo "\n=== RETURN STRUCTURE ===\n";
        print_r($result->return);
        
        if (isset($result->return->entry)) {
            echo "\n=== ENTRIES ===\n";
            $entries = is_array($result->return->entry) ? $result->return->entry : [$result->return->entry];
            foreach ($entries as $i => $entry) {
                echo "Entry $i:\n";
                print_r($entry);
            }
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
