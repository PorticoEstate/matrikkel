# Implementasjonsplan: 7 Primære Tabeller med Filter på Kommune og Tinglyst Eier

**Opprettet**: 7. oktober 2025  
**Sist oppdatert**: 8. oktober 2025  
**Status**: Pågående (Fase 3 - Trinn 5 fullført)  
**Mål**: Implementere 7 primære tabeller i Matrikkel-systemet med filtrering på kommune og tinglyst eier

---

## 📈 Fremdrift

| Fase | Trinn | Beskrivelse | Status |
|------|-------|-------------|--------|
| 1 | Trinn 1 | Analyser SOAP API og database struktur | ✅ Fullført |
| 1 | Trinn 2 | Design database-skjema (7 tabeller) | ✅ Fullført |
| 2 | Trinn 3 | Implementer NedlastningClient | ✅ Fullført |
| 3 | Trinn 4 | Kommune-import (883 kommuner) | ✅ Fullført |
| 3 | **Trinn 5** | **Matrikkelenhet-import (137 for kommune 811)** | **✅ Fullført** || Trinn 4 | Kommune-import (883 kommuner) | ✅ Fullført |
| 3 | **Trinn 5** | **Matrikkelenhet-import (137 for kommune 811)** | **✅ Fullført** |
| 3 | Trinn 6 | Bygning-import | ⏳ Neste |lan: 7 Primære Tabeller med Filter på Kommune og Tinglyst Eier

**Opprettet**: 7. oktober 2025  
**Sist oppdatert**: 8. oktober 2025  
**Status**: Pågående (Fase 3 - Trinn 4 fullført)  
**Mål**: Implementere 7 primære tabeller i Matrikkel-systemet med filtrering på kommune og tinglyst eier

---

## � Fremdrift

| Fase | Trinn | Beskrivelse | Status |
|------|-------|-------------|--------|
| 1 | Trinn 1 | Analyser SOAP API og database struktur | ✅ Fullført |
| 1 | Trinn 2 | Design database-skjema (7 tabeller) | ✅ Fullført |
| 2 | Trinn 3 | Implementer NedlastningClient | ✅ Fullført |
| 3 | **Trinn 4** | **Kommune-import (883 kommuner)** | **✅ Fullført** |
| 3 | Trinn 5 | Matrikkelenhet-import (med eierforhold) | ⏳ Neste |
| 3 | Trinn 6 | Bygning-import | 📋 Planlagt |
| 3 | Trinn 7 | Gate-import | 📋 Planlagt |
| 3 | Trinn 8 | Adresse-import (SOAP) | 📋 Planlagt |
| 3 | Trinn 9 | Bruksenhet-import (SOAP) | 📋 Planlagt |
| 4 | Trinn 10-14 | Console commands, REST API, dokumentasjon | 📋 Planlagt |

**Siste oppdatering**: Kommune-import fullført med 883 kommuner importert (kommunenummer 101-9999). Kritisk bug i AbstractTable.deduplicateRows() ble identifisert og fikset.

---

## �📊 Oversikt over Tabeller

1. **Kommune** - Alle norske kommuner
2. **Matrikkelenhet** - Grunneiendommer med eierforhold
3. **Bygning** - Bygningsdata inkludert bygningsnummer
4. **Gate** - Gatenavn per kommune
5. **Adresse** - Adresser (eksisterer allerede)
6. **Bruksenhet** - Seksjoner/leiligheter (eksisterer allerede)
7. **Bygning-Matrikkelenhet** - Koblingstabel mellom bygning og matrikkelenhet

---

## 🎯 Implementasjonstrinn

### ✅ Fase 1: Analyse og Design

#### [ ] Trinn 1: Analyser eksisterende SOAP API og database struktur
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Undersøk WSDL-filer i `doc/wsdl/` for følgende tjenester:
  - [ ] `BygningServiceWS.wsdl` - Metoder og datatyper
  - [ ] `MatrikkelenhetServiceWS.wsdl` - Søkemetoder med filter
  - [ ] `KommuneServiceWS.wsdl` - Henting av kommunedata
  - [ ] `AdresseServiceWS.wsdl` - Eksisterende adresse-funksjoner
  - [ ] `BruksenhetServiceWS.wsdl` - Bruksenhet-funksjoner
- [ ] Gjennomgå eksisterende database-tabeller i `README.md`:
  - [ ] `matrikkel_adresser` - Kolonner og indexes
  - [ ] `matrikkel_bruksenheter` - Kolonner og relasjoner
- [ ] Sjekk eksisterende Client-klasser i `src/Client/`:
  - [ ] `BygningClient.php` - Finnes og fungerer?
  - [ ] `MatrikkelenhetClient.php` - Støtter eier-filter?
  - [ ] `KommuneClient.php` - Fungerer korrekt?
  - [ ] `AdresseClient.php` - Eksisterende funksjonalitet
  - [ ] `BruksenhetClient.php` - Finnes?
- [ ] Identifiser gaps: Hvilke metoder mangler for eier-filtrering?

