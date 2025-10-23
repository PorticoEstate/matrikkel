# Copilot Agent Instructions: Matrikkel API Import Implementation

## Oversikt

Du skal implementere en komplett import-prosess for Matrikkel API i PHP basert på **to-fase arkitektur** med **server-side filtrering**. All nødvendig dokumentasjon finnes i `/opt/matrikkel/doc/MATRIKKEL_API_IMPORT_PROSESS.md`.

---

## Tilgjengelige Ressurser

### WSDL-filer

Alle WSDL-filer er tilgjengelige i `/opt/matrikkel/doc/wsdl/`:
- `StoreServiceWS.wsdl` - Hent komplette objekter basert på IDer
- `MatrikkelenhetServiceWS.wsdl` - Server-side filter for matrikkelenheter
- `KommuneServiceWS.wsdl` - Kommune-søk og ID-oppslag
- `PersonServiceWS.wsdl` - Person-søk og ID-oppslag
- `BruksenhetServiceWS.wsdl` - Bruksenhet-søk for matrikkelenheter
- `BygningServiceWS.wsdl` - Bygning-søk for matrikkelenheter
- `AdresseServiceWS.wsdl` - Adresse-søk for matrikkelenheter
- `NedlastningServiceWS.wsdl` - Bulk-nedlasting med paginering

### Eksisterende Kode

- `src/Client/AbstractSoapClient.php` - Base SOAP client med classmap
- `src/Client/MatrikkelTypes.php` - PHP klasser for SOAP types
- `src/Client/NedlastningClient.php` - Client med `findObjekterEtterIdWithClassMap()` (fungerer!)
- `src/Service/MatrikkelenhetImportService.php` - Service for matrikkelenhet-import (fungerer!)
- `src/LocalDb/MatrikkelenhetTable.php` - Database-lagring av matrikkelenheter
- Database schema: `/opt/matrikkel/doc/V1__baseline_schema.sql`

### Fungerende Løsninger

✅ **SOAP Classmap approach** - Fungerer perfekt med `AbstractSoapClient`
✅ **Paginering** - `NedlastningClient::findObjekterEtterIdWithClassMap()` fungerer
✅ **Matrikkelenhet import** - Bulk download med kommune-filter fungerer

---

## To-Fase Arkitektur

### Phase 1: Base Import (Grunndata)

**Formål:** Hent grunnleggende data for hele kommunen (uten eier-filter).

**Steg:**
1. ✅ Verifiser/hent kommune (manuelt INSERT utført, skip for nå)
2. ✅ Bulk-download matrikkelenheter (FUNGERER ALLEREDE!)
3. ⚠️ **TODO:** Hent personer fra eierforhold
4. ⚠️ **TODO:** Lagre eierforhold

**Eksisterende kommando:** `php bin/console matrikkel:phase1-import --kommune=4601`

### Phase 2: Filtered Import (Filtrerte Data)

**Formål:** Hent detaljerte data KUN for matrikkelenheter som matcher eier-filter.

**Steg:**
1. ⚠️ **TODO:** Filtrer matrikkelenheter basert på eier (personnummer/organisasjonsnummer)
2. ⚠️ **TODO:** Bulk-download veger (hele kommunen)
3. ⚠️ **TODO:** API-filtered import av bruksenheter (two-step pattern)
4. ⚠️ **TODO:** API-filtered import av bygninger (two-step pattern)
5. ⚠️ **TODO:** API-filtered import av adresser (two-step pattern)

**Eksisterende kommando:** `php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=922530890`

---

## Implementasjonsplan

### Prioritet 1: Phase 1 - Person og Eierforhold Import

#### Task 1.1: Opprett SOAP Client for StoreService

**Fil:** `src/Client/StoreClient.php`

**Krav:**
- Extend `AbstractSoapClient` (for automatisk classmap)
- Implementer `getObjects(array $bubbleIds): array`
- Batch-støtte (max 1000 objekter per request)
- Håndter både enkelt-objekt og array-respons

**WSDL:** `/opt/matrikkel/doc/wsdl/StoreServiceWS.wsdl`

**Eksempel fra dokumentasjon:**
```php
/**
 * Hent komplette objekter fra StoreService
 * 
 * @param array $bubbleIds Array av MatrikkelBubbleId objekter
 * @return array Array av komplette objekter
 */
public function getObjects(array $bubbleIds): array
{
    $params = [
        'bubbleIdList' => ['items' => $bubbleIds],
        'matrikkelContext' => $this->getMatrikkelContext()
    ];
    
    $response = $this->__call('getObjects', [$params]);
    
    // Parse response
    $items = $response->return->items ?? [];
    if (!is_array($items)) {
        $items = [$items];
    }
    
    return $items;
}
```

