# Matrikkel SOAP API - Paginering og Import Guide for PHP

## Oversikt
Dette dokumentet beskriver hvordan du implementerer korrekt paginering og import fra Matrikkel SOAP API i PHP, basert på løsningen som fungerer i Java-prosjektet.

---

## Del 1: SOAP Paginering med MatrikkelBubbleId

### Problemet
Matrikkel API bruker cursor-basert paginering med `MatrikkelBubbleId`-objekter. Dette er komplekse XML-strukturer som må serialiseres korrekt.

### Løsningen: PHP SOAP med ClassMap

#### Steg 1: Opprett PHP-klasser for Matrikkel-typer

```php
<?php
// MatrikkelTypes.php

class MatrikkelBubbleId {
    public $type;    // String: "MatrikkelenhetId", "PersonId", etc.
    public $value;   // Long: ID-verdien
}

class SnapshotVersion {
    public $timestamp;  // DateTime i ISO 8601 format
}

class MatrikkelContext {
    public $snapshotVersion;  // SnapshotVersion object
}

class MatrikkelenhetId {
    public $value;  // Long
}
```

#### Steg 2: Konfigurer SOAP-klient med ClassMap

```php
<?php
// NedlastningClient.php

class NedlastningClient {
    private $client;
    private $username;
    private $password;
    
    public function __construct($wsdlUrl, $username, $password) {
        $this->username = $username;
        $this->password = $password;
        
        // KRITISK: Bruk classmap for automatisk serialisering
        $this->client = new SoapClient($wsdlUrl, [
            'classmap' => [
                'MatrikkelBubbleId' => 'MatrikkelBubbleId',
                'SnapshotVersion' => 'SnapshotVersion',
                'MatrikkelContext' => 'MatrikkelContext',
                'MatrikkelenhetId' => 'MatrikkelenhetId',
            ],
            'trace' => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,  // For utvikling
            'soap_version' => SOAP_1_1,
        ]);
    }
    
    /**
     * Opprett MatrikkelContext med snapshotVersion satt til 9999-01-01
     * VIKTIG: Må alltid bruke fremtidig dato for å unngå tilgangsfeil
     */
    private function createContext() {
        $context = new MatrikkelContext();
        $snapshotVersion = new SnapshotVersion();
        $snapshotVersion->timestamp = '9999-01-01T00:00:00+01:00';  // ISO 8601 format
        $context->snapshotVersion = $snapshotVersion;
        return $context;
    }
    
    /**
     * Legg til Basic Authentication headers
     */
    private function addAuthHeaders() {
        $auth = base64_encode($this->username . ':' . $this->password);
        stream_context_set_option($this->client->_stream_context, [
            'http' => [
                'header' => "Authorization: Basic $auth"
            ]
        ]);
    }
    
    /**
     * Hent objekter med paginering
     * 
     * @param MatrikkelBubbleId|null $cursor Cursor fra forrige batch (null for første)
     * @param int $batchSize Antall objekter per batch (max 5000)
     * @param string $kommunenummer Kommunenummer (4 siffer)
     * @return object SOAP response med MatrikkelBubbleObjectList
     */
    public function findObjekterEtterId($cursor, $batchSize, $kommunenummer) {
        $this->addAuthHeaders();
        $context = $this->createContext();
        
        try {
            $response = $this->client->findObjekterEtterId([
                'id' => $cursor,  // MatrikkelBubbleId eller null
                'antall' => $batchSize,
                'kommunenummer' => $kommunenummer,
                'kontekst' => $context
            ]);
            
            return $response;
            
        } catch (SoapFault $e) {
            error_log("SOAP Error: " . $e->getMessage());
            error_log("Request: " . $this->client->__getLastRequest());
            error_log("Response: " . $this->client->__getLastResponse());
            throw $e;
        }
    }
}
```

#### Steg 3: Implementer paginering

