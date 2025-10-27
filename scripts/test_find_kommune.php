<?php
/**
 * Test script for å finne en spesifikk kommune fra Matrikkel API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\KommuneClient;
use Symfony\Component\Dotenv\Dotenv;

// Load .env
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

// Create KommuneClient
$wsdl = 'https://matrikkel.no/matrikkelapi/wsapi/v1/KommuneServiceWS?WSDL';
$options = [
    'trace' => 1,
    'exceptions' => true,
    'cache_wsdl' => WSDL_CACHE_NONE,
];

try {
    $client = new SoapClient($wsdl, $options);
    
    // Opprett MatrikkelContext
    $context = new stdClass();
    $context->klientIdentifikasjon = new stdClass();
    $context->klientIdentifikasjon->klientNavn = $_ENV['MATRIKKEL_API_USERNAME'] ?? 'default';
    $context->klientIdentifikasjon->klientPassord = $_ENV['MATRIKKEL_API_PASSWORD'] ?? '';
    $context->klientIdentifikasjon->klientVersjon = '1.0';
    $context->locale = 'no_NB';
    
    $params = [
        'MatrikkelContext' => $context
    ];
    
    echo "Henter alle kommuner fra Matrikkel API...\n";
    $result = $client->findAlleKommuner($params);
    
    if (!$result || !isset($result->return)) {
        echo "FEIL: Ingen resultat fra API\n";
        exit(1);
    }
    
    $kommuner = is_array($result->return) ? $result->return : [$result->return];
    echo "Antall kommuner returnert: " . count($kommuner) . "\n\n";
    
    // Finn Askøy (4627)
    $askoy = null;
    foreach ($kommuner as $kommune) {
        $knr = (int) ($kommune->kommunenummer ?? 0);
        if ($knr === 4627) {
            $askoy = $kommune;
            break;
        }
    }
    
    if ($askoy) {
        echo "✓ Funnet Askøy kommune:\n";
        echo "  Kommunenummer: " . $askoy->kommunenummer . "\n";
        echo "  Kommunenavn: " . $askoy->kommunenavn . "\n";
        echo "  Fylkesnummer: " . ($askoy->fylkeId ?? 'N/A') . "\n";
    } else {
        echo "✗ Askøy (4627) IKKE funnet i API-resultatet\n\n";
        echo "Viser første 10 kommuner:\n";
        for ($i = 0; $i < min(10, count($kommuner)); $i++) {
            $k = $kommuner[$i];
            echo sprintf("  %d: %s (%s)\n", 
                $k->kommunenummer ?? 0, 
                $k->kommunenavn ?? 'N/A',
                isset($k->fylkeId) ? (is_object($k->fylkeId) ? $k->fylkeId->value ?? 'N/A' : $k->fylkeId) : 'N/A'
            );
        }
    }
    
} catch (SoapFault $e) {
    echo "SOAP FEIL: " . $e->getMessage() . "\n";
    exit(1);
}
