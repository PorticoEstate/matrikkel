<?php
require __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\PersonId;

if (!isset($argv[1])) {
    echo "Usage: php scripts/debug_api_matrikkelenhet.php <personId>\n";
    exit(1);
}
$personId = (int)$argv[1];

$personClient = SoapClientFactory::create(Iaasen\Matrikkel\Client\PersonClient::class);
$matrikkelenhetClient = SoapClientFactory::create(Iaasen\Matrikkel\Client\MatrikkelenhetClient::class);

$pid = new Iaasen\Matrikkel\Client\PersonId($personId);

try {
    $res = $matrikkelenhetClient->findMatrikkelenheterForOrganisasjon($pid);
    echo "Response type: " . gettype($res) . "\n";
    if (is_array($res)) {
        echo "Count: " . count($res) . "\n";
        foreach ($res as $r) {
            print_r($r);
        }
    } else {
        print_r($res);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