```php
<?php
// import_matrikkelenheter.php

require_once 'MatrikkelTypes.php';
require_once 'NedlastningClient.php';

function importMatrikkelenheter($kommunenummer) {
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/NedlastningServiceWS?wsdl';
    $username = 'DITT_BRUKERNAVN';
    $password = 'DITT_PASSORD';
    
    $client = new NedlastningClient($wsdl, $username, $password);
    
    $cursor = null;  // Start uten cursor
    $batchSize = 5000;  // API maximum
    $totalCount = 0;
    $batchNumber = 1;
    
    echo "Starting import for kommune $kommunenummer\n";
    
    do {
        echo "Fetching batch $batchNumber...\n";
        
        // Hent batch
        $response = $client->findObjekterEtterId($cursor, $batchSize, $kommunenummer);
        
        // Ekstraher objekter fra respons
        $objects = $response->return->matrikkelBubbleObject ?? [];
        if (!is_array($objects)) {
            $objects = [$objects];  // Håndter single object
        }
        
        $batchCount = count($objects);
        $totalCount += $batchCount;
        
        echo "Received $batchCount objects (Total: $totalCount)\n";
        
        // Prosesser objekter
        foreach ($objects as $obj) {
            // Her: Lagre til database
            processMatrikkelenhet($obj);
        }
        
        // Hent cursor for neste batch
        $cursor = $response->return->matrikkelBubbleId ?? null;
        
        // Sjekk om siste batch
        $isLastBatch = ($batchCount < $batchSize);
        
        $batchNumber++;
        
    } while (!$isLastBatch && $cursor !== null);
    
    echo "Import complete! Total objects: $totalCount\n";
}

function processMatrikkelenhet($obj) {
    // Eksempel: Lagre til database
    // $obj inneholder Matrikkelenhet-objektet
    
    if (isset($obj->Grunneiendom)) {
        $grunneiendom = $obj->Grunneiendom;
        
        // Ekstraher data
        $matrikkelenhetId = $grunneiendom->matrikkelnummer->matrikkelenhetId->value ?? null;
        $kommunenummer = $grunneiendom->matrikkelnummer->kommunenummer ?? null;
        $gardsnummer = $grunneiendom->matrikkelnummer->gardsnummer ?? null;
        $bruksnummer = $grunneiendom->matrikkelnummer->bruksnummer ?? null;
        
        echo "Processing: $kommunenummer/$gardsnummer/$bruksnummer (ID: $matrikkelenhetId)\n";
        
        // TODO: INSERT INTO database
    }
}

// Kjør import
importMatrikkelenheter('4631');  // Osterøy
```

---

## Del 2: Komplett Import-prosess (Two-Phase Architecture)

Java-prosjektet bruker en to-fase import-arkitektur for effektivitet:

### Phase 1: Base Import (Matrikkelenheter + Personer)

```php
<?php
// phase1_base_import.php

/**
 * Phase 1: Hent matrikkelenheter og persondata
 * Dette er den grunnleggende importen som må kjøres først
 */
function phase1BaseImport($kommunenummer) {
    echo "=== PHASE 1: Base Import ===\n";
    
    // 1. Importer kommune
    importKommune($kommunenummer);
    
    // 2. Importer matrikkelenheter (bulk download)
    importMatrikkelenheter($kommunenummer);
    
    // 3. Importer personer (fra eierforhold)
    importPersoner($kommunenummer);
    
    echo "=== Phase 1 Complete ===\n";
}

function importKommune($kommunenummer) {
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/KommuneServiceWS?wsdl';
    // ... SOAP-kall til getKommune()
    // Lagre kommune til database
}

function importMatrikkelenheter($kommunenummer) {
    // Se Steg 3 over for full implementasjon
    // Lagre matrikkelenheter til database
}

function importPersoner($kommunenummer) {
    // Hent alle matrikkelenheter fra database
    // For hver matrikkelenhet:
    //   - Hent eierforhold fra API
    //   - Ekstraher person-IDer
    //   - Hent persondata fra PersonService eller StoreService
    //   - Lagre personer til database
    
    $db = getDatabase();
    $matrikkelenheter = $db->query("SELECT matrikkelenhet_id FROM matrikkelenheter WHERE kommunenummer = ?", [$kommunenummer]);
    
    foreach ($matrikkelenheter as $me) {
        $eierforhold = fetchEierforhold($me['matrikkelenhet_id']);
        
        foreach ($eierforhold as $eier) {
            if (isset($eier->personId)) {
                $person = fetchPerson($eier->personId->value);
                savePerson($person);
            }
        }
    }
}
```

### Phase 2: Filtered Import (Bygninger/Bruksenheter/Adresser)

