<?php
/**
 * Test script to check bygning kode structure from API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env file
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\BygningId;

// Create StoreClient
$storeClient = SoapClientFactory::create(StoreClient::class);

// Get one bygning ID from database
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

$stmt = $db->prepare("SELECT bygning_id FROM matrikkel_bygninger LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "No bygninger found in database!\n";
    exit(1);
}

$bygningId = $row['bygning_id'];
echo "Testing bygning ID: $bygningId\n\n";

try {
    $bygning = $storeClient->getObject(new BygningId($bygningId));
    
    echo "=== Bygning Object Structure ===\n";
    echo "Object type: " . get_class($bygning) . "\n\n";
    
    echo "=== All properties ===\n";
    foreach (get_object_vars($bygning) as $key => $value) {
        echo "- $key: " . gettype($value);
        if (is_object($value) && isset($value->value)) {
            echo " (has value: " . $value->value . ")";
        }
        if (is_object($value) && isset($value->kodeId)) {
            echo " (has kodeId: " . $value->kodeId . ")";
        }
        echo "\n";
    }
    
    echo "\n=== Checking specific kode fields ===\n";
    
    // Check representasjonspunkt
    if (isset($bygning->representasjonspunkt)) {
        echo "\nrepresentasjonspunkt object:\n";
        print_r($bygning->representasjonspunkt);
    }
    
    // Check bruksareal fields
    if (isset($bygning->bruksarealTotalt)) {
        echo "\n✅ bruksarealTotalt: " . $bygning->bruksarealTotalt . "\n";
    }
    if (isset($bygning->bebygdAreal)) {
        echo "✅ bebygdAreal: " . $bygning->bebygdAreal . "\n";
    }
    
    // Check etasjer object
    if (isset($bygning->etasjer)) {
        echo "\netasjer object:\n";
        print_r($bygning->etasjer);
    }
    
    // Check for byggeaar variations
    $byggeaarFields = ['byggeaar', 'byggeAar', 'aarBygget', 'byggear'];
    foreach ($byggeaarFields as $field) {
        if (isset($bygning->$field)) {
            echo "\nFound $field: " . $bygning->$field . "\n";
        }
    }
    
    // Check etasjedata
    if (isset($bygning->etasjedata)) {
        echo "\netasjedata object:\n";
        print_r($bygning->etasjedata);
    }
    
    // Check kommunalTilleggsdel - this contains antallEtasjer according to WSDL
    if (isset($bygning->kommunalTilleggsdel)) {
        echo "\nkommunalTilleggsdel object:\n";
        print_r($bygning->kommunalTilleggsdel);
        
        if (isset($bygning->kommunalTilleggsdel->antallEtasjer)) {
            echo "\n✅ antallEtasjer found in kommunalTilleggsdel: " . $bygning->kommunalTilleggsdel->antallEtasjer . "\n";
        }
    }
    
    // Check bygningsstatusHistorikker - this contains status history with dates
    if (isset($bygning->bygningsstatusHistorikker)) {
        echo "\n=== bygningsstatusHistorikker ===\n";
        print_r($bygning->bygningsstatusHistorikker);
        
        if (isset($bygning->bygningsstatusHistorikker->item)) {
            $items = is_array($bygning->bygningsstatusHistorikker->item) 
                ? $bygning->bygningsstatusHistorikker->item 
                : [$bygning->bygningsstatusHistorikker->item];
            
            echo "\nFound " . count($items) . " status history entries:\n";
            foreach ($items as $i => $item) {
                echo "\nEntry $i:\n";
                if (isset($item->bygningsstatusKodeId)) {
                    echo "  - bygningsstatusKodeId: " . $item->bygningsstatusKodeId->value . "\n";
                }
                if (isset($item->dato)) {
                    echo "  - dato: ";
                    print_r($item->dato);
                }
                if (isset($item->registrertDato)) {
                    echo "  - registrertDato: ";
                    print_r($item->registrertDato);
                }
            }
        }
    }
    
    // Check for bygningstype related fields
    $kodeFields = [
        'bygningstypeKodeId',
        'bygningstype',
        'bygningsstatus',
        'bygningsstatusKodeId',
        'avlopKodeId',
        'avlop',
        'vannforsyningKodeId',
        'vannforsyning',
        'oppvarmingKodeIdListe',
        'oppvarming',
        'energikildeKodeIdListe',
        'energikilde',
        'naringsgruppeKodeId',
        'naringsgruppe',
        'opprinnelseKodeId',
        'opprinnelse'
    ];
    
    foreach ($kodeFields as $field) {
        if (isset($bygning->$field)) {
            echo "\n$field:\n";
            print_r($bygning->$field);
        }
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
