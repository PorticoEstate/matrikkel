<?php
/**
 * Debug script: Inspect adresse data returned from the remote API and compare it with
 * what is stored locally in matrikkel_adresser / matrikkel_matrikkelenhet_adresse.
 *
 * Usage
 *   php scripts/debug_adresse_import.php --matrikkelenhet=255917192 [--kommune=4601]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\AdresseClient;
use Iaasen\Matrikkel\Client\AdresseId;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Service\PdoFactory;

// Load .env if credentials are not preloaded
if (empty($_ENV['MATRIKKELAPI_LOGIN']) || empty($_ENV['DB_USERNAME'])) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $_ENV[$key] = $value;
        }
    }
}

$options = getopt('', ['matrikkelenhet:', 'kommune::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/debug_adresse_import.php --matrikkelenhet=<id> [--kommune=<nr>]" . PHP_EOL;
    exit(0);
}

if (empty($options['matrikkelenhet'])) {
    fwrite(STDERR, "Error: --matrikkelenhet is required" . PHP_EOL);
    exit(1);
}

$matrikkelenhetId = (int) $options['matrikkelenhet'];
$kommunenummer = isset($options['kommune']) ? (int) $options['kommune'] : null;

echo PHP_EOL . "=== Adresse Import Debugger ===" . PHP_EOL;
echo "Matrikkelenhet ID : {$matrikkelenhetId}" . PHP_EOL;
if ($kommunenummer) {
    echo "Kommunenummer     : {$kommunenummer}" . PHP_EOL;
}

echo PHP_EOL . "1) Creating clients..." . PHP_EOL;
$adresseClient = SoapClientFactory::create(AdresseClient::class);
$storeClient = SoapClientFactory::create(StoreClient::class);
$db = PdoFactory::create();

echo "[OK] Clients ready" . PHP_EOL;

echo PHP_EOL . "2) Fetching adresse IDs from remote API..." . PHP_EOL;
$payload = [
    'matrikkelenhetIds' => ['item' => [new MatrikkelenhetId($matrikkelenhetId)]]
];
$response = $adresseClient->findAdresserForMatrikkelenheter($payload);

$adresseIds = [];
if (isset($response->return) && isset($response->return->entry)) {
    $entries = is_array($response->return->entry)
        ? $response->return->entry
        : [$response->return->entry];

    foreach ($entries as $entry) {
        $key = $entry->key->value ?? null;
        if (!$key || (int)$key !== $matrikkelenhetId) {
            continue;
        }
        if (isset($entry->value) && isset($entry->value->item)) {
            $items = is_array($entry->value->item) ? $entry->value->item : [$entry->value->item];
            foreach ($items as $item) {
                if (isset($item->value)) {
                    $adresseIds[] = (int) $item->value;
                }
            }
        }
    }
}

$adresseIds = array_values(array_unique($adresseIds));
echo "Remote API returned " . count($adresseIds) . " adresse-ID(s)" . PHP_EOL;
if (empty($adresseIds)) {
    echo "[ERROR] No adresser found for the supplied matrikkelenhet" . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "3) Fetching adresse objects from StoreService..." . PHP_EOL;
$adresseIdObjects = array_map(fn($id) => new AdresseId($id), $adresseIds);
$adresser = $storeClient->getObjects($adresseIdObjects);
if (!is_array($adresser)) {
    $adresser = [$adresser];
}

echo "StoreService returned " . count($adresser) . " objects" . PHP_EOL;
foreach ($adresser as $adresse) {
    $id = $adresse->id->value ?? 'unknown';
    $type = (isset($adresse->vegId) || isset($adresse->nummer)) ? 'VEGADRESSE' : 'MATRIKKELADRESSE';
    $vegId = isset($adresse->vegId) ? $adresse->vegId->value : '-';
    $nummer = $adresse->nummer ?? '-';
    $bokstav = $adresse->bokstav ?? '-';
    $navn = $adresse->adressenavn ?? ($adresse->navn ?? '-');
    echo sprintf("  • %s | %s | vegId=%s | %s%s", $id, $type, $vegId, $nummer, $bokstav ? $bokstav : '') . PHP_EOL;
    if ($type === 'VEGADRESSE') {
        echo "      Navn: {$navn}" . PHP_EOL;
    }
}

echo PHP_EOL . "4) Comparing with local database..." . PHP_EOL;
$dbStmt = $db->prepare("SELECT mma.adresse_id, v.adressenavn, va.nummer, va.bokstav FROM matrikkel_matrikkelenhet_adresse mma JOIN matrikkel_adresser a ON mma.adresse_id = a.adresse_id LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id WHERE mma.matrikkelenhet_id = :id ORDER BY v.adressenavn, va.nummer, va.bokstav");
$dbStmt->execute(['id' => $matrikkelenhetId]);
$dbRows = $dbStmt->fetchAll(PDO::FETCH_ASSOC);
$dbAdresseIds = array_map(fn($row) => (int)$row['adresse_id'], $dbRows);

echo "Local DB has " . count($dbAdresseIds) . " adresse relation(s)" . PHP_EOL;
if (!empty($dbRows)) {
    foreach (array_slice($dbRows, 0, 10) as $row) {
        $navn = $row['adressenavn'] ?? '-';
        $num = $row['nummer'] ?? '-';
        $bokstav = $row['bokstav'] ?? '';
        echo sprintf("  • %s | %s %s%s", $row['adresse_id'], $navn, $num, $bokstav) . PHP_EOL;
    }
    if (count($dbRows) > 10) {
        echo "  ..." . PHP_EOL;
    }
}

$missingInDb = array_diff($adresseIds, $dbAdresseIds);
$extraInDb = array_diff($dbAdresseIds, $adresseIds);

echo PHP_EOL;
if (empty($missingInDb) && empty($extraInDb)) {
    echo "[OK] Remote API and local database contain the same adresse IDs for this matrikkelenhet." . PHP_EOL;
} else {
    if (!empty($missingInDb)) {
        echo "[WARN] Missing in DB: " . implode(', ', $missingInDb) . PHP_EOL;
    }
    if (!empty($extraInDb)) {
        echo "[WARN] Extra in DB: " . implode(', ', $extraInDb) . PHP_EOL;
    }
}

echo PHP_EOL . "=== Done ===" . PHP_EOL;