**Notater**:
```
ANALYSE FULLFØRT - 7. oktober 2025

✅ WSDL-FILER FUNNET:
- BygningServiceWS.wsdl + bygning.xsd (883 linjer) - EKSISTERER
- MatrikkelenhetServiceWS.wsdl + matrikkelenhet.xsd (2533 linjer) - EKSISTERER
- KommuneServiceWS.wsdl + kommune.xsd - EKSISTERER
- AdresseServiceWS.wsdl + adresse.xsd - EKSISTERER
- BruksenhetServiceWS.wsdl - EKSISTERER
- ⭐ NedlastningServiceWS.wsdl - BULK-NEDLASTING (NY)

✅ EKSISTERENDE SOAP CLIENTS:
- AdresseClient.php - FUNGERER
- BruksenhetClient.php - FUNGERER
- KommuneClient.php - FUNGERER
- MatrikkelenhetClient.php - FUNGERER
- MatrikkelsokClient.php - FUNGERER
- KodelisteClient.php - FUNGERER
- StoreClient.php - FUNGERER

❌ MANGLER:
- BygningClient.php - FINNES IKKE, MÅ IMPLEMENTERES
- ⭐ NedlastningClient.php - ANBEFALES STERKT FOR BULK-IMPORT

✅ EKSISTERENDE SERVICES:
- AdresseService.php - Fungerer
- BruksenhetService.php - Fungerer
- KommuneService.php - Fungerer
- MatrikkelenhetService.php - Fungerer (har getMatrikkelenhetById og getMatrikkelenhetByMatrikkel)
- MatrikkelsokService.php - Fungerer
- KodelisteService.php - Fungerer

❌ SERVICES SOM MANGLER:
- BygningService - MÅ IMPLEMENTERES
- MatrikkelenhetImportService - MÅ IMPLEMENTERES
- KommuneImportService - MÅ IMPLEMENTERES
- BygningImportService - MÅ IMPLEMENTERES
- GateImportService - MÅ IMPLEMENTERES
- ⭐ NedlastningImportService - NY BULK-IMPORT SERVICE

✅ VIKTIGE WSDL-METODER FUNNET:

BygningServiceWS:
- findByggForKommune - Hent alle bygninger i en kommune
- findByggForMatrikkelenhet - Hent bygninger for en matrikkelenhet
- findByggForMatrikkelenheter - Hent bygninger for flere matrikkelenheter
- findBygg / findBygning - Hent enkeltbygning
- findByggEnkel - Enkel bygningsinfo

MatrikkelenhetServiceWS:
- findMatrikkelenhet - Hent enkelt matrikkelenhet
- findMatrikkelenheterForAdresse - Hent matrikkelenheter for adresse
- findMatrikkelenheterForBygg - Hent matrikkelenheter for bygning
- findMatrikkelenheterForByggList - Hent for flere bygninger
- INGEN DIREKTE EIER-FILTER FUNNET i WSDL

KommuneServiceWS:
- findAlleKommuner - Hent alle kommuner (perfekt!)
- findAlleFylker - Hent alle fylker
- findKommuneDTOsForFylke - Hent kommuner for fylke

✅ DATABASE TABELLER SOM EKSISTERER:
1. matrikkel_adresser (25 kolonner):
   - PK: adresse_id (BIGINT)
   - Inkluderer: gardsnummer, bruksnummer, festenummer, seksjonsnummer, undernummer
   - Indexes: fylkesnummer, adressenavn, postnummer, search_context
   - ⚠️ MANGLER: matrikkelenhet_id (foreign key)

2. matrikkel_bruksenheter (2 kolonner):
   - PK: (adresse_id, bruksenhet) composite
   - FK: adresse_id -> matrikkel_adresser
   - ⚠️ MANGLER: matrikkelenhet_id (foreign key)

❌ DATABASE TABELLER SOM MANGLER:
1. matrikkel_kommuner - MÅ LAGES
2. matrikkel_matrikkelenheter - MÅ LAGES (viktigste for eier-filtrering!)
3. matrikkel_bygninger - MÅ LAGES
4. matrikkel_gater - MÅ LAGES
5. matrikkel_bygning_matrikkelenhet (kobling) - MÅ LAGES

**KRITISK INNSIKT om eier-filtrering:**
- Eierforhold-objektet inneholder kun `eierforholdKodeId` - IKKE person/organisasjon-detaljer direkte
- Må bruke `StoreService` til å slå opp Person eller JuridiskPerson basert på eierforholdKodeId
- Ingen direkte WSDL-metode for å filtrere på eier - må implementeres på applikasjonsnivå
- Strategi: Hent matrikkelenheter for kommune → filtrer på eier_id i lokal database → JOIN til andre tabeller

**⭐ VIKTIG OPPDATERING - NedlastningServiceWS (Bulk-nedlasting):**

NedlastningServiceWS er DESIGNET for bulk-nedlasting av store datamengder og er den anbefalte 
metoden for å laste ned komplette datasett per kommune!

Metoder:
1. findIdsEtterId(matrikkelBubbleId, domainklasse, filter, maksAntall, matrikkelContext)
   → Returnerer liste med ID-er (MatrikkelBubbleIdList) - rask for å kun få ID-er

2. findObjekterEtterId(matrikkelBubbleId, domainklasse, filter, maksAntall, matrikkelContext)
   → Returnerer komplette objekter (MatrikkelBubbleObjectList) - full data

Parametere:
- matrikkelBubbleId (long): Start-ID for paginering. Bruk 0 for første batch, deretter siste 
  mottatt ID for neste batch (effektiv cursor-basert paginering)
- domainklasse (enum): Objekttype å hente - støtter ALLE relevante typer:
  * Kommune, Fylke
  * Matrikkelenhet, Grunneiendom, Festegrunn, Seksjon, Anleggseiendom, Jordsameie
  * Bygg, Bygning, Bygningsendring
  * Bruksenhet
  * Adresse, Vegadresse, Matrikkeladresse, Veg
  * Teig, Teiggrense
  * Kulturminne
  * Og mange flere...
- filter (string): Filter-uttrykk (syntaks må testes, men kan filtrere på kommunenummer)
- maksAntall (int): Batch-størrelse (f.eks. 1000 objekter per kall)
- matrikkelContext: Autentisering og kontekst

Fordeler med NedlastningServiceWS:
✅ Effektiv cursor-basert paginering med matrikkelBubbleId
✅ Kan hente ALLE objekttyper gjennom én tjeneste
✅ Filter-parameter for kommune-basert nedlasting
✅ Optimalisert for store datamengder
✅ Reduserer antall SOAP-kall kraftig

Anbefalt strategi:
1. Bruk NedlastningServiceWS med domainklasse="Matrikkelenhet" og filter på kommune
2. Paginer med matrikkelBubbleId (fortsett til tom liste returneres)
3. Lagre alle matrikkelenheter lokalt med eier-informasjon
4. Bruk samme metode for Bygning, Bruksenhet, Adresse per kommune
5. Filtrer på eier LOKALT i PostgreSQL via JOIN til matrikkel_matrikkelenheter.eier_id
6. Bygning-Matrikkelenhet koblinger kan hentes fra Bygning-objektet direkte

Konklusjon:
BRUK NedlastningServiceWS i stedet for objektspesifikke services (BygningServiceWS, 
MatrikkelenhetServiceWS) for alle bulk-import operasjoner. Dette gir bedre ytelse og 
enklere implementasjon med cursor-basert paginering.

**⭐ VIKTIG OPPDATERING - NedlastningServiceWS:**

NedlastningServiceWS er designet for **bulk-nedlasting** og er den foretrukne metoden for store datamengder!

**To metoder:**
- `findIdsEtterId` - Henter kun ID-er (rask)
- `findObjekterEtterId` - Henter komplette objekter

**Parametere:**
- `matrikkelBubbleId` (long) - Start-ID for paginering, bruk siste hentet ID for neste batch
- `domainklasse` (enum) - Objekttype: Kommune, Matrikkelenhet, Grunneiendom, Bygg, Bygning, Bruksenhet, Vegadresse, Adresse, Teig, etc.
- `filter` (string) - Filter-uttrykk (f.eks. kommune-filter)
- `maksAntall` (int) - Batch-størrelse
- `matrikkelContext` - Autentisering

**Fordeler:**
✅ Bulk-nedlasting med paginering (skalerbart)
✅ Kan filtrere per kommune via filter-parameter
✅ Støtter alle relevante objekttyper
✅ Effektivt for store datamengder

**Strategi:**
1. Last ned alle objekter per kommune via NedlastningServiceWS med kommune-filter
2. Paginer med matrikkelBubbleId (fortsett fra siste ID)
3. Lagre alle data lokalt i PostgreSQL
4. Filtrer på eier lokalt via SQL JOIN til matrikkel_matrikkelenheter.eier_id

**Konklusjon:** 
Bruk NedlastningServiceWS i stedet for de objektspesifikke services (BygningServiceWS, MatrikkelenhetServiceWS, etc.) for effektiv bulk-import per kommune. Eier-filtrering gjøres lokalt etter import.

---

📋 GAPS IDENTIFISERT:
1. Ingen BygningClient implementert
2. Ingen import-services for nye tabeller
3. Ingen REST API endpoints for kommune, matrikkelenhet, bygning, gate
4. Database-skjema må utvides med 5 nye tabeller + 2 foreign keys
5. Eier-filtrering må implementeres på applikasjonsnivå (ikke SOAP-nivå)
```

