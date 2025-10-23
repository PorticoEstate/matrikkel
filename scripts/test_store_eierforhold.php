<?php
/**
 * Debug: Test StoreClient.getObject() for single matrikkelenhet to see if eierforhold is present
 * 
 * Usage: php scripts/test_store_eierforhold.php 255758146
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Client\SoapClientFactory;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Service\PdoFactory;

// Load environment
if (empty($_ENV['MATRIKKELAPI_LOGIN'])) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
}

$matrikkelenhetId = $argv[1] ?? '255758146';

echo "\n=== StoreClient Eierforhold Test ===\n\n";
echo "Testing matrikkelenhet_id: $matrikkelenhetId\n\n";

// Create StoreClient using factory
try {
    $storeClient = SoapClientFactory::create(StoreClient::class);
    echo "[OK] StoreClient created\n";
} catch (Exception $e) {
    echo "[FATAL] Failed to create StoreClient: " . $e->getMessage() . "\n";
    exit(1);
}

// Fetch single matrikkelenhet
echo "\nFetching matrikkelenhet from StoreService...\n";
try {
    $id = new MatrikkelenhetId((int)$matrikkelenhetId);
    $matrikkelenhet = $storeClient->getObject($id);
    echo "[OK] Received object\n\n";
} catch (Exception $e) {
    echo "[FATAL] Failed to fetch: " . $e->getMessage() . "\n";
    exit(1);
}

// Inspect object
echo "=== Object Structure ===\n";
echo "Class: " . get_class($matrikkelenhet) . "\n\n";

$properties = get_object_vars($matrikkelenhet);
echo "Properties (" . count($properties) . " total):\n";

foreach ($properties as $name => $value) {
    $type = gettype($value);
    if (is_object($value)) {
        $type = get_class($value);
    } elseif (is_array($value)) {
        $type = "array(" . count($value) . ")";
    }
    echo "  - $name: $type\n";
    
    // Deep inspect eierforhold
    if ($name === 'eierforhold' || $name === 'tinglystEierforhold' || $name === 'ikkeTinglystEierforhold') {
        echo "    *** EIERFORHOLD FIELD FOUND ***\n";
        if (is_array($value)) {
            echo "    Array with " . count($value) . " items:\n";
            foreach ($value as $i => $eier) {
                echo "      [$i] " . (is_object($eier) ? get_class($eier) : gettype($eier)) . "\n";
                if (is_object($eier)) {
                    $eierProps = get_object_vars($eier);
                    foreach ($eierProps as $prop => $val) {
                        $valType = is_object($val) ? get_class($val) : gettype($val);
                        echo "        - $prop: $valType";
                        if ($prop === 'personId' || $prop === 'juridiskPersonId' || $prop === 'fysiskPersonId') {
                            if (is_object($val) && isset($val->value)) {
                                echo " = " . $val->value;
                            }
                        }
                        echo "\n";
                    }
                }
            }
        } elseif (is_object($value)) {
            echo "    Single object: " . get_class($value) . "\n";
            $eierProps = get_object_vars($value);
            foreach ($eierProps as $prop => $val) {
                $valType = is_object($val) ? get_class($val) : gettype($val);
                echo "      - $prop: $valType\n";
                
                // Deep dive into 'item' if it exists
                if ($prop === 'item' && is_object($val)) {
                    echo "        DIVING INTO ITEM:\n";
                    $itemProps = get_object_vars($val);
                    foreach ($itemProps as $iProp => $iVal) {
                        $iType = is_object($iVal) ? get_class($iVal) : gettype($iVal);
                        echo "          - $iProp: $iType";
                        if ($iProp === 'personId' || $iProp === 'juridiskPersonId' || $iProp === 'fysiskPersonId' || $iProp === 'eierId') {
                            if (is_object($iVal) && isset($iVal->value)) {
                                echo " = " . $iVal->value;
                            }
                        }
                        echo "\n";
                    }
                } elseif ($prop === 'item' && is_array($val)) {
                    echo "        ITEM IS ARRAY with " . count($val) . " elements:\n";
                    foreach ($val as $idx => $arrItem) {
                        echo "          [$idx] " . (is_object($arrItem) ? get_class($arrItem) : gettype($arrItem)) . "\n";
                        if (is_object($arrItem)) {
                            $arrItemProps = get_object_vars($arrItem);
                            foreach ($arrItemProps as $aProp => $aVal) {
                                $aType = is_object($aVal) ? get_class($aVal) : gettype($aVal);
                                echo "            - $aProp: $aType";
                                if ($aProp === 'personId' || $aProp === 'juridiskPersonId' || $aProp === 'fysiskPersonId' || $aProp === 'eierId') {
                                    if (is_object($aVal) && isset($aVal->value)) {
                                        echo " = " . $aVal->value;
                                    }
                                }
                                echo "\n";
                            }
                        }
                    }
                }
            }
        } elseif ($value === null) {
            echo "    NULL - no eierforhold\n";
        } else {
            echo "    Value: " . var_export($value, true) . "\n";
        }
    }
}

// Summary
echo "\n=== SUMMARY ===\n";
$hasEierforhold = isset($matrikkelenhet->eierforhold) || 
                  isset($matrikkelenhet->tinglystEierforhold) || 
                  isset($matrikkelenhet->ikkeTinglystEierforhold);

if ($hasEierforhold) {
    echo "[OK] Eierforhold field(s) found in object!\n";
    
    $eierforholdCount = 0;
    if (isset($matrikkelenhet->tinglystEierforhold)) {
        $count = is_array($matrikkelenhet->tinglystEierforhold) ? count($matrikkelenhet->tinglystEierforhold) : 1;
        echo "  - tinglystEierforhold: $count item(s)\n";
        $eierforholdCount += $count;
    }
    if (isset($matrikkelenhet->ikkeTinglystEierforhold)) {
        $count = is_array($matrikkelenhet->ikkeTinglystEierforhold) ? count($matrikkelenhet->ikkeTinglystEierforhold) : 1;
        echo "  - ikkeTinglystEierforhold: $count item(s)\n";
        $eierforholdCount += $count;
    }
    if (isset($matrikkelenhet->eierforhold)) {
        $count = is_array($matrikkelenhet->eierforhold) ? count($matrikkelenhet->eierforhold) : 1;
        echo "  - eierforhold: $count item(s)\n";
        $eierforholdCount += $count;
    }
    
    echo "\nTotal eierforhold: $eierforholdCount\n";
} else {
    echo "[WARNING] NO eierforhold fields found!\n";
    echo "This matrikkelenhet may not have registered owners,\n";
    echo "or the API response doesn't include eierforhold data.\n";
}

echo "\n";