```php
<?php
// phase2_filtered_import.php

/**
 * Phase 2: Hent bygninger, bruksenheter og adresser for filtrerte matrikkelenheter
 * Kjøres ETTER Phase 1
 * Støtter filtrering på personnummer eller organisasjonsnummer
 */
function phase2FilteredImport($kommunenummer, $personnummer = null, $organisasjonsnummer = null) {
    echo "=== PHASE 2: Filtered Import ===\n";
    
    // 1. Filtrer matrikkelenheter fra database basert på eierskap
    $matrikkelenheter = filterMatrikkelenheterByOwner($kommunenummer, $personnummer, $organisasjonsnummer);
    
    echo "Found " . count($matrikkelenheter) . " matrikkelenheter for filter\n";
    
    // 2. VIKTIG: Hent ALLE veger først (bulk download for hele kommunen)
    importVeger($kommunenummer);
    
    // 3. Hent bruksenheter (API-side filtrering - to-step pattern)
    importBruksenheter($matrikkelenheter);
    
    // 4. Hent bygninger (bulk download, filter client-side)
    importBygninger($kommunenummer, $matrikkelenheter);
    
    // 5. Hent adresser (API-side filtrering - to-step pattern)
    importAdresser($matrikkelenheter);
    
    echo "=== Phase 2 Complete ===\n";
}

function filterMatrikkelenheterByOwner($kommunenummer, $personnummer, $organisasjonsnummer) {
    $db = getDatabase();
    
    if ($personnummer) {
        // Filtrer på personnummer
        $sql = "
            SELECT DISTINCT m.matrikkelenhet_id 
            FROM matrikkelenheter m
            JOIN eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
            JOIN personer p ON (p.id = e.fysisk_person_id OR p.id = e.juridisk_person_id)
            WHERE m.kommunenummer = ? AND p.nummer = ?
        ";
        return $db->query($sql, [$kommunenummer, $personnummer]);
    }
    
    if ($organisasjonsnummer) {
        // Filtrer på organisasjonsnummer
        $sql = "
            SELECT DISTINCT m.matrikkelenhet_id 
            FROM matrikkelenheter m
            JOIN eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
            JOIN juridiske_personer jp ON jp.id = e.juridisk_person_id
            WHERE m.kommunenummer = ? AND jp.organisasjonsnummer = ?
        ";
        return $db->query($sql, [$kommunenummer, $organisasjonsnummer]);
    }
    
    // Ingen filter - returner alle
    return $db->query("SELECT matrikkelenhet_id FROM matrikkelenheter WHERE kommunenummer = ?", [$kommunenummer]);
}

function importVeger($kommunenummer) {
    echo "Importing ALL veger for kommune $kommunenummer (bulk)...\n";
    
    // Bulk download av alle veger i kommunen
    // Dette MÅ gjøres før adresser importeres
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/NedlastningServiceWS?wsdl';
    $client = new NedlastningClient($wsdl, USERNAME, PASSWORD);
    
    $cursor = null;
    $batchSize = 5000;
    
    do {
        $response = $client->findObjekterEtterId($cursor, $batchSize, $kommunenummer);
        $objects = $response->return->matrikkelBubbleObject ?? [];
        
        foreach ($objects as $obj) {
            if (isset($obj->Veg)) {
                saveVeg($obj->Veg);
            }
        }
        
        $cursor = $response->return->matrikkelBubbleId ?? null;
        $isLast = count($objects) < $batchSize;
        
    } while (!$isLast && $cursor !== null);
    
    echo "Veger import complete\n";
}

function importBruksenheter($matrikkelenheter) {
    echo "Importing bruksenheter (API-filtered)...\n";
    
    // TWO-STEP PATTERN (API-side filtering - EFFEKTIVT!)
    // Step 1: Hent bruksenhet-IDer for matrikkelenheter
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/BruksenhetServiceWS?wsdl';
    $client = new SoapClient($wsdl, ['trace' => 1]);
    
    $matrikkelenhetIds = array_map(function($me) {
        $id = new MatrikkelenhetId();
        $id->value = $me['matrikkelenhet_id'];
        return $id;
    }, $matrikkelenheter);
    
    $response = $client->findBruksenheterForMatrikkelenheter([
        'matrikkelenhetIdList' => ['matrikkelenhetId' => $matrikkelenhetIds],
        'kontekst' => createContext()
    ]);
    
    // Step 2: Hent full bruksenhet-data med StoreService
    $bruksenhetIds = extractBruksenhetIdsFromResponse($response);
    fetchAndSaveWithStoreService($bruksenhetIds, 'Bruksenhet');
    
    echo "Bruksenheter import complete\n";
}

function importBygninger($kommunenummer, $filteredMatrikkelenheter) {
    echo "Importing bygninger (bulk download, client-side filter)...\n";
    
    // BULK DOWNLOAD av alle bygninger, filter client-side
    // (API har ikke server-side filter for bygninger)
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/NedlastningServiceWS?wsdl';
    $client = new NedlastningClient($wsdl, USERNAME, PASSWORD);
    
    $matrikkelenhetIds = array_column($filteredMatrikkelenheter, 'matrikkelenhet_id');
    
    $cursor = null;
    $batchSize = 5000;
    
    do {
        $response = $client->findObjekterEtterId($cursor, $batchSize, $kommunenummer);
        $objects = $response->return->matrikkelBubbleObject ?? [];
        
        foreach ($objects as $obj) {
            if (isset($obj->Bygning)) {
                $bygning = $obj->Bygning;
                
                // CLIENT-SIDE FILTER: Sjekk om bygning tilhører filtrerte matrikkelenheter
                if (bygningBelongsToMatrikkelenheter($bygning, $matrikkelenhetIds)) {
                    saveBygning($bygning);
                }
            }
        }
        
        $cursor = $response->return->matrikkelBubbleId ?? null;
        $isLast = count($objects) < $batchSize;
        
    } while (!$isLast && $cursor !== null);
    
    echo "Bygninger import complete\n";
}

function importAdresser($matrikkelenheter) {
    echo "Importing adresser (API-filtered)...\n";
    
    // TWO-STEP PATTERN (API-side filtering - EFFEKTIVT!)
    // Step 1: Hent adresse-IDer for matrikkelenheter
    $wsdl = 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/AdresseServiceWS?wsdl';
    $client = new SoapClient($wsdl, ['trace' => 1]);
    
    $matrikkelenhetIds = array_map(function($me) {
        $id = new MatrikkelenhetId();
        $id->value = $me['matrikkelenhet_id'];
        return $id;
    }, $matrikkelenheter);
    
    $response = $client->findAdresserForMatrikkelenheter([
        'matrikkelenhetIdList' => ['matrikkelenhetId' => $matrikkelenhetIds],
        'kontekst' => createContext()
    ]);
    
    // Step 2: Hent full adresse-data med StoreService
    $adresseIds = extractAdresseIdsFromResponse($response);
    fetchAndSaveWithStoreService($adresseIds, 'Adresse');
    
    echo "Adresser import complete\n";
}
```