#### Task 1.2: Opprett SOAP Client for MatrikkelenhetService

**Fil:** `src/Client/MatrikkelenhetClient.php` (finnes allerede, men kanskje ikke komplett)

**Krav:**
- Implementer `findMatrikkelenheterForPerson(PersonId $personId): array`
- Implementer `findMatrikkelenheterForOrganisasjon(PersonId $organisasjonId): array`
- Server-side filtrering (returnerer kun IDer, ikke komplette objekter)

**WSDL:** `/opt/matrikkel/doc/wsdl/MatrikkelenhetServiceWS.wsdl`

**Metode fra dokumentasjon:**
```
Operation: findMatrikkelenheter()

Request:
  avgrensMatrikkelenhetPaPersonQuery:
    personId: PersonId(value: 789012345)
  context: MatrikkelContext

Response:
  matrikkelenhetIdList:
    items:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
```

#### Task 1.3: Opprett SOAP Client for PersonService

**Fil:** `src/Client/PersonClient.php`

**Krav:**
- Implementer `findPersonIdByNummer(string $nummer): ?PersonId`
- Håndter 404 (person ikke funnet) gracefully
- Støtt både fødselsnummer og organisasjonsnummer

**WSDL:** `/opt/matrikkel/doc/wsdl/PersonServiceWS.wsdl`

**Metode fra dokumentasjon:**
```
Operation: findPersonIdByNummer()

Request:
  nummer: "12345678901"  # Fødselsnummer eller organisasjonsnummer
  context: MatrikkelContext

Response (SUCCESS):
  PersonId:
    value: 789012345

Response (404 - Person ikke i Matrikkel):
  SoapFault: "Person not found"
```

#### Task 1.4: Opprett Person Import Service

**Fil:** `src/Service/PersonImportService.php`

**Krav:**
- Hent alle matrikkelenheter fra database for kommunen
- For hver matrikkelenhet: hent eierforhold fra StoreService
- Ekstraher person-IDer fra eierforhold
- Batch-fetch personer fra StoreService (max 500 per batch)
- Skille mellom fysiske og juridiske personer
- Lagre i 3 tabeller: `matrikkel_personer`, `matrikkel_fysiske_personer`, `matrikkel_juridiske_personer`

**Database Tables (fra V1__baseline_schema.sql):**
```sql
-- matrikkel_personer (base table)
CREATE TABLE matrikkel_personer (
    id BIGSERIAL PRIMARY KEY,
    matrikkel_person_id BIGINT NOT NULL UNIQUE,
    uuid VARCHAR(36),
    nummer VARCHAR(50),  -- fødselsnummer eller organisasjonsnummer
    navn VARCHAR(500),
    ...
);

-- matrikkel_fysiske_personer (extends personer)
CREATE TABLE matrikkel_fysiske_personer (
    id BIGINT PRIMARY KEY REFERENCES matrikkel_personer(id),
    fodselsnummer VARCHAR(11) UNIQUE,
    etternavn VARCHAR(200),
    fornavn VARCHAR(200),
    ...
);

-- matrikkel_juridiske_personer (extends personer)
CREATE TABLE matrikkel_juridiske_personer (
    id BIGINT PRIMARY KEY REFERENCES matrikkel_personer(id),
    organisasjonsnummer VARCHAR(20) UNIQUE,
    organisasjonsform_kode VARCHAR(50),
    ...
);
```