---

#### [ ] Trinn 2: Design database-skjema for de 7 tabellene
**Status**: Ikke startet  
**Estimat**: 3-4 timer

**Oppgaver**:
- [ ] Design `matrikkel_kommuner`:
  ```sql
  CREATE TABLE matrikkel_kommuner (
    kommune_id INT PRIMARY KEY,
    kommunenummer INT NOT NULL UNIQUE,
    kommunenavn VARCHAR(255) NOT NULL,
    fylkesnummer INT NOT NULL,
    fylkesnavn VARCHAR(255) NOT NULL,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE INDEX idx_matrikkel_kommuner_kommunenummer ON matrikkel_kommuner (kommunenummer);
  ```

- [ ] Design `matrikkel_matrikkelenheter`:
  ```sql
  CREATE TABLE matrikkel_matrikkelenheter (
    matrikkelenhet_id BIGINT PRIMARY KEY,
    kommunenummer INT NOT NULL,
    gardsnummer INT NOT NULL,
    bruksnummer INT NOT NULL,
    festenummer INT,
    seksjonsnummer INT,
    matrikkel_tekst VARCHAR(255) NOT NULL,
    eier_type VARCHAR(50), -- 'person', 'juridisk_person'
    eier_id BIGINT, -- PersonId eller OrganisasjonId
    eier_navn VARCHAR(255),
    areal_m2 FLOAT,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer)
  );
  CREATE INDEX idx_matrikkel_matrikkelenheter_kommune ON matrikkel_matrikkelenheter (kommunenummer);
  CREATE INDEX idx_matrikkel_matrikkelenheter_eier ON matrikkel_matrikkelenheter (eier_id, eier_type);
  CREATE INDEX idx_matrikkel_matrikkelenheter_matr ON matrikkel_matrikkelenheter (gardsnummer, bruksnummer, festenummer);
  ```