---

## Del 3: Kritiske Suksessfaktorer

### 1. MatrikkelContext med SnapshotVersion
```php
// ALLTID bruk fremtidig dato (9999-01-01) for å unngå tilgangsfeil
$context = new MatrikkelContext();
$snapshotVersion = new SnapshotVersion();
$snapshotVersion->timestamp = '9999-01-01T00:00:00+01:00';
$context->snapshotVersion = $snapshotVersion;
```

### 2. Batch Size
```php
$batchSize = 5000;  // API maximum - IKKE bruk større verdi
```

### 3. Cursor Detection (siste batch)
```php
// Sjekk om siste batch
$isLastBatch = (count($objects) < $batchSize);
```

### 4. To-Step Pattern for API-filtrering
```php
// Step 1: Hent IDer (server-side filter)
$ids = $serviceClient->findXForMatrikkelenheter($matrikkelenhetIds);

// Step 2: Hent full data (batch fetch)
$objects = $storeService->getObjects($ids);
```

### 5. Rekkefølge i Phase 2
```
1. Veger (bulk, HELE kommunen)
2. Bruksenheter (API-filtered)
3. Bygninger (bulk download, client-side filter)
4. Adresser (API-filtered) - ETTER veger!
```

### 6. Database-struktur
- Bruk samme skjema som Java-prosjektet (`V1__baseline_schema.sql`)
- Støtt både fysiske og juridiske personer
- Bruk foreign keys for integritet
- Lagre `Person.nummer` for både fødselsnummer og organisasjonsnummer

---

## Del 4: Debugging og Feilsøking

### Logg SOAP-requests
```php
try {
    $response = $client->findObjekterEtterId(...);
} catch (SoapFault $e) {
    error_log("SOAP Fault: " . $e->getMessage());
    error_log("Request XML:\n" . $client->__getLastRequest());
    error_log("Response XML:\n" . $client->__getLastResponse());
    throw $e;
}
```

### Vanlige feil

1. **"Permission denied"**: Bruk `snapshotVersion = 9999-01-01`
2. **"Invalid MatrikkelBubbleId"**: Sjekk at classmap er korrekt konfigurert
3. **"Max 5000 objects"**: Reduser batch size til 5000
4. **"No cursor returned"**: Siste batch - stopp paginering

---

## Del 5: Kjøreeksempel

```bash
# Phase 1: Base import (matrikkelenheter + personer)
php phase1_base_import.php --kommune=4631

# Phase 2: Filtered import (bygninger/bruksenheter/adresser)
php phase2_filtered_import.php --kommune=4631 --organisasjonsnummer=922530890
```

---

## Konklusjon

**Nøkkelen til suksess**: La PHP SOAP-extension håndtere serialisering/deserialisering av `MatrikkelBubbleId` via classmap. Ikke serialiser manuelt til XML!

**Two-Phase Architecture**: Kjør base import først (billig/rask), deretter filtered import for spesifikke eiere (dyrere, men nødvendig for bygninger/adresser).

**API-filtrering**: Bruk two-step pattern for bruksenheter og adresser for å unngå å laste ned hele kommunen.
