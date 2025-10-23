# Matrikkel API Import-prosess - Språk-uavhengig Beskrivelse

**Versjon:** 1.0  
**Dato:** 23. oktober 2025  
**Formål:** Komplett beskrivelse av import-prosess med server-side filtrering for reproduksjon i andre programmeringsspråk

---

## Innholdsfortegnelse

1. [Oversikt](#oversikt)
2. [WSDL-tjenester og deres Roller](#wsdl-tjenester-og-deres-roller)
3. [MatrikkelContext og Autentisering](#matrikkelcontext-og-autentisering)
4. [Paginering med MatrikkelBubbleId](#paginering-med-matrikkelBubbleId)
5. [Fullstendig Import-prosess](#fullstendig-import-prosess)
6. [To-stegs API-pattern](#to-stegs-api-pattern)
7. [Datamodell og Relasjoner](#datamodell-og-relasjoner)
8. [Optimalisering og Ytelse](#optimalisering-og-ytelse)

---

## Oversikt

Import-prosessen henter matrikkeldata fra Kartverkets Matrikkel SOAP API med **server-side filtrering** for å minimere dataoverføring. Nøkkelprinsippet er å filtrere så tidlig som mulig i prosessen.

### Hovedstrategi: Server-side Filtrering

**Tradisjonell tilnærming (ineffektiv):**
```
1. Last ned ALLE matrikkelenheter for kommune → 15,000 objekter
2. Filtrer på klient-side for organisasjon → 100 objekter
3. Kast bort 14,900 objekter som ble lastet ned unødvendig
```

**Optimal tilnærming (server-side filtrering):**
```
1. Spør API: "Hvilke matrikkelenhet-IDer tilhører organisasjon X?" → 100 IDer
2. Last ned KUN de 100 matrikkelenhetene
3. Resultat: 99.3% reduksjon i dataoverføring
```

---

## WSDL-tjenester og deres Roller

### Oversikt over Tjenester

| WSDL | Formål | Hovedfunksjon | Bruk i Import |
|------|--------|---------------|---------------|
| **KommuneServiceWS.wsdl** | Kommune-søk | `findKommuneIdForIdent()` | Finn KommuneId fra kommunenummer |
| **StoreServiceWS.wsdl** | Objekthenting | `getObjects()` | Hent komplette objekter basert på IDer |
| **MatrikkelenhetServiceWS.wsdl** | Matrikkelenhet-søk | `findMatrikkelenheter()` | Server-side filter: Finn matrikkelenheter for person/org |
| **NedlastningServiceWS.wsdl** | Bulk-nedlasting | `findObjekterEtterId()` | Bulk-download med cursor-paginering |
| **PersonServiceWS.wsdl** | Person-søk | `findPersonIdByNummer()` | Slå opp PersonId (kan returnere 404) |
| **BruksenhetServiceWS.wsdl** | Bruksenhet-søk | `findBruksenheterForMatrikkelenheter()` | Finn bruksenheter for spesifikke matrikkelenheter |
| **BygningServiceWS.wsdl** | Bygning-søk | Ikke brukt i denne implementasjonen | - |
| **AdresseServiceWS.wsdl** | Adresse-søk | `findAdresserForMatrikkelenheter()` | Finn adresser for spesifikke matrikkelenheter |

### WSDL-filer Plassering

Alle WSDL-filer lastes ned fra:
```
https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/[ServiceName]?wsdl
```

Eksempel:
- `https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/StoreServiceWS?wsdl`
- `https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/MatrikkelenhetServiceWS?wsdl`

**Produksjon:**
```
https://wsweb.matrikkel.no/matrikkel-ws-v1.0/[ServiceName]?wsdl
```

---

## MatrikkelContext og Autentisering

### MatrikkelContext (Påkrevd i ALLE API-kall)

Alle SOAP-operasjoner krever en `MatrikkelContext`-parameter. Dette objektet inneholder:

#### Påkrevde Felter

```
MatrikkelContext:
  locale: "no_NO" (string)
  brukOriginaleKoordinater: false (boolean)
  koordinatsystemKodeId: 
    value: 22 (integer) # 22 = EPSG:25832 (UTM Zone 32N)
  systemVersion: "1.0" (string)
  klientIdentifikasjon: "matrikkel-integration" (string)
  snapshotVersion:
    timestamp: "9999-01-01T00:00:00+01:00" (XMLGregorianCalendar)
```

#### KRITISK: snapshotVersion

**MÅ settes til fremtidig dato (9999-01-01)** for å unngå "historical data permission" feil!

**Eksempel XML:**
```xml
<snapshotVersion>
  <timestamp>9999-01-01T00:00:00+01:00</timestamp>
</snapshotVersion>
```

**Hvorfor?** API-en tolker manglende eller gammel snapshotVersion som forespørsel om historiske data, som krever spesielle tilganger.

### Autentisering

SOAP-klienten må settes opp med **HTTP Basic Authentication**:

```
Username: [kommunenavn]_test (f.eks. "bergen_test")
Password: [levert av Kartverket]
```

**Settes i SOAP header:**
```xml
<wsse:UsernameToken>
  <wsse:Username>bergen_test</wsse:Username>
  <wsse:Password Type="PasswordText">[password]</wsse:Password>
</wsse:UsernameToken>
```

---

## Paginering med MatrikkelBubbleId

### Cursor-basert Paginering

API-en bruker **cursor-basert paginering** for bulk-nedlasting. Dette er IKKE offset/limit-paginering!

### Konsept

```
MatrikkelBubbleId = Cursor som peker til siste objekt i forrige batch
```

**Flyt:**
1. Første request: cursor = null → får objekter 1-500 + cursor til objekt 500
2. Andre request: cursor = 500 → får objekter 501-1000 + cursor til objekt 1000
3. Tredje request: cursor = 1000 → får objekter 1001-1500 + cursor til objekt 1500
4. Siste request: cursor = 1500 → får objekter 1501-1523 (mindre enn batch size → ferdig!)

### MatrikkelBubbleId Struktur

**MatrikkelBubbleId er en abstrakt base-klasse:**
```
MatrikkelBubbleId (abstract)
├── MatrikkelenhetId (value: Long)
├── PersonId (value: Long)
├── BruksenhetId (value: Long)
├── BygningId (value: Long)
├── AdresseId (value: Long)
├── VegId (value: Long)
└── ...etc
```

**Eksempel JSON-representasjon:**
```json
{
  "type": "MatrikkelenhetId",
  "value": 123456789
}
```

### Paginerings-algoritme

**Pseudokode:**
```
function downloadAllObjects(domainklasse, filter):
    cursor = null
    batchSize = 500
    allObjects = []
    
    while true:
        batch = API.findObjekterEtterId(
            cursor,           # null for første batch
            domainklasse,     # f.eks. MATRIKKELENHET
            filter,           # JSON filter (f.eks. kommune)
            batchSize,        # Max objekter per batch
            context           # MatrikkelContext
        )
        
        if batch.isEmpty():
            break  # Ingen flere objekter
        
        allObjects.addAll(batch.items)
        
        if batch.size() < batchSize:
            break  # Siste batch (færre objekter enn batchSize)
        
        # Hent cursor for neste batch (siste objektets ID)
        lastObject = batch.items.last()
        cursor = lastObject.getId()  # MatrikkelBubbleId
    
    return allObjects
```

### KRITISKE Tekniske Detaljer (for SOAP-klient implementasjon)

#### 1. Parameterrekkefølge (SOAP Message Structure)

**WSDL XSD definerer denne EKSAKTE rekkefølgen:**

```xml
<xs:complexType name="findObjekterEtterId">
  <xs:sequence>
    <xs:element name="matrikkelBubbleId" nillable="true" type="MatrikkelBubbleId"/>
    <xs:element name="domainklasse" type="Domainklasse"/>
    <xs:element name="filter" nillable="true" type="xs:string"/>
    <xs:element name="maksAntall" type="xs:int"/>
    <xs:element name="matrikkelContext" type="MatrikkelContext"/>
  </xs:sequence>
</xs:complexType>
```

**SOAP-klienten MÅ sende parametere i denne rekkefølgen:**

1. `matrikkelBubbleId` (kan være `null`/`nil` for første batch)
2. `domainklasse` (enum: MATRIKKELENHET, BYGNING, VEG, etc.)
3. `filter` (JSON string, kan være `null`/`nil`)
4. `maksAntall` (integer: batch size)
5. `matrikkelContext` (kompleks objekt med snapshotVersion)

**PHP-fallgruve:** Mange SOAP-klienter i PHP sender parametere som associative array, men rekkefølgen er kritisk! Bruk SoapClient med riktig argument-rekkefølge:

```php
// ❌ FEIL (PHP) - Associative array kan endre rekkefølge
$params = [
    'context' => $context,
    'cursor' => $cursor,
    'domainklasse' => 'MATRIKKELENHET'
];

// ✅ RIKTIG (PHP) - Eksplisitt rekkefølge med stdClass
$params = new stdClass();
$params->matrikkelBubbleId = $cursor;  // 1. cursor
$params->domainklasse = 'MATRIKKELENHET';  // 2. domainklasse
$params->filter = '{"kommunefilter": ["4601"]}';  // 3. filter
$params->maksAntall = 500;  // 4. batch size
$params->matrikkelContext = $context;  // 5. context

$result = $soapClient->findObjekterEtterId($params);
```

#### 2. MatrikkelBubbleId Struktur

**MatrikkelBubbleId er et kompleks objekt, IKKE bare et tall:**

```xml
<xs:complexType name="MatrikkelBubbleId">
  <xs:sequence>
    <xs:element name="value" type="xs:long"/>
  </xs:sequence>
</xs:complexType>
```

**Cursor må være objekt med `value` property:**

```php
// ❌ FEIL
$cursor = 123456789;  // Bare et tall

// ✅ RIKTIG
$cursor = new stdClass();
$cursor->value = 123456789;  // Objekt med value property
```

**Java-kode (fungerer automatisk):**
```java
// Java SOAP client genererer korrekt klasse automatisk
MatrikkelenhetId cursor = lastObject.getId();  // Riktig type
nedlastningService.findObjekterEtterId(cursor, ...);  // Type-safe
```

#### 3. Første Batch - NULL vs NIL

**For første batch MÅ cursor være:**
- **Java:** `null`
- **PHP:** `null` ELLER SOAP `xsi:nil="true"`
- **XML:** `<matrikkelBubbleId xsi:nil="true"/>`

**Eksempel XML (første request):**
```xml
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <ns1:findObjekterEtterId xmlns:ns1="...">
      <matrikkelBubbleId xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>
      <domainklasse>MATRIKKELENHET</domainklasse>
      <filter>{"kommunefilter": ["4601"]}</filter>
      <maksAntall>500</maksAntall>
      <matrikkelContext>...</matrikkelContext>
    </ns1:findObjekterEtterId>
  </soap:Body>
</soap:Envelope>
```

**Eksempel XML (andre request med cursor):**
```xml
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <ns1:findObjekterEtterId xmlns:ns1="...">
      <matrikkelBubbleId xsi:type="ns2:MatrikkelenhetId">
        <value>123456789</value>
      </matrikkelBubbleId>
      <domainklasse>MATRIKKELENHET</domainklasse>
      <filter>{"kommunefilter": ["4601"]}</filter>
      <maksAntall>500</maksAntall>
      <matrikkelContext>...</matrikkelContext>
    </ns1:findObjekterEtterId>
  </soap:Body>
</soap:Envelope>
```

**Legg merke til:** Cursor har `xsi:type="ns2:MatrikkelenhetId"` - subklasse av MatrikkelBubbleId!

#### 4. Type Casting (Polymorfi)

**MatrikkelBubbleId er abstrakt base-klasse med mange subklasser:**
- MatrikkelenhetId (for matrikkelenheter)
- BygningId (for bygninger)
- VegId (for veger)
- AdresseId (for adresser)
- etc.

**Response returnerer riktig subklasse:**
```php
// Responsen inneholder faktisk type-informasjon
$lastObject = $batch->items[count($batch->items) - 1];
$cursor = $lastObject->id;  // Dette er MatrikkelenhetId, ikke generisk MatrikkelBubbleId

// PHP må bevare type-informasjonen når cursor sendes i neste request!
// Bruk stdClass og set xsi:type eksplisitt hvis nødvendig
```

### Viktige Detaljer

1. **Første batch:** cursor = null (IKKE 0 eller empty string!) med `xsi:nil="true"`
2. **Siste batch:** Når batch.size < batchSize → ferdig
3. **Cursor-oppdatering:** Cursor = siste objektets ID fra forrige batch (kompleks objekt, ikke bare tall!)
4. **Thread-safety:** Hver paginerings-session må ha sin egen cursor-variabel
5. **Batch size:** Anbefalt 500 objekter (API maksimum er typisk 5000)
6. **Parameterrekkefølge:** KRITISK for SOAP - følg XSD sequence eksakt!
7. **Type preservation:** Cursor må bevare subtype (MatrikkelenhetId, ikke generisk MatrikkelBubbleId)

---

## Fullstendig Import-prosess

### Fase 0: Forberedelse

#### Steg 0.1: Verifiser/Opprett Kommune i Database

**WSDL:** KommuneServiceWS.wsdl + StoreServiceWS.wsdl

**Prosess:**

1. **Sjekk om kommune finnes i lokal database:**
   ```sql
   SELECT * FROM matrikkel_kommuner WHERE kommunenummer = 4601
   ```

2. **Hvis ikke funnet → Hent fra API:**

   **Steg 2a: Finn KommuneId**
   ```
   SOAP Operation: KommuneService.findKommuneIdForIdent()
   
   Request:
     kommuneIdent:
       kommunenummer: "4601"
     context: MatrikkelContext
   
   Response:
     KommuneId:
       value: 987654321 (Long)
   ```

   **Steg 2b: Hent full Kommune-objekt**
   ```
   SOAP Operation: StoreService.getObjects()
   
   Request:
     bubbleIdList:
       items:
         - KommuneId(value: 987654321)
     context: MatrikkelContext
   
   Response:
     bubbleObjectList:
       items:
         - Kommune:
             id: 987654321
             kommunenummer: 4601
             kommunenavn: "Bergen"
             fylkesnummer: 46
             fylkesnavn: "Vestland"
             koordinatsystemKode: "22"
             ...
   ```

3. **Lagre kommune i database:**
   ```sql
   INSERT INTO matrikkel_kommuner (
     kommunenummer, kommunenavn, fylkesnummer, fylkesnavn, ...
   ) VALUES (
     4601, 'Bergen', 46, 'Vestland', ...
   )
   ```

---

### Fase 1: Server-side Filtrering (Finn Matrikkelenhet-IDer)

**WSDL:** MatrikkelenhetServiceWS.wsdl

**Formål:** Finn HVILKE matrikkelenheter som tilhører angitt organisasjon/person UTEN å laste ned komplette objekter.

#### Steg 1.1: Finn Matrikkelenhet-IDer

```
SOAP Operation: MatrikkelenhetService.findMatrikkelenheter()

Request:
  matrikkelenhetsokModel:
    kommunenummer: "4601" (String)
    nummerForPerson: "964338531" (String) # Organisasjonsnummer eller personnummer
  context: MatrikkelContext

Response:
  matrikkelenhetIdList:
    items:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
      - MatrikkelenhetId(value: 123456791)
      ... (totalt f.eks. 85 IDer)
```

**Viktig:** Dette API-kallet returnerer KUN IDer, IKKE komplette objekter!

**Resultat:** Liste med matrikkelenhet-IDer (f.eks. 85 IDer for Bergen Kommune)

---

### Fase 2: Hent Komplette Matrikkelenhet-objekter

**WSDL:** StoreServiceWS.wsdl

**Formål:** Hent komplette Matrikkelenhet-objekter basert på IDene fra Fase 1.

#### Steg 2.1: Batch-henting av Matrikkelenheter

```
SOAP Operation: StoreService.getObjects()

Request:
  bubbleIdList:
    items:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
      ... (max 500 IDer per batch)
  context: MatrikkelContext

Response:
  bubbleObjectList:
    items:
      - Matrikkelenhet:
          id: 123456789
          kommunenummer: 4601
          gardsnummer: 123
          bruksnummer: 45
          festenummer: 0
          seksjonsnummer: 0
          matrikkelnummer: "4601-123/45"
          tinglyst: true
          skyld: 125000.0
          eierforhold:
            - Eierforhold:
                personId: 987654321
                andel: 1/1
          ... (komplette data)
      - Matrikkelenhet: ...
```

**Batch-strategi:**
- Split IDer i batches på 500 objekter
- Gjør 500 API-kall om nødvendig: batch 1 (IDs 1-500), batch 2 (IDs 501-1000), etc.
- Slå sammen alle batches til en komplett liste

**Eksempel:**
- 85 matrikkelenheter → 1 batch (85 objekter)
- 1,500 matrikkelenheter → 3 batches (500 + 500 + 500)

#### Steg 2.2: Mapper til Database-entiteter

For hver Matrikkelenhet:
```
1. Ekstraher felt-verdier
2. Konverter datatyper (API bruker XMLGregorianCalendar, database bruker timestamp)
3. Opprett database-entitet
```

#### Steg 2.3: Lagre Matrikkelenheter

```sql
INSERT INTO matrikkel_matrikkelenheter (
  matrikkel_matrikkelenhet_id,
  kommunenummer,
  gardsnummer,
  bruksnummer,
  festenummer,
  seksjonsnummer,
  matrikkelnummer_tekst,
  tinglyst,
  skyld,
  ...
) VALUES (
  123456789,
  4601,
  123,
  45,
  0,
  0,
  '4601-123/45',
  true,
  125000.0,
  ...
) ON CONFLICT (matrikkel_matrikkelenhet_id) DO UPDATE SET ...
```

**Viktig:** Bruk `ON CONFLICT ... DO UPDATE` for å støtte oppdateringer.

---

### Fase 3: Hent Eierforhold og Personer

**WSDL:** StoreServiceWS.wsdl

**Formål:** Hent eiere (personer og juridiske personer) for alle matrikkelenheter.

#### Steg 3.1: Ekstraher PersonId fra Eierforhold

Fra Matrikkelenhet-objektene hentet i Fase 2:
```
For hver Matrikkelenhet:
  For hver Eierforhold:
    personIds.add(eierforhold.personId)
```

**Resultat:** Liste med unike PersonId (f.eks. 50 unike PersonId fra 85 matrikkelenheter)

#### Steg 3.2: Hent Person-objekter

```
SOAP Operation: StoreService.getObjects()

Request:
  bubbleIdList:
    items:
      - PersonId(value: 987654321)
      - PersonId(value: 987654322)
      ... (max 500 per batch)
  context: MatrikkelContext

Response:
  bubbleObjectList:
    items:
      - FysiskPerson (subklasse av Person):
          id: 987654321
          nummer: "01018012345" # PERSONNUMMER eller ORG.NUMMER lagres HER!
          navn: "Ola Nordmann"
          fodselsnummer: null # ALLTID NULL - ikke bruk!
          fodselsaar: 1980
          ...
      - JuridiskPerson (subklasse av Person):
          id: 987654322
          nummer: "964338531" # ORGANISASJONSNUMMER lagres HER!
          navn: "Bergen Kommune"
          organisasjonsnummer: null # ALLTID NULL - ikke bruk!
          organisasjonsform: "KOMM"
          ...
```

**KRITISK DISCOVERY:** 
- Både personnummer OG organisasjonsnummer lagres i `Person.nummer`!
- Subklasse-feltene (`fodselsnummer`, `organisasjonsnummer`) er ALLTID NULL!
- **Bruk ALLTID** base-klassen `Person.nummer` for filtrering og søk.

#### Steg 3.3: Lagre Personer

**Database-struktur:** Single Table Inheritance

```sql
-- Base table
INSERT INTO matrikkel_personer (
  matrikkel_person_id,
  person_type,
  nummer,
  navn,
  sist_lastet_ned
) VALUES (
  987654321,
  'FysiskPerson',
  '01018012345',
  'Ola Nordmann',
  NOW()
)

-- Subclass table (hvis FysiskPerson)
INSERT INTO matrikkel_fysiske_personer (
  fysisk_person_entity_id,
  fodselsnummer  -- NULL (deprecated)
) VALUES (
  [JPA generated ID],
  NULL
)

-- Subclass table (hvis JuridiskPerson)
INSERT INTO matrikkel_juridiske_personer (
  juridisk_person_entity_id,
  organisasjonsnummer,  -- NULL (deprecated)
  organisasjonsform
) VALUES (
  [JPA generated ID],
  NULL,
  'KOMM'
)
```

#### Steg 3.4: Lagre Eierforhold

```sql
INSERT INTO matrikkel_eierforhold (
  matrikkelenhet_id,
  fysisk_person_id,           -- FK til matrikkel_personer.id (hvis fysisk person)
  juridisk_person_entity_id,  -- FK til matrikkel_personer.id (hvis juridisk person)
  andel_teller,
  andel_nevner,
  sist_lastet_ned
) VALUES (
  123456789,
  [person_id from database],
  NULL,
  1,
  1,
  NOW()
)
```

**Linking-strategi (N+1 prevention):**
```
1. Hent alle Person-objekter fra API (batch)
2. Load eksisterende personer fra database til Map<matrikkel_person_id, person_id>
3. For hver Eierforhold: lookup person_id i Map (O(1) operasjon)
4. Opprett Eierforhold med correct foreign keys
```

---

### Fase 4: Hent Bruksenheter (Two-step Pattern)

**WSDL:** BruksenhetServiceWS.wsdl + StoreServiceWS.wsdl

**Formål:** Hent bruksenheter (boliger, næringslokaler) for alle matrikkelenheter.

#### Steg 4.1: Finn Bruksenhet-IDer

```
SOAP Operation: BruksenhetService.findBruksenheterForMatrikkelenheter()

Request:
  matrikkelenhetIds:  # WSDL parameter name (not matrikkelenhetIdList!)
    item:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
      ... (max 500 per batch)
  context: MatrikkelContext

Response:
  matrikkelenhetIdTilBruksenhetIdsMap:
    entry:
      - key: MatrikkelenhetId(value: 123456789)
        value:
          item:
            - BruksenhetId(value: 555666777)
            - BruksenhetId(value: 555666778)
      - key: MatrikkelenhetId(value: 123456790)
        value:
          item:
            - BruksenhetId(value: 555666779)
```

**Resultat:** Mapping fra matrikkelenhet-ID til liste av bruksenhet-IDer.

#### Steg 4.2: Hent Komplette Bruksenhet-objekter

```
SOAP Operation: StoreService.getObjects()

Request:
  bubbleIdList:
    items:
      - BruksenhetId(value: 555666777)
      - BruksenhetId(value: 555666778)
      - BruksenhetId(value: 555666779)
      ... (max 500 per batch)
  context: MatrikkelContext

Response:
  bubbleObjectList:
    items:
      - Bruksenhet:
          id: 555666777
          bruksenhetsnummer: "H0101"
          bruksenhetstype: "Bolig"
          bruksareal: 85.5
          antallRom: 3
          kjokkentype: "Separat kjøkken"
          badWcType: "Bad/WC"
          bygningId: 888999000  # FK til bygning
          matrikkelenhetId: 123456789  # FK til matrikkelenhet
          ...
```

#### Steg 4.3: Lagre Bruksenheter

```sql
INSERT INTO matrikkel_bruksenheter (
  bruksenhet_id,
  matrikkelenhet_id,
  lopenummer,
  uuid,
  bruksenhettype_kode_id,
  etasjeplan_kode_id,
  etasjenummer,
  adresse_id,
  antall_rom,
  antall_bad,
  antall_wc,
  bruksareal,
  sist_lastet_ned,
  opprettet,
  oppdatert
) VALUES (
  555666777,
  123456789,
  1,
  '6b4902ef-8dc8-5c2e-a884-d368ec2d1e1d',
  'Bolig',
  null,
  1,
  256844037,
  3,
  1,
  1,
  85.5,
  NOW(),
  NOW(),
  NOW()
)
```

**Merk:** `bygning_id` er fjernet fra tabellen. Bruksenhet ↔ Bygning kobling håndteres via junction table eller bygninger importeres separat.

---

### Fase 5: Hent Bygninger (Two-step Pattern)

**WSDL:** BygningServiceWS.wsdl + StoreServiceWS.wsdl

**Formål:** Hent bygninger for spesifikke matrikkelenheter (API-side filtrering!).

#### Steg 5.1: Finn Bygning-IDer

```
SOAP Operation: BygningService.findByggForMatrikkelenheter()

Request:
  matrikkelenhetIdList:
    items:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
      ... (max 200 per batch - BygningService anbefaling)
  context: MatrikkelContext

Response:
  matrikkelenhetIdTilByggIdsMap:
    entries:
      - matrikkelenhetId: 123456789
        byggIds:
          - ByggId(value: 888999000)
          - ByggId(value: 888999001)
      - matrikkelenhetId: 123456790
        byggIds:
          - ByggId(value: 888999002)
```

**Resultat:** Mapping fra matrikkelenhet-ID til liste av bygning-IDer.

#### Steg 5.2: Hent Komplette Bygning-objekter

```
SOAP Operation: StoreService.getObjects()

Request:
  bubbleIdList:
    items:
      - BygningId(value: 888999000)
      - BygningId(value: 888999001)
      - BygningId(value: 888999002)
      ... (max 1000 per batch - StoreService kan håndtere større batches)
  context: MatrikkelContext

Response:
  bubbleObjectList:
    items:
      - Bygning:
          id: 888999000
          bygningsnummer: 123456
          kommunenummer: 4601
          bygningstype: "Enebolig"
          bebygdAreal: 120.5
          naeringsKode: "Bolig"
          ...
```

#### Steg 5.3: Lagre Bygninger

```sql
INSERT INTO matrikkel_bygninger (
  bygning_id,
  bygningsnummer,
  kommunenummer,
  bygningstype,
  bebygd_areal,
  naerings_kode,
  sist_lastet_ned
) VALUES (
  888999000,
  123456,
  4601,
  'Enebolig',
  120.5,
  'Bolig',
  NOW()
)
```

---

### Fase 6: Hent Veger (KRITISK: Må skje FØR adresser!)

**WSDL:** NedlastningServiceWS.wsdl

**Formål:** Hent alle veger/gater for kommunen (påkrevd for vegadresse-mapping).

#### Steg 6.1: Bulk-download Veger

```
SOAP Operation: NedlastningService.findObjekterEtterId()

Loop (cursor-paginering):
  Request:
    cursor: null (første gang)
    domainklasse: VEG
    filter: '{"kommunefilter": ["4601"]}'
    batchSize: 500
    context: MatrikkelContext
  
  Response:
    bubbleObjectList:
      items:
        - Veg:
            id: 111222333
            adressenavn: "Storgata"
            kommunenummer: 4601
            ...
```

**Eksempel:** Bergen har ca. 1,944 veger.

#### Steg 6.2: Lagre Veger

```sql
INSERT INTO matrikkel_veger (
  veg_id,
  adressenavn,
  kommunenummer,
  sist_lastet_ned
) VALUES (
  111222333,
  'Storgata',
  4601,
  NOW()
)
```

**KRITISK:** Veger MÅ lagres FØR adresser! Vegadresser har foreign key til veger.

---

### Fase 7: Hent Adresser (Two-step Pattern)

**WSDL:** AdresseServiceWS.wsdl + StoreServiceWS.wsdl

**Formål:** Hent adresser for alle matrikkelenheter.

#### Steg 7.1: Finn Adresse-IDer

```
SOAP Operation: AdresseService.findAdresserForMatrikkelenheter()

Request:
  matrikkelenhetIds:
    items:
      - MatrikkelenhetId(value: 123456789)
      - MatrikkelenhetId(value: 123456790)
      ... (max 500 per batch)
  context: MatrikkelContext

Response:
  matrikkelenhetIdTilAdresseIdsMap:
    entries:
      - matrikkelenhetId: 123456789
        adresseIds:
          - AdresseId(value: 444555666)
      - matrikkelenhetId: 123456790
        adresseIds:
          - AdresseId(value: 444555667)
```

#### Steg 7.2: Hent Komplette Adresse-objekter

```
SOAP Operation: StoreService.getObjects()

Request:
  bubbleIdList:
    items:
      - AdresseId(value: 444555666)
      - AdresseId(value: 444555667)
      ... (max 500 per batch)
  context: MatrikkelContext

Response:
  bubbleObjectList:
    items:
      - Vegadresse (subklasse av Adresse):
          id: 444555666
          kommunenummer: 4601
          vegId: 111222333  # FK til matrikkel_veger
          husnummer: 42
          bokstav: "A"
          ...
      - Matrikkeladresse (subklasse av Adresse):
          id: 444555667
          kommunenummer: 4601
          gardsnummer: 123
          bruksnummer: 45
          ...
```

**Adresse-typer:**
1. **Vegadresse** (vanligst): Gateadresse med veg, husnummer, bokstav
2. **Matrikkeladresse** (sjelden): Matrikkelnummer som adresse

#### Steg 7.3: Lagre Adresser

**Base table (Single Table Inheritance):**
```sql
INSERT INTO matrikkel_adresser (
  adresse_id,
  adresse_type,
  kommunenummer,
  sist_lastet_ned
) VALUES (
  444555666,
  'Vegadresse',
  4601,
  NOW()
)
```

**Vegadresse (subclass):**
```sql
INSERT INTO matrikkel_vegadresser (
  vegadresse_entity_id,
  veg_id,
  husnummer,
  bokstav
) VALUES (
  444555666,
  111222333,  -- FK til matrikkel_veger (MÅ eksistere!)
  42,
  'A'
)
```

**Dependency:** Vegadresse krever at Veg-entiteter eksisterer i database!

---

## To-stegs API-pattern

### Konsept

Mange API-operasjoner bruker **to-stegs pattern** for optimal ytelse:

**Steg 1: Finn IDer** (server-side filtrering)
- Rask operasjon
- Returnerer kun IDer (små objekter)
- API gjør filtreringen

**Steg 2: Hent Komplette Objekter** (batch-henting)
- Hent kun objekter du trenger
- Batch-prosessering (500 per batch)
- Redusert dataoverføring

### Eksempler

#### Matrikkelenheter for Person

```
Steg 1: MatrikkelenhetService.findMatrikkelenheter()
  Input: kommunenummer, nummerForPerson
  Output: List<MatrikkelenhetId> (kun IDer)

Steg 2: StoreService.getObjects()
  Input: List<MatrikkelenhetId>
  Output: List<Matrikkelenhet> (komplette objekter)
```

#### Bruksenheter for Matrikkelenheter

```
Steg 1: BruksenhetService.findBruksenheterForMatrikkelenheter()
  Input: List<MatrikkelenhetId>
  Output: Map<MatrikkelenhetId, List<BruksenhetId>>

Steg 2: StoreService.getObjects()
  Input: List<BruksenhetId>
  Output: List<Bruksenhet> (komplette objekter)
```

#### Bygninger for Matrikkelenheter

```
Steg 1: BygningService.findByggForMatrikkelenheter()
  Input: List<MatrikkelenhetId>
  Output: Map<MatrikkelenhetId, List<ByggId>>

Steg 2: StoreService.getObjects()
  Input: List<ByggId>
  Output: List<Bygning> (komplette objekter)
```

#### Adresser for Matrikkelenheter

```
Steg 1: AdresseService.findAdresserForMatrikkelenheter()
  Input: List<MatrikkelenhetId>
  Output: Map<MatrikkelenhetId, List<AdresseId>>

Steg 2: StoreService.getObjects()
  Input: List<AdresseId>
  Output: List<Adresse> (komplette objekter)
```

### Fordeler med To-stegs Pattern

1. **Server-side filtrering:** API gjør filtreringen (raskere)
2. **Redusert dataoverføring:** Last kun nødvendige objekter
3. **Batch-processing:** 500 objekter per request (effektivt)
4. **Fleksibilitet:** Kan kombinere IDer fra flere kilder

---

## Datamodell og Relasjoner

### Entity Relationship Diagram

```
Kommune (1) ←─────── (N) Matrikkelenhet
                           ├─── (N) Eierforhold ──→ (1) Person (FysiskPerson/JuridiskPerson)
                           ├─── (N) Bruksenhet ────→ (1) Bygning
                           └─── (N) Adresse (Vegadresse/Matrikkeladresse)

Veg (1) ←────────── (N) Vegadresse (extends Adresse)
```

### Database-tabeller

1. **matrikkel_kommuner** - Kommuner
2. **matrikkel_matrikkelenheter** - Matrikkelenheter (grunndata)
3. **matrikkel_personer** - Personer (base table, Single Table Inheritance)
4. **matrikkel_fysiske_personer** - Fysiske personer (extends Person)
5. **matrikkel_juridiske_personer** - Juridiske personer (extends Person)
6. **matrikkel_eierforhold** - Eierforhold (linking table)
7. **matrikkel_bygninger** - Bygninger
8. **matrikkel_bruksenheter** - Bruksenheter
9. **matrikkel_veger** - Veger
10. **matrikkel_adresser** - Adresser (base table, Single Table Inheritance)
11. **matrikkel_vegadresser** - Vegadresser (extends Adresse)

### Inheritance-strategier

**Single Table Inheritance** brukes for:
- **Person** → FysiskPerson, JuridiskPerson
- **Adresse** → Vegadresse, Matrikkeladresse

**Hvorfor?** API returnerer polymorfiske objekter (f.eks. Person kan være FysiskPerson ELLER JuridiskPerson).

---

## Optimalisering og Ytelse

### Server-side Filtrering (99% reduksjon)

**Før (bulk download):**
```
Kommune 1103 (Stavanger):
- Totalt: ~15,000 matrikkelenheter
- Organisasjon 964965226: 4,744 matrikkelenheter
- Unødvendig nedlasting: 10,256 matrikkelenheter (68%)
```

**Etter (server-side filtrering):**
```
Step 1: MatrikkelenhetService → 4,744 IDer (~1 sekund)
Step 2: StoreService.getObjects → 4,744 objekter (~12 sekunder)
Total: ~13 sekunder vs ~5 minutter (bulk)
```

### Batch-processing

**Anbefalt batch-størrelse:**
- Matrikkelenheter: 500
- Personer: 500
- Bruksenheter: 500
- Bygninger: 500
- Adresser: 500

**Algoritme:**
```
function processBatches(ids, batchSize, fetchFunction):
    totalBatches = ceil(ids.size / batchSize)
    allObjects = []
    
    for i = 0 to ids.size step batchSize:
        batchIds = ids[i : min(i + batchSize, ids.size)]
        batchNumber = (i / batchSize) + 1
        
        log("Processing batch {}/{}: {} objects", 
            batchNumber, totalBatches, batchIds.size)
        
        batchObjects = fetchFunction(batchIds)
        allObjects.addAll(batchObjects)
    
    return allObjects
```

### Per-Batch Database Commits

**Kritisk for ytelse:**
```
# ❌ FEIL - Lag IKKE outer transaction!
transaction:
    for batch in batches:
        saveBatch(batch)  # Inner transaction har ingen effekt

# ✅ RIKTIG - Ingen outer transaction
for batch in batches:
    transaction:
        saveBatch(batch)  # Commits umiddelbart etter hver batch
```

**Ytelse:**
- ❌ Feil: 1.8 objekter/sekund
- ✅ Riktig: 583 objekter/sekund

### N+1 Query Prevention

**❌ FEIL:**
```
for person in persons:
    dbPerson = database.findByMatrikkelPersonId(person.id)  # N queries!
    createEierforhold(dbPerson)
```

**✅ RIKTIG:**
```
# Load ALL persons into Map (1 query)
personMap = database.findAll()
    .toMap(p -> p.matrikkelPersonId -> p.id)

# O(1) lookup for each person
for person in persons:
    dbPersonId = personMap.get(person.id)  # In-memory lookup!
    createEierforhold(dbPersonId)
```

**Ytelse:**
- ❌ Feil: 348,000 database queries
- ✅ Riktig: 3 database queries

### Rekkefølge av Operasjoner

**KRITISK rekkefølge:**

```text
1. Kommune (hvis ikke finnes)
2. Matrikkelenheter
3. Personer
4. Eierforhold (krever både matrikkelenheter og personer)
5. Bruksenheter (two-step pattern med BruksenhetService)
6. Bygninger (two-step pattern med BygningService)
7. Veger (MÅ komme før adresser!)
8. Adresser (krever veger i database)
```

**Hvorfor?** Foreign key constraints!

**Viktig:** Bygninger og bruksenheter kan hentes i parallell da de har uavhengige foreign keys.

---

## Feilhåndtering

### Common Errors

#### 1. "Historical data permission denied"

**Årsak:** snapshotVersion ikke satt eller for gammel dato.

**Løsning:** Sett snapshotVersion til 9999-01-01.

#### 2. "ClassCastException" (kun relevant for Java/C#)

**Årsak:** WSDL-tjenester generert til forskjellige packages/namespaces.

**Løsning:** Bruk samme package/namespace for alle WSDL-tjenester som deler XSD-schemas.

#### 3. "Foreign key constraint violation"

**Årsak:** Prøver å lagre entitet før parent-entitet eksisterer.

**Løsning:** Følg riktig rekkefølge (se [Rekkefølge av Operasjoner](#rekkefølge-av-operasjoner)).

#### 4. PersonService.findPersonIdByNummer() returnerer 404

**Årsak:** Person finnes ikke i API eller access restrictions.

**Løsning:** Bruk database-fallback (personer lastet fra StoreService i Fase 3).

#### 5. Alle adresser skippes (0 saved)

**Årsak:** Veger ikke lastet først.

**Løsning:** Last veger FØR adresser (se Fase 6).

---

## Ytelsessammenligning

### Filtrert Import (4,744 matrikkelenheter)

| Operasjon | Tid | Metode |
|-----------|-----|--------|
| Finn matrikkelenhet-IDer | ~1 sek | MatrikkelenhetService (server-filter) |
| Hent matrikkelenheter | ~12 sek | StoreService (10 batches × 500) |
| Hent personer | ~5 sek | StoreService (batch) |
| Hent bruksenheter | ~8 sek | Two-step pattern (API-filtered) |
| Hent bygninger | ~6 sek | Two-step pattern (API-filtered) |
| Hent veger | ~3 sek | Bulk download (once per kommune) |
| Hent adresser | ~4 sek | Two-step pattern (API-filtered) |
| **Total** | **~39 sek** | 99% raskere enn bulk! |

### Bulk Import (15,000 matrikkelenheter - Bergen)

| Operasjon | Tid | Metode |
|-----------|-----|--------|
| Hent matrikkelenheter | ~5 min | NedlastningService (cursor-paginering) |
| Hent personer | ~10 min | StoreService (alle personer) |
| Hent bruksenheter | ~15 min | Two-step eller bulk + client-filter |
| Hent bygninger | ~10 min | Two-step eller bulk + client-filter |
| Hent veger | ~3 min | Bulk download |
| Hent adresser | ~10 min | Two-step eller bulk + client-filter |
| **Total** | **~53 min** | Ikke anbefalt for store kommuner |

**Konklusjon:** Server-side filtrering er **~80x raskere** enn bulk import!

---

## Referanse: API Endpoints

### Test-miljø
```
Base URL: https://wsweb-test.matrikkel.no/matrikkel-ws-v1.0/

Tjenester:
- StoreServiceWS
- MatrikkelenhetServiceWS
- NedlastningServiceWS
- PersonServiceWS
- BruksenhetServiceWS
- BygningServiceWS
- AdresseServiceWS
- KommuneServiceWS
```

### Produksjon
```
Base URL: https://wsweb.matrikkel.no/matrikkel-ws-v1.0/

(Samme tjenester som test)
```

---

## Konklusjon

Denne prosessen demonstrerer hvordan man effektivt henter matrikkeldata med **99% reduksjon i dataoverføring** gjennom:

1. **Server-side filtrering:** API gjør filtreringen (MatrikkelenhetService)
2. **To-stegs pattern:** Finn IDer → Hent objekter (optimal batch-henting)
3. **Cursor-basert paginering:** Effektiv bulk-download når nødvendig
4. **Batch-processing:** 500 objekter per request (minimere API-kall)
5. **Per-batch commits:** Minimere database-transaksjonsstørrelse
6. **N+1 prevention:** Load data into Maps (O(1) lookup)

**Nøkkelprinsipp:** Filtrer så tidlig som mulig i prosessen!

---

## Lisens

Dette dokumentet beskriver integrasjon med Kartverkets Matrikkel API og er ment for reproduksjon i andre programmeringsspråk.