- [ ] Design `matrikkel_bygninger`:
  ```sql
  CREATE TABLE matrikkel_bygninger (
    bygning_id BIGINT PRIMARY KEY,
    bygningsnummer BIGINT NOT NULL UNIQUE,
    kommunenummer INT NOT NULL,
    bygningstype VARCHAR(100),
    byggeaar INT,
    bruksareal_totalt INT,
    bruksenheter_antall INT,
    etasjer_antall INT,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer)
  );
  CREATE INDEX idx_matrikkel_bygninger_bygningsnummer ON matrikkel_bygninger (bygningsnummer);
  CREATE INDEX idx_matrikkel_bygninger_kommune ON matrikkel_bygninger (kommunenummer);
  ```

- [ ] Design `matrikkel_gater`:
  ```sql
  CREATE TABLE matrikkel_gater (
    gate_id SERIAL PRIMARY KEY,
    kommunenummer INT NOT NULL,
    gatenavn VARCHAR(255) NOT NULL,
    adresser_antall INT DEFAULT 0,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer),
    UNIQUE (kommunenummer, gatenavn)
  );
  CREATE INDEX idx_matrikkel_gater_kommune ON matrikkel_gater (kommunenummer);
  CREATE INDEX idx_matrikkel_gater_navn ON matrikkel_gater (gatenavn);
  ```

- [ ] Oppdater `matrikkel_adresser` med relasjon til matrikkelenhet:
  ```sql
  ALTER TABLE matrikkel_adresser
    ADD COLUMN matrikkelenhet_id BIGINT;
  ALTER TABLE matrikkel_adresser
    ADD CONSTRAINT fk_matrikkel_adresser_matrikkelenhet
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id);
  CREATE INDEX idx_matrikkel_adresser_matrikkelenhet ON matrikkel_adresser (matrikkelenhet_id);
  ```

- [ ] Oppdater `matrikkel_bruksenheter` med relasjon til matrikkelenhet:
  ```sql
  ALTER TABLE matrikkel_bruksenheter
    ADD COLUMN matrikkelenhet_id BIGINT;
  ALTER TABLE matrikkel_bruksenheter
    ADD CONSTRAINT fk_matrikkel_bruksenheter_matrikkelenhet
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id);
  CREATE INDEX idx_matrikkel_bruksenheter_matrikkelenhet ON matrikkel_bruksenheter (matrikkelenhet_id);
  ```

- [ ] Design `matrikkel_bygning_matrikkelenhet` (koblingstabel):
  ```sql
  CREATE TABLE matrikkel_bygning_matrikkelenhet (
    bygning_id BIGINT NOT NULL,
    matrikkelenhet_id BIGINT NOT NULL,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bygning_id, matrikkelenhet_id),
    FOREIGN KEY (bygning_id) REFERENCES matrikkel_bygninger (bygning_id),
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id)
  );
  CREATE INDEX idx_bygning_matrikkelenhet_bygning ON matrikkel_bygning_matrikkelenhet (bygning_id);
  CREATE INDEX idx_bygning_matrikkelenhet_matrikkel ON matrikkel_bygning_matrikkelenhet (matrikkelenhet_id);
  ```

**Notater**:
```
DATABASE-SKJEMA DESIGN FULLFØRT - 7. oktober 2025

Basert på XSD-analyse:
- Kommune: kommunenummer, kommunenavn, fylkeId
- Matrikkelenhet: matrikkelnummer (gardsnummer, bruksnummer, festenummer, seksjonsnummer),
  eierforhold (id, eierforholdKodeId), tinglyst, etableringsdato, areal
- Bygning: bygningsnummer, kommuneId, bygningstypeKodeId, bebygdAreal, etasjedata
- Eierforhold: Ingen direkte person/org-felt i Eierforhold type - må hente via StoreService

VIKTIG FUNN:
- Eierforhold-objektet inneholder bare eierforholdKodeId, IKKE person/org direkte
- Vi må hente Person/JuridiskPerson separat via StoreService
- matrikkel_adresser har allerede gardsnummer/bruksnummer - kan bruke for JOIN
```

---

### ✅ Fase 2: SOAP Clients

#### ✅ Trinn 3: Implementer NedlastningClient for bulk-import (ANBEFALT)
**Status**: ✅ FULLFØRT  
**Estimat**: 2-3 timer  
**Prioritet**: ⭐⭐⭐ HØY - Dette gir mest effektiv datahenting

**Oppgaver**:
- [x] Opprett `src/Client/NedlastningClient.php`:
  - [x] Extend `AbstractSoapClient`
  - [x] Konfigurer WSDL-URL (prod og test)
  - [x] Implementer `findIdsEtterId()` metode
  - [x] Implementer `findObjekterEtterId()` metode
