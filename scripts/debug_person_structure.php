<?php
/**
 * Debug script to examine Person API response structure
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\PersonId;

// Load environment from .env file manually
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

// Initialize SOAP client
$storeClient = new StoreClient(
    getenv('MATRIKKEL_STORE_WSDL'),
    getenv('MATRIKKEL_USERNAME'),
    getenv('MATRIKKEL_PASSWORD')
);

// Hent en person fra database
$db = new PDO(
    'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD')
);

$stmt = $db->query("SELECT matrikkel_person_id FROM matrikkel_personer LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "Ingen personer funnet i database!\n";
    exit(1);
}

$personId = $row['matrikkel_person_id'];
echo "Henter person med ID: $personId\n\n";

// Hent person fra API
$personIdObj = new PersonId($personId);
$personer = $storeClient->getObjects([$personIdObj]);

if (empty($personer)) {
    echo "Kunne ikke hente person fra API!\n";
    exit(1);
}

$person = $personer[0];

echo "=== PERSON STRUKTUR ===\n\n";

// List all top-level properties
echo "Top-level properties:\n";
foreach (get_object_vars($person) as $key => $value) {
    $type = is_object($value) ? get_class($value) : gettype($value);
    echo "- $key: $type\n";
}

echo "\n=== POSTADRESSE STRUKTUR ===\n";
if (isset($person->postadresse)) {
    print_r($person->postadresse);
} else {
    echo "postadresse: NOT SET\n";
}

echo "\n=== FULL PERSON OBJECT ===\n";
print_r($person);