**Pseudo-kode:**
```php
public function importPersonerForKommune(SymfonyStyle $io, int $kommunenummer): int
{
    // 1. Hent matrikkelenheter fra database
    $matrikkelenheter = $this->db->query(
        "SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = ?",
        [$kommunenummer]
    );
    
    // 2. Hent eierforhold for hver matrikkelenhet (batch via StoreService)
    $matrikkelenhetIds = array_map(fn($m) => new MatrikkelenhetId($m['matrikkelenhet_id']), $matrikkelenheter);
    
    // Batch: 500 matrikkelenheter per StoreService call
    $allEierforhold = [];
    foreach (array_chunk($matrikkelenhetIds, 500) as $batch) {
        $objects = $this->storeClient->getObjects($batch);
        foreach ($objects as $obj) {
            if (isset($obj->eierforhold)) {
                $allEierforhold = array_merge($allEierforhold, $obj->eierforhold);
            }
        }
    }
    
    // 3. Ekstraher unike person-IDer
    $personIds = [];
    foreach ($allEierforhold as $eierforhold) {
        if (isset($eierforhold->personId)) {
            $personIds[$eierforhold->personId->value] = $eierforhold->personId;
        }
    }
    
    // 4. Batch-fetch personer (500 per call)
    $personCount = 0;
    foreach (array_chunk(array_values($personIds), 500) as $batch) {
        $personer = $this->storeClient->getObjects($batch);
        
        foreach ($personer as $person) {
            $this->savePerson($person);
            $personCount++;
        }
    }
    
    return $personCount;
}

private function savePerson($person): void
{
    // Sjekk om fysisk eller juridisk person
    if (isset($person->fodselsnummer)) {
        // Fysisk person
        $this->saveFysiskPerson($person);
    } elseif (isset($person->organisasjonsnummer)) {
        // Juridisk person
        $this->saveJuridiskPerson($person);
    }
}
```

#### Task 1.5: Opprett Eierforhold Import Service

**Fil:** `src/Service/EierforholdImportService.php`

**Krav:**
- Hent eierforhold for matrikkelenheter
- Lagre i `matrikkel_eierforhold` tabell med foreign keys til matrikkelenheter og personer

**Database Table:**
```sql
CREATE TABLE matrikkel_eierforhold (
    id BIGSERIAL PRIMARY KEY,
    matrikkelenhet_id BIGINT NOT NULL,
    matrikkel_eierforhold_id BIGINT,
    eierforhold_type VARCHAR(50),
    andel_teller INTEGER,
    andel_nevner INTEGER,
    fysisk_person_id BIGINT,
    juridisk_person_entity_id BIGINT,
    eier_matrikkelenhet_id BIGINT,
    tinglyst BOOLEAN DEFAULT false,
    ...
    CONSTRAINT fk_eierforhold_matrikkelenhet 
        FOREIGN KEY (matrikkelenhet_id) 
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id),
    CONSTRAINT fk_eierforhold_fysisk_person
        FOREIGN KEY (fysisk_person_id)
        REFERENCES matrikkel_fysiske_personer(id),
    CONSTRAINT fk_eierforhold_juridisk_person
        FOREIGN KEY (juridisk_person_entity_id)
        REFERENCES matrikkel_juridiske_personer(id)
);
```

#### Task 1.6: Integrer i Phase1ImportCommand

**Fil:** `src/Console/Phase1ImportCommand.php`

**Oppdater TODO-seksjoner:**
```php
// Step 3: Import personer (from eierforhold)
$io->section('Step 3/4: Importing personer');
$personCount = $this->personImportService->importPersonerForKommune($io, $kommunenummer);
$io->success("Imported $personCount personer");

// Step 4: Import eierforhold
$io->section('Step 4/4: Importing eierforhold');
$eierforholdCount = $this->eierforholdImportService->importEierforholdForKommune($io, $kommunenummer);
$io->success("Imported $eierforholdCount eierforhold");
```

---

### Prioritet 2: Phase 2 - Filtered Import Services

#### Task 2.1: Opprett Matrikkelenhet Filter Service

**Fil:** `src/Service/MatrikkelenhetFilterService.php`

**Krav:**
- Filtrer matrikkelenheter fra database basert på eier
- SQL query med JOIN til personer og eierforhold
- Støtt både personnummer og organisasjonsnummer

**Pseudo-kode:**
```php
public function filterMatrikkelenheterByOwner(
    int $kommunenummer,
    ?string $personnummer = null,
    ?string $organisasjonsnummer = null
): array {
    if ($personnummer) {
        $sql = "
            SELECT DISTINCT m.matrikkelenhet_id 
            FROM matrikkel_matrikkelenheter m
            JOIN matrikkel_eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
            JOIN matrikkel_fysiske_personer fp ON fp.id = e.fysisk_person_id
            WHERE m.kommunenummer = ? AND fp.fodselsnummer = ?
        ";
        return $this->db->query($sql, [$kommunenummer, $personnummer]);
    }
    
    if ($organisasjonsnummer) {
        $sql = "
            SELECT DISTINCT m.matrikkelenhet_id 
            FROM matrikkel_matrikkelenheter m
            JOIN matrikkel_eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
            JOIN matrikkel_juridiske_personer jp ON jp.id = e.juridisk_person_entity_id
            WHERE m.kommunenummer = ? AND jp.organisasjonsnummer = ?
        ";
        return $this->db->query($sql, [$kommunenummer, $organisasjonsnummer]);
    }
    
    // Ingen filter - returner alle
    return $this->db->query(
        "SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = ?",
        [$kommunenummer]
    );
}
```