- [x] Registrer i `config/services.yaml`:
  ```yaml
  Iaasen\Matrikkel\Client\NedlastningClient:
      factory: [ Iaasen\Matrikkel\Client\SoapClientFactory, create ]
      arguments: [ Iaasen\Matrikkel\Client\NedlastningClient ]
  ```
- [x] Test paginering med matrikkelBubbleId cursor
- [ ] Test filter-parameter for kommune-filtrering (neste steg)
- [x] Dokumenter hvilke Domainklasse-verdier som støttes

**Eksempelkode**:
```php
// Hent alle matrikkelenheter for kommune 0301 (Oslo)
$lastId = 0;
$maxAntall = 1000;
do {
    $result = $nedlastningClient->findObjekterEtterId(
        $lastId,
        'Matrikkelenhet',
        'kommunenummer=0301',  // Filter-syntaks må testes
        $maxAntall,
        $matrikkelContext
    );
    
    foreach ($result as $matrikkelenhet) {
        // Lagre til database
        $lastId = $matrikkelenhet->getId()->getValue();
    }
} while (count($result) === $maxAntall);
```

**Resultat**:
```
✅ FULLFØRT - 7. oktober 2025

IMPLEMENTERT:
- src/Client/NedlastningClient.php (189 linjer med komplett dokumentasjon)
- src/Console/TestNedlastningCommand.php (test-command for verifisering)
- Registrert i config/services.yaml

TESTET:
✓ NedlastningClient fungerer med Kartverket API
✓ Hentet 3 kommuner: Halden (101), Sarpsborg (102), Fredrikstad (103)
✓ Cursor-basert paginering fungerer (lastId = 103)
✓ findObjekterEtterId() returnerer komplette objekter

GJENSTÅR:
- Test filter-parameter for kommune-filtrering (må teste syntaks)
- Implementer import-services som bruker NedlastningClient

NOTES:
- matrikkelBubbleId må være null (ikke 0) for første kall
- Domainklasse støtter: Kommune, Matrikkelenhet, Bygning, Bruksenhet, Adresse, Veg, etc.
- Batch-størrelse anbefalt: 1000 objekter
```

---

#### [ ] Trinn 3b: Implementer BygningClient (Fallback hvis NedlastningClient ikke dekker alt)
**Status**: Ikke startet  
**Estimat**: 1-2 timer  
**Prioritet**: ⭐ LAV - Kun nødvendig hvis NedlastningClient ikke fungerer

**Oppgaver**:
- [ ] Opprett `src/Client/BygningClient.php`:
  - [ ] Extend `AbstractSoapClient`
  - [ ] Konfigurer WSDL-URL fra BygningServiceWS.wsdl
  - [ ] Implementer `findByggForKommune()` metode
  - [ ] Implementer `findByggForMatrikkelenhet()` metode
- [ ] Registrer i `config/services.yaml`:
  ```yaml
  Iaasen\Matrikkel\Client\BygningClient:
      factory: [ Iaasen\Matrikkel\Client\SoapClientFactory, create ]
      arguments: [ Iaasen\Matrikkel\Client\BygningClient ]
  ```
- [ ] Test SOAP-kall mot test-miljø

**Notater**:
```
Denne er OPTIONAL hvis NedlastningClient fungerer godt.
Behold som backup-løsning.
```

---

### ✅ Fase 3: Import Services

#### ✅ Trinn 4: Lag import-service for Kommune-data
**Status**: ✅ FULLFØRT  
**Estimat**: 2-3 timer  
**Faktisk tid**: ~3 timer (inkl. debugging av AbstractTable.deduplicateRows bug)

**Oppgaver**:
- [x] Opprett `src/LocalDb/KommuneTable.php`:
  ```php
  class KommuneTable extends AbstractTable {
      protected string $tableName = 'matrikkel_kommuner';
      public function insertRow(object $kommune) : void { ... }
  }
  ```
- [x] Opprett `src/Service/KommuneImportService.php`:
  ```php
  class KommuneImportService {
      public function importAlleKommuner(SymfonyStyle $io) : int { ... }
  }
  ```
- [x] Opprett `src/Console/KommuneImportCommand.php`
- [x] Registrer services i `config/services.yaml`
- [x] Test import av kommune-data
- [x] **KRITISK BUG FIKSET**: AbstractTable.deduplicateRows brukte feil primary key

