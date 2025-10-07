# Implementasjonsplan: 7 Primære Tabeller med Filter på Kommune og Tinglyst Eier

**Opprettet**: 7. oktober 2025  
**Status**: Ikke påbegynt  
**Mål**: Implementere 7 primære tabeller i Matrikkel-systemet med filtrering på kommune og tinglyst eier

---

## 📊 Oversikt over Tabeller

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
<!-- Legg inn notater her etter analyse -->
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
<!-- Legg til justeringer basert på WSDL-analyse -->
```

---

### ✅ Fase 2: SOAP Clients

#### [ ] Trinn 3: Implementer SOAP Client for manglende tjenester
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Verifiser `BygningClient.php`:
  - [ ] Sjekk om filen finnes i `src/Client/`
  - [ ] Test at SOAP-kall fungerer
  - [ ] Implementer hvis manglende
- [ ] Verifiser `MatrikkelenhetClient.php`:
  - [ ] Sjekk støtte for `findMatrikkelenhetByEier()` metode
  - [ ] Test søk med kommunefilter
  - [ ] Implementer manglende metoder
- [ ] Registrer alle Clients i `config/services.yaml`:
  ```yaml
  Iaasen\Matrikkel\Client\BygningClient:
      factory: [ Iaasen\Matrikkel\Client\SoapClientFactory, create ]
      arguments: [ Iaasen\Matrikkel\Client\BygningClient ]
  ```

**Notater**:
```
<!-- Status på hver Client -->
```

---

### ✅ Fase 3: Import Services

#### [ ] Trinn 4: Lag import-service for Kommune-data
**Status**: Ikke startet  
**Estimat**: 2-3 timer

**Oppgaver**:
- [ ] Opprett `src/LocalDb/KommuneTable.php`:
  ```php
  class KommuneTable extends AbstractTable {
      protected string $tableName = 'matrikkel_kommuner';
      public function insertRow(array $row) : void { ... }
  }
  ```
- [ ] Opprett `src/LocalDb/KommuneImportService.php`:
  ```php
  class KommuneImportService {
      public function importKommuner(SymfonyStyle $io) : bool { ... }
  }
  ```
- [ ] Registrer services i `config/services.yaml`
- [ ] Test import av kommune-data

**Notater**:
```
<!-- Implementeringsnotater -->
```

---

#### [ ] Trinn 5: Lag import-service for Matrikkelenhet-data
**Status**: Ikke startet  
**Estimat**: 4-5 timer

**Oppgaver**:
- [ ] Opprett `src/LocalDb/MatrikkelenhetTable.php`
- [ ] Opprett `src/LocalDb/MatrikkelenhetImportService.php`:
  - [ ] Implementer filter på `kommunenummer`
  - [ ] Implementer filter på `eier_id` (PersonId/OrganisasjonId)
  - [ ] Håndter paginering for store datasett
  - [ ] Parse eierforhold fra SOAP-respons
- [ ] Test import med forskjellige filtere

**Notater**:
```
<!-- Spesifikke SOAP-metoder brukt -->
<!-- Utfordringer med eier-filtrering -->
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
  curl http://localhost:8000/api/v1/kommune
  curl http://localhost:8000/api/v1/kommune/5001
  curl http://localhost:8000/api/v1/matrikkelenhet?kommune=5001
  curl http://localhost:8000/api/v1/bygning?kommune=5001
  # etc.
  ```
- [ ] Test eier-filtrering:
  ```bash
  # Finn en eier-ID fra matrikkelenheter
  curl http://localhost:8000/api/v1/matrikkelenhet?kommune=5001&eier=XXXXX
  curl http://localhost:8000/api/v1/adresse/sok?kommune=5001&eier=XXXXX
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