#### Task 2.2: Opprett Veg Import Service

**Fil:** `src/Service/VegImportService.php`

**Krav:**
- Bulk-download ALLE veger for kommunen (ingen filter)
- Bruk `NedlastningClient::findObjekterEtterIdWithClassMap()`
- Lagre i `matrikkel_veger` tabell

**Database Table:**
```sql
CREATE TABLE matrikkel_veger (
    veg_id BIGINT PRIMARY KEY,
    kommune_id BIGINT NOT NULL,
    adressekode INTEGER NOT NULL,
    adressenavn VARCHAR(200) NOT NULL,
    kort_adressenavn VARCHAR(100),
    uuid VARCHAR(36),
    ...
);
```

**Pseudo-kode:**
```php
public function importVegerForKommune(SymfonyStyle $io, int $kommunenummer, int $batchSize = 5000): int
{
    $cursor = null;
    $totalCount = 0;
    
    do {
        $batch = $this->nedlastningClient->findObjekterEtterIdWithClassMap(
            $cursor,
            'Veg',  // domainklasse
            '{"kommunefilter": ["' . str_pad($kommunenummer, 4, '0', STR_PAD_LEFT) . '"]}',
            $batchSize
        );
        
        foreach ($batch as $item) {
            $this->vegTable->insertRow($item->soapObject);
            $totalCount++;
        }
        
        $this->vegTable->flush();
        
        // Get cursor for next batch
        if (!empty($batch)) {
            $lastItem = end($batch);
            $cursor = $lastItem->id;
        }
        
    } while (count($batch) === $batchSize);
    
    return $totalCount;
}
```

#### Task 2.3: Opprett Bruksenhet Import Service (Two-Step Pattern)

**Fil:** `src/Service/BruksenhetImportService.php`

**WSDL:** 
- Step 1: `BruksenhetServiceWS.wsdl` - `findBruksenheterForMatrikkelenheter()`
- Step 2: `StoreServiceWS.wsdl` - `getObjects()`

**Two-Step Pattern:**
```php
public function importBruksenheterForMatrikkelenheter(
    SymfonyStyle $io,
    array $matrikkelenhetIds
): int {
    $io->text('Step 1/2: Finding bruksenhet IDs (API-filtered)...');
    
    // STEP 1: Finn bruksenhet-IDer (server-side filter)
    $bruksenhetIds = [];
    foreach (array_chunk($matrikkelenhetIds, 200) as $batch) {
        $matrikkelenhetIdObjects = array_map(
            fn($id) => new MatrikkelenhetId($id),
            $batch
        );
        
        $response = $this->bruksenhetClient->findBruksenheterForMatrikkelenheter(
            $matrikkelenhetIdObjects
        );
        
        // Extract bruksenhet IDs from response
        foreach ($response->items ?? [] as $item) {
            if (isset($item->bruksenhetId)) {
                $bruksenhetIds[] = $item->bruksenhetId;
            }
        }
    }
    
    $io->text('Step 2/2: Fetching full bruksenhet objects...');
    
    // STEP 2: Hent komplette bruksenhet-objekter
    $count = 0;
    foreach (array_chunk($bruksenhetIds, 500) as $batch) {
        $bruksenheter = $this->storeClient->getObjects($batch);
        
        foreach ($bruksenheter as $bruksenhet) {
            $this->bruksenhetTable->insertRow($bruksenhet);
            $count++;
        }
        
        $this->bruksenhetTable->flush();
    }
    
    return $count;
}
```

#### Task 2.4: Opprett Bygning Import Service (Two-Step Pattern)

**Fil:** `src/Service/BygningImportService.php`

**WSDL:**
- Step 1: `BygningServiceWS.wsdl` - `findByggForMatrikkelenheter()`
- Step 2: `StoreServiceWS.wsdl` - `getObjects()`

**Samme pattern som bruksenheter**, men med `BygningId` i stedet.

#### Task 2.5: Opprett Adresse Import Service (Two-Step Pattern)

**Fil:** `src/Service/AdresseImportService.php`

**WSDL:**
- Step 1: `AdresseServiceWS.wsdl` - `findAdresserForMatrikkelenheter()`
- Step 2: `StoreServiceWS.wsdl` - `getObjects()`

**Samme pattern**, men med `AdresseId`.