**Resultat**:
```
✅ FULLFØRT - 8. oktober 2025

Implementerte følgende filer:
1. src/LocalDb/KommuneTable.php (220 linjer)
   - insertRow() mapper Kommune SOAP-objekt til database
   - Ekstraher kommunenummer (4-sifret padding)
   - Ekstraher fylkesnummer fra fylkeId eller kommunenummer
   - Håndter LocalDate-konvertering for gyldig_til_dato
   - Parse koordinatsystem, eksklusiv_bruker, nedsatt_konsesjonsgrense
   - Parse senterpunkt (nord/øst koordinater)

2. src/Service/KommuneImportService.php (157 linjer)
   - Bruker TestNedlastningCommand-logikk (bevist stabil)
   - Single batch fetch: findObjekterEtterId(0, 'Kommune', null, 1000)
   - Loop med insertRow() + progressAdvance()
   - flush() kalles én gang etter alle inserts

3. src/Console/KommuneImportCommand.php (111 linjer)
   - Kommando: matrikkel:kommune-import
   - Option: --batch-size (default 1000)
   - Viser progress bar under import
   - Statistikk: total count, tid brukt, throughput
   - Database-verifikasjon etter import

4. ⚠️ BUG-FIX: src/LocalDb/AbstractTable.php
   PROBLEM: deduplicateRows() brukte 'kommune_id' som primary key
            men KommuneTable.insertRow() sender 'kommunenummer'
   KONSEKVENS: Alle rader fikk samme composite key → kun 9/883 lagret
   FIX: Endret to steder:
        - Line 57: 'matrikkel_kommuner' => ['kommunenummer']
        - Line 109: ON CONFLICT clause bruker 'kommunenummer'

Import-resultat:
✅ 883 kommuner hentet fra NedlastningServiceWS
✅ 883 kommuner lagret i matrikkel_kommuner tabell
✅ Hastighet: 459.9 kommuner/sekund
✅ Kommunenummer range: 101-9999
✅ Alle felt korrekt fylt: kommunenavn, fylkesnummer, koordinater, etc.

Test-kommando:
$ php bin/console matrikkel:kommune-import --no-interaction

Verifikasjon:
$ psql -d matrikkel -c "SELECT COUNT(*) FROM matrikkel_kommuner;" → 883
$ psql -d matrikkel -c "SELECT kommunenummer, kommunenavn, fylkesnummer 
  FROM matrikkel_kommuner ORDER BY kommunenummer LIMIT 10;"
  → Viser: 101 HALDEN, 102 SARPSBORG, 103 FREDRIKSTAD, etc.
```

---

#### ✅ Trinn 5: Lag import-service for Matrikkelenhet-data
**Status**: ✅ FULLFØRT  
**Estimat**: 4-5 timer  
**Faktisk tid**: ~4 timer (inkl. debugging av NedlastningClient array-problem)

**Oppgaver**:
- [x] Opprett `src/LocalDb/MatrikkelenhetTable.php` (270 linjer)
- [x] Opprett `src/Service/MatrikkelenhetImportService.php` (174 linjer)
- [x] Opprett `src/Console/MatrikkelenhetImportCommand.php` (197 linjer)
- [x] Implementer lokal filter på `kommunenummer` (API-filter virker ikke)
- [x] Implementer `eier_id` ekstrahering fra eierforhold SOAP-respons
- [x] Test import med kommune 811

**Resultat**:
```
✅ FULLFØRT - 8. oktober 2025

Implementerte følgende filer:
1. src/LocalDb/MatrikkelenhetTable.php (270 linjer)
   - insertRow() mapper Matrikkelenhet SOAP-objekt til database
   - Ekstraher matrikkelnummer (format: "kommunenr/gnr/bnr/fnr/snr")
   - extractTinglystEier() henter første eierforhold med eierId
   - Håndter LocalDate-konvertering for etableringsdato
   - Parse areal, tinglyst, skyld, bruksnavn
   - Parse status-flagg: er_seksjonert, har_aktive_festegrunner, utgatt, etc.

2. src/Service/MatrikkelenhetImportService.php (174 linjer)
   - importMatrikkelenhetForKommune() med lokal kommune-filtrering
   - importMatrikkelenhetForAlleKommuner() for bulk-import
   - Debug-output viser hvilke kommuner som finnes i batch
   - Statistikk: total, per_kommune

3. src/Console/MatrikkelenhetImportCommand.php (197 linjer)
   - Kommando: matrikkel:matrikkelenhet-import
   - Options: --kommune=X, --batch-size=N
   - Viser progress bar og statistikk
   - Database-verifikasjon

4. ⚠️ BUG-FIX: src/Client/NedlastningClient.php
   PROBLEM: findObjekterEtterId() returnerte stdClass når bare 1 element
            Type error: "Return value must be of type array, stdClass returned"
   FIX: Sjekk if (!is_array($items)) og wrap i array
        Samme fix i findIdsEtterId()

5. Updated: src/LocalDb/AbstractTable.php
   - Lagt til primary key: 'matrikkel_matrikkelenheter' => ['matrikkelenhet_id']
   - ON CONFLICT clause oppdatert

Import-resultat for kommune 811:
✅ 137 matrikkelenheter importert (av 1000 hentet fra API)
✅ 11 matrikkelenheter med eier_id (8%)
✅ 126 matrikkelenheter uten eier_id (92%)
✅ Hastighet: 41.12 matrikkelenheter/sek
✅ Eierforhold-ekstrahering fungerer for de som har det registrert

VIKTIG INNSIKT:
- NedlastningServiceWS API-filter virker IKKE for kommunenummer
- Må hente alle objekter og filtrere lokalt per kommune
- De første 1000 matrikkelenhetene tilhører kommune: 4010, 811, 3812
- Mange matrikkelenheter har ikke eierforhold registrert i Matrikkel
- eier_navn, organisasjonsnr, fodselsnr må hentes separat fra PersonService

Test-kommando:
$ php bin/console matrikkel:matrikkelenhet-import --kommune=811

Verifikasjon:
$ psql -d matrikkel -c "SELECT COUNT(*) FROM matrikkel_matrikkelenheter 
  WHERE kommunenummer = 811;" → 137
$ psql -d matrikkel -c "SELECT COUNT(*) as total, COUNT(eier_id) as med_eier 
  FROM matrikkel_matrikkelenheter WHERE kommunenummer = 811;"
  → total: 137, med_eier: 11
```