**VIKTIG:** Adresser må importeres ETTER veger (foreign key dependency)!

#### Task 2.6: Integrer i Phase2ImportCommand

**Fil:** `src/Console/Phase2ImportCommand.php`

**Oppdater alle TODO-seksjoner med faktiske service-kall.**

---

## KRITISKE Implementasjonsdetaljer

### 1. MatrikkelContext (ALLE API-kall)

```php
protected function getMatrikkelContext(): array
{
    return [
        'locale' => 'no_NO',
        'brukOriginaleKoordinater' => false,
        'koordinatsystemKodeId' => ['value' => 22],  // EPSG:25832
        'systemVersion' => '1.0',
        'klientIdentifikasjon' => $this->getOptions()['login'] ?? 'matrikkel-integration',
        'snapshotVersion' => [
            'timestamp' => '9999-01-01T00:00:00+01:00'  // KRITISK: Fremtidig dato!
        ]
    ];
}
```

### 2. SOAP Classmap (Allerede fungerer!)

**Bruk alltid `AbstractSoapClient`** - den har classmap konfigurert korrekt:
```php
class MyNewClient extends AbstractSoapClient
{
    const WSDL = [
        'test' => 'https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/MyServiceWS?wsdl',
        'prod' => 'https://wsweb.matrikkel.no/matrikkel-ws-v1.0/MyServiceWS?wsdl',
    ];
}
```

### 3. Response Parsing

**ALLTID håndter både enkelt-objekt og array:**
```php
$items = $response->return->items ?? [];
if (!is_array($items)) {
    $items = [$items];
}
```

### 4. Batch Sizes

**Anbefalte størrelser:**
- NedlastningService: 5000 (bulk download)
- StoreService: 1000 (object fetch)
- MatrikkelenhetService filter: 500 (ID lookup)
- BruksenhetService filter: 200 (ID lookup)

### 5. Database Foreign Keys

**Rekkefølge for insert:**
1. Kommuner
2. Matrikkelenheter
3. Personer (fysiske + juridiske)
4. Eierforhold
5. Veger
6. Bygninger
7. Bruksenheter (krever bygning + matrikkelenhet)
8. Adresser (krever veger)

### 6. Error Handling

**Håndter 404 gracefully:**
```php
try {
    $personId = $this->personClient->findPersonIdByNummer($nummer);
} catch (\SoapFault $e) {
    if (strpos($e->getMessage(), 'not found') !== false) {
        // Person finnes ikke i Matrikkel - dette er OK
        return null;
    }
    throw $e;  // Andre feil
}
```

---

## Testing og Validering

### Test Phase 1

```bash
# 1. Tøm database (eller bruk ny database)
# 2. Insert kommune 4601
PGPASSWORD="${DB_PASSWORD}" psql -h 10.0.2.15 -p 5435 -U "${DB_USERNAME}" -d matrikkel -c "INSERT INTO matrikkel_kommuner (kommunenummer, kommunenavn, fylkesnummer, fylkesnavn) VALUES (4601, 'Bergen', 46, 'Vestland') ON CONFLICT (kommunenummer) DO NOTHING;"

# 3. Kjør Phase 1
php bin/console matrikkel:phase1-import --kommune=4601 --batch-size=5000

# 4. Verifiser
psql -h 10.0.2.15 -p 5435 -U "${DB_USERNAME}" -d matrikkel -c "
SELECT 
  (SELECT COUNT(*) FROM matrikkel_matrikkelenheter WHERE kommunenummer = 4601) as matrikkelenheter,
  (SELECT COUNT(*) FROM matrikkel_personer) as personer,
  (SELECT COUNT(*) FROM matrikkel_fysiske_personer) as fysiske_personer,
  (SELECT COUNT(*) FROM matrikkel_juridiske_personer) as juridiske_personer,
  (SELECT COUNT(*) FROM matrikkel_eierforhold) as eierforhold;
"
```

### Test Phase 2 (Med filter)

```bash
# Finn en organisasjon i databasen
psql -h 10.0.2.15 -p 5435 -U "${DB_USERNAME}" -d matrikkel -c "
SELECT jp.organisasjonsnummer, p.navn, COUNT(e.id) as antall_eiendommer
FROM matrikkel_juridiske_personer jp
JOIN matrikkel_personer p ON p.id = jp.id
JOIN matrikkel_eierforhold e ON (e.juridisk_person_entity_id = jp.id)
GROUP BY jp.organisasjonsnummer, p.navn
ORDER BY antall_eiendommer DESC
LIMIT 10;
"

# Kjør Phase 2 med filter
php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=XXXXXX

# Verifiser
psql -h 10.0.2.15 -p 5435 -U "${DB_USERNAME}" -d matrikkel -c "
SELECT 
  (SELECT COUNT(*) FROM matrikkel_veger WHERE kommune_id IN (SELECT kommune_id FROM matrikkel_kommuner WHERE kommunenummer = 4601)) as veger,
  (SELECT COUNT(*) FROM matrikkel_bygninger) as bygninger,
  (SELECT COUNT(*) FROM matrikkel_bruksenheter) as bruksenheter,
  (SELECT COUNT(*) FROM matrikkel_adresser) as adresser;
"
```

---

## Ytelsesoptimalisering

### Database Batch Insert

**Bruk LocalDb Table classes** (allerede implementert pattern):
```php
// Accumulate rows
$this->table->insertRow($object);
$this->table->insertRow($object);

// Flush when batch is complete (500-1000 rows)
$this->table->flush();
```

### API Batch Calls

**Aldri kall API for enkelt-objekter i loop:**
```php
// ❌ DÅRLIG (1000 API-kall)
foreach ($ids as $id) {
    $object = $storeClient->getObject($id);  // Enkelt-kall
}

// ✅ BRA (2 API-kall med batch 500)
foreach (array_chunk($ids, 500) as $batch) {
    $objects = $storeClient->getObjects($batch);  // Batch-kall
}
```

### Logging og Progress

**Bruk SymfonyStyle for bruker-feedback:**
```php
$io->section('Importing X objects...');
$io->progressStart($totalCount);

foreach ($batches as $batch) {
    // Process batch
    $io->progressAdvance(count($batch));
}

$io->progressFinish();
$io->success("Imported $totalCount objects");
```

---

## Feilsøking

### Debug SOAP Requests

**Aktiver SOAP tracing:**
```php
// I AbstractSoapClient konstruktør
'trace' => true,  // Allerede aktivert
'exceptions' => true,
```

**Logg requests/responses:**
```php
try {
    $response = $this->__call('methodName', $params);
} catch (\SoapFault $e) {
    error_log("SOAP Fault: " . $e->getMessage());
    error_log("Request XML:\n" . $this->__getLastRequest());
    error_log("Response XML:\n" . $this->__getLastResponse());
    throw $e;
}
```

### Common Issues

1. **"Unknown SOAP client option"** → Sett classmap ETTER parent::__construct()
2. **"Permission denied"** → Sjekk snapshotVersion (MÅ være 9999-01-01)
3. **"Argument #1 must be MatrikkelBubbleId, stdClass given"** → Classmap ikke konfigurert
4. **"Foreign key constraint"** → Feil insert-rekkefølge
5. **"Person not found"** → Normalt, håndter gracefully (404 er OK)

---

## Suksesskriterier

### Phase 1 Complete

✅ Alle matrikkelenheter importert for kommune
✅ Alle personer (fysiske + juridiske) importert
✅ Alle eierforhold importert med korrekte foreign keys
✅ Ingen foreign key violations
✅ Data kan queried med SQL joins

### Phase 2 Complete

✅ Veger importert for kommune
✅ Bruksenheter importert (kun for filtrerte matrikkelenheter)
✅ Bygninger importert (kun for filtrerte matrikkelenheter)
✅ Adresser importert (kun for filtrerte matrikkelenheter)
✅ Two-step pattern fungerer (API-side filtrering reduserer data 90%+)
✅ Eier-filter fungerer (personnummer/organisasjonsnummer)

---

## Neste Steg

1. **Start med Task 1.1** - Opprett `StoreClient.php`
2. **Test hver client individuelt** før du går videre
3. **Implementer services én om gangen** i prioritert rekkefølge
4. **Test Phase 1** komplett før du starter Phase 2
5. **Dokumenter eventuelle avvik** fra denne planen

---

## Referanser

- **Full dokumentasjon:** `/opt/matrikkel/doc/MATRIKKEL_API_IMPORT_PROSESS.md`
- **Database schema:** `/opt/matrikkel/doc/V1__baseline_schema.sql`
- **WSDL-filer:** `/opt/matrikkel/doc/wsdl/`
- **Java guide:** `/opt/matrikkel/doc/MATRIKKEL_SOAP_PAGINERING_GUIDE.md`
- **Eksisterende kode:** `src/Client/`, `src/Service/`, `src/LocalDb/`

God implementering! 🚀