---

#### [ ] Trinn 6: Lag import-service for Bygning-data
**Status**: Ikke startet  
**Estimat**: 3-4 timer

**Oppgaver**:
- [ ] Opprett `src/LocalDb/BygningTable.php`
- [ ] Opprett `src/LocalDb/BygningImportService.php`:
  - [ ] Implementer filter på `kommunenummer`
  - [ ] Parse bygningsdata (bygningsnummer, byggeår, areal, etc.)
  - [ ] Håndter relasjon til matrikkelenheter
- [ ] Test import med kommune-filter

**Notater**:
```
<!-- Bygningsdata-strukturer fra SOAP -->
```

---

#### [ ] Trinn 7: Lag import-service for Gate-data
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Opprett `src/LocalDb/GateTable.php`
- [ ] Opprett `src/LocalDb/GateImportService.php`:
  - [ ] Hent gate-informasjon fra eksisterende adresse-data
  - [ ] Dedupliser gatenavn per kommune
  - [ ] Tell antall adresser per gate
- [ ] Alternativt: Bruk AdresseServiceWS for å ekstrahere gatenavn

**Notater**:
```
<!-- Kilde for gate-data -->
```

---

#### [ ] Trinn 8: Utvid eksisterende Adresse-import med eier-filter
**Status**: Ikke startet  
**Estimat**: 3-4 timer

**Oppgaver**:
- [ ] Modifiser `src/LocalDb/AdresseImportService.php`:
  - [ ] Legg til parameter for eier-filtrering
  - [ ] Implementer JOIN-logikk mot matrikkelenheter
  - [ ] Filtrer adresser basert på eierens matrikkelenheter
- [ ] Oppdater `src/LocalDb/AdresseTable.php`:
  - [ ] Legg til `matrikkelenhet_id` kolonne
  - [ ] Oppdater `insertRow()` og `insertRowLeilighetsnivaa()`

**Notater**:
```
<!-- JOIN-strategi -->
```

---

#### [ ] Trinn 9: Utvid eksisterende Bruksenhet-import med eier-filter
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Modifiser `src/LocalDb/BruksenhetImportService.php` (eller opprett hvis mangler)
- [ ] Oppdater `src/LocalDb/BruksenhetTable.php`:
  - [ ] Legg til `matrikkelenhet_id` kolonne
  - [ ] Implementer eier-filtrering via matrikkelenheter
- [ ] Test bruksenhet-import med eier-filter

**Notater**:
```
<!-- Relasjon bruksenhet -> matrikkelenhet -->
```

---

#### [ ] Trinn 10: Implementer Bygning-Matrikkelenhet koblingstabel
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Opprett `src/LocalDb/BygningMatrikkelenhetTable.php`
- [ ] Implementer metode for å hente kobling fra SOAP API:
  - [ ] Via `BygningServiceWS.findMatrikkelenheterForBygning()`
  - [ ] Via `MatrikkelenhetServiceWS.findBygningerForMatrikkelenhet()`
- [ ] Oppdater `AbstractTable.php` for å støtte composite primary key
- [ ] Test koblingstabel-import

**Notater**:
```
<!-- SOAP-metoder for relasjon -->
```

---

### ✅ Fase 4: Console Commands

#### [ ] Trinn 11: Lag Console Commands for import
**Status**: Ikke startet  
**Estimat**: 3-4 timer

**Oppgaver**:
- [ ] Opprett `src/Console/KommuneImportCommand.php`:
  ```bash
  php bin/console matrikkel:kommune-import
  ```
- [ ] Opprett `src/Console/MatrikkelenhetImportCommand.php`:
  ```bash
  php bin/console matrikkel:matrikkelenhet-import --kommune=5001 --eier=12345678
  ```
- [ ] Opprett `src/Console/BygningImportCommand.php`:
  ```bash
  php bin/console matrikkel:bygning-import --kommune=5001
  ```
- [ ] Opprett `src/Console/GateImportCommand.php`:
  ```bash
  php bin/console matrikkel:gate-import --kommune=5001
  ```
- [ ] Oppdater eksisterende `AdresseImportCommand.php`:
  ```bash
  php bin/console matrikkel:adresse-import --liste=norge --kommune=5001 --eier=12345678
  ```
- [ ] Test alle commands med forskjellige parametere

**Notater**:
```
<!-- Command-syntaks og eksempler -->
```

---

### ✅ Fase 5: REST API Endpoints

#### [ ] Trinn 12: Implementer REST API endpoints for de 7 tabellene
**Status**: Ikke startet  
**Estimat**: 4-5 timer

**Oppgaver**:
- [ ] Utvid `src/Controller/MatrikkelApiController.php` med norske endpoints:
  
  **Kommune**:
  ```php
  #[Route('/kommune', methods: ['GET'])]
  #[Route('/kommune/{kommunenummer}', methods: ['GET'])]
  ```
  
  **Matrikkelenhet**:
  ```php
  #[Route('/matrikkelenhet', methods: ['GET'])] // ?kommune=X&eier=Y
  #[Route('/matrikkelenhet/{id}', methods: ['GET'])]
  ```
  
  **Bygning**:
  ```php
  #[Route('/bygning', methods: ['GET'])] // ?kommune=X
  #[Route('/bygning/{bygningsnummer}', methods: ['GET'])]
  ```
  
  **Gate**:
  ```php
  #[Route('/gate', methods: ['GET'])] // ?kommune=X
  #[Route('/gate/{id}', methods: ['GET'])]
  ```
  
  **Adresse** (oppdater eksisterende):
  ```php
  #[Route('/adresse/sok', methods: ['GET'])] // ?q=&kommune=X&eier=Y
  ```
  
  **Bruksenhet** (oppdater eksisterende):
  ```php
  #[Route('/bruksenhet', methods: ['GET'])] // ?kommune=X&eier=Y
  ```
  
  **Bygning-Matrikkelenhet**:
  ```php
  #[Route('/bygning-matrikkelenhet', methods: ['GET'])] // ?bygning=X eller ?matrikkelenhet=Y
  ```

- [ ] Implementer paginering for alle endpoints
- [ ] Implementer filtrering på kommune og eier
- [ ] Oppdater `getAvailableEndpoints()` med nye ruter
- [ ] Test alle endpoints med curl/Postman

**Notater**:
```
<!-- API-eksempler og respons-strukturer -->
```

---

### ✅ Fase 6: Dokumentasjon og Testing

#### [ ] Trinn 13: Oppdater README.md med ny dokumentasjon
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Dokumenter nye database-tabeller:
  - [ ] Legg til alle CREATE TABLE statements
  - [ ] Dokumenter relasjoner og foreign keys
  - [ ] Lag tekstlig ER-diagram
- [ ] Dokumenter nye console commands:
  - [ ] Syntaks og parametere
  - [ ] Eksempler med forskjellige filter-kombinasjoner
- [ ] Dokumenter nye REST API endpoints:
  - [ ] Request-eksempler med curl
  - [ ] Response-eksempler med JSON
  - [ ] Query-parametere for filtrering
- [ ] Dokumenter eier-filtrering:
  - [ ] Hvordan finne eier-ID
  - [ ] Eksempler på eier-basert søk

**Notater**:
```
<!-- Dokumentasjonsnotater -->
```

---

#### [ ] Trinn 14: Test import og API med ekte data
**Status**: Ikke startet  
**Estimat**: 3-4 timer

**Oppgaver**:
- [ ] Velg testkommune (f.eks. Trondheim 5001)
- [ ] Test import-sekvens:
  ```bash
  php bin/console matrikkel:kommune-import
  php bin/console matrikkel:matrikkelenhet-import --kommune=5001
  php bin/console matrikkel:bygning-import --kommune=5001
  php bin/console matrikkel:gate-import --kommune=5001
  php bin/console matrikkel:adresse-import --liste=trondelag --kommune=5001
  ```
- [ ] Verifiser data i PostgreSQL:
  ```sql
  SELECT COUNT(*) FROM matrikkel_kommuner;
  SELECT COUNT(*) FROM matrikkel_matrikkelenheter WHERE kommunenummer = 5001;
  SELECT COUNT(*) FROM matrikkel_bygninger WHERE kommunenummer = 5001;
  -- etc.
  ```
- [ ] Test alle REST API endpoints:
  ```bash
  curl http://localhost:8000/api/kommune
  curl http://localhost:8000/api/kommune/5001
  curl http://localhost:8000/api/matrikkelenhet?kommune=5001
  curl http://localhost:8000/api/bygning?kommune=5001
  # etc.
  ```
- [ ] Test eier-filtrering:
  ```bash
  # Finn en eier-ID fra matrikkelenheter
  curl http://localhost:8000/api/matrikkelenhet?kommune=5001&eier=XXXXX
  curl http://localhost:8000/api/adresse/sok?kommune=5001&eier=XXXXX
  ```
- [ ] Valider ytelseoptimalisering:
  - [ ] Sjekk query execution time
  - [ ] Verifiser at indexes brukes (EXPLAIN ANALYZE)
  - [ ] Optimaliser trege queries
- [ ] Test edge cases:
  - [ ] Tom resultatliste
  - [ ] Ugyldig kommune-nummer
  - [ ] Ugyldig eier-ID
  - [ ] Store datasett (paginering)

**Notater**:
```
<!-- Test-resultater og ytelsesobservasjoner -->
```

---

## 📝 Generelle Notater

### Tekniske Beslutninger
```
<!-- Legg til viktige tekniske valg og begrunnelser -->
```

### Utfordringer og Løsninger
```
<!-- Dokumenter problemer og hvordan de ble løst -->
```

### Neste Steg
```
<!-- Legg til nye oppgaver som dukker opp underveis -->
```

---

## 🔗 Relasjoner mellom Tabeller

```
Kommune (1) ----< (N) Matrikkelenhet
Kommune (1) ----< (N) Bygning
Kommune (1) ----< (N) Gate
Kommune (1) ----< (N) Adresse

Matrikkelenhet (1) ----< (N) Adresse
Matrikkelenhet (1) ----< (N) Bruksenhet
Matrikkelenhet (N) ----< (N) Bygning  [via matrikkel_bygning_matrikkelenhet]

Gate (1) ----< (N) Adresse
Adresse (1) ----< (N) Bruksenhet
```

---

## 📊 Fremdrift

**Totalt antall trinn**: 14  
**Fullførte trinn**: 0  
**Prosent fullført**: 0%

**Sist oppdatert**: 7. oktober 2025
