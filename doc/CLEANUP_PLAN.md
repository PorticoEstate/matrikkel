# Plan for Opprydding av Gammelt Kode

## 📊 Analyse av Nåværende Situasjon

### ✅ **Nye, Viktige Commands (BEHOLD)**
Disse er kjernen i den nye funksjonaliteten og MÅ beholdes:

1. **`matrikkel:phase1-import`** ⭐
   - Import av: kommune + matrikkelenheter + personer + eierforhold
   - Bruker: `Phase1ImportCommand.php`
   - **STATUS**: BEHOLD - Dette er hovedkommandoen for grunnimport
   - **Erstatter**: `matrikkel:kommune-import` og `matrikkel:matrikkelenhet-import`

2. **`matrikkel:phase2-import`** ⭐
   - Import av: veger + bygninger + bruksenheter + adresser (filtrert)
   - Bruker: `Phase2ImportCommand.php`
   - **STATUS**: BEHOLD - Dette er hovedkommandoen for filtrert import
   - **Erstatter**: `matrikkel:adresse-import` (for filtrerte adresser)

3. **`matrikkel:ping`** ⭐
   - Test SOAP-tilkobling til Matrikkel API
   - Bruker: `PingCommand.php`
   - **STATUS**: BEHOLD - Viktig for debugging og testing av API-tilkobling

---

### ⚠️ **Gamle Commands (KAN FJERNES HVIS REST API BRUKES)**

**✅ REST API REFAKTORERT**: REST API er nå refaktorert til å hente data direkte fra PostgreSQL-databasen!

**Endringer:**
- ✅ Fjernet SOAP Service-avhengigheter (AdresseService, BruksenhetService, etc.)
- ✅ Laget Repository-lag (AdresseRepository, MatrikkelenhetRepository, etc.)
- ✅ Alle endpoints henter data fra lokal database (ikke fra Matrikkel API)
- ✅ Raskere respons (ingen SOAP-kall)
- ✅ Fungerer offline
- ✅ Bruker samme data som Phase1/Phase2 populerer

**Arkitektur:**
- **FØR**: REST API → SOAP Services → Matrikkel API (eksternt)
- **ETTER**: REST API → Repository Services → PostgreSQL (lokalt)

---

Disse ble laget før REST API var på plass. De er **redundante** fordi:
- REST API (`MatrikkelApiController.php`) dekker samme funksjonalitet
- Phase1/Phase2 import er den nye måten å hente data på
- De bruker gamle SOAP-services direkte uten database-integrasjon

#### **Console Commands som er Overflødige:**

1. **`matrikkel:adresse`**
   - Fil: `AdresseCommand.php`
   - Funksjon: Søk/hent enkelt-adresser via SOAP API
   - **Erstattet av**: 
     - REST API: `GET /api/adresse/{id}` og `GET /api/adresse/sok?q=...`
     - Phase2 import for bulk
   - **STATUS**: ❌ KAN FJERNES

2. **`matrikkel:bruksenhet`**
   - Fil: `BruksenhetCommand.php`
   - Funksjon: Hent bruksenheter via SOAP API
   - **Erstattet av**: 
     - REST API: `GET /api/bruksenhet/{id}` og `GET /api/bruksenhet/adresse/{adresseId}`
     - Phase2 import for bulk
   - **STATUS**: ❌ KAN FJERNES

3. **`matrikkel:kommune`**
   - Fil: `KommuneCommand.php`
   - Funksjon: Hent enkelt-kommune via SOAP API
   - **Erstattet av**: 
     - REST API: `GET /api/kommune/{id}` og `GET /api/kommune/nummer/{nummer}`
     - `matrikkel:kommune-import` for bulk
   - **STATUS**: ❌ KAN FJERNES

4. **`matrikkel:kodeliste`**
   - Fil: `KodelisteCommand.php`
   - Funksjon: Hent kodelister via SOAP API
   - **Erstattet av**: 
     - REST API: `GET /api/kodeliste` og `GET /api/kodeliste/{id}`
   - **STATUS**: ❌ KAN FJERNES

5. **`matrikkel:matrikkelenhet`**
   - Fil: `MatrikkelenhetCommand.php`
   - Funksjon: Hent enkelt matrikkelenhet via SOAP API
   - **Erstattet av**: 
     - REST API: `GET /api/matrikkelenhet/{id}` eller `GET /api/matrikkelenhet/{knr}/{gnr}/{bnr}`
     - Phase1 import for bulk
   - **STATUS**: ❌ KAN FJERNES

5. **`matrikkel:matrikkelenhet-import`**
   - Fil: `MatrikkelenhetImportCommand.php`
   - Funksjon: Gammel import av matrikkelenheter (før Phase1)
   - **Erstattet av**: Phase1 import
   - **STATUS**: ❌ KAN FJERNES

6. **`matrikkel:kommune-import`**
   - Fil: `KommuneImportCommand.php`
   - Funksjon: Import alle norske kommuner
   - **Erstattet av**: Phase1 import (steg 1 importerer kommune)
   - **STATUS**: ❌ KAN FJERNES

7. **`matrikkel:adresse-import`**
   - Fil: `AddressImportCommand.php`
   - Funksjon: Import alle norske adresser fra Kartverket CSV-fil til lokal database
   - **Erstattet av**: Phase2 import (importerer adresser filtrert på kommune/eier)
   - **STATUS**: ❌ KAN FJERNES
   - **Merknad**: Dette var en egen use case for FULL adressedatabase (2.5M adresser). Hvis du ikke trenger dette, fjern den.

---

### ❓ **Spesial-Commands (VURDER)**

1. **`matrikkel:sok`**
   - Fil: `MatrikkelsokCommand.php`
   - Funksjon: Generelt søk i Matrikkel API
   - **Erstattet av**: REST API: `GET /api/sok?q=...&source=api`
   - **STATUS**: ❌ KAN FJERNES

2. **`matrikkel:debug-matrikkelenhet`**
   - Fil: `DebugMatrikkelenhetCommand.php`
   - Funksjon: Debug-verktøy for å se Matrikkelenhet-struktur fra API
   - **STATUS**: 🔧 **BEHOLD** (nyttig for utvikling/debugging)

3. **`matrikkel:test-nedlastning`**
   - Fil: `TestNedlastningCommand.php`
   - Funksjon: Test NedlastningClient med bulk-nedlasting
   - **STATUS**: 🔧 **BEHOLD** (nyttig for testing av NedlastningClient)

---

## 🗂️ Detaljert Ryddingsplan

### **Fase 1: Sikkerhetskopi og Analyse** (1 time)

**Før du sletter noe!**

1. ✅ **Commit og push alt til Git**
   ```bash
   git add .
   git commit -m "Checkpoint før opprydding av gamle commands"
   git push origin NedlastningClient
   ```

2. ✅ **Les gjennom hver command som skal fjernes**
   - Bekreft at REST API eller Phase1/Phase2 dekker funksjonaliteten
   - Sjekk om det er spesielle features som må migreres

3. ✅ **Test REST API endpoints**
   ```bash
   # Test at alle REST API endpoints fungerer
   curl http://localhost:8083/api/ping
   curl http://localhost:8083/api/endpoints
   curl "http://localhost:8083/api/adresse/sok?q=Bergen"
   curl http://localhost:8083/api/kommune/4601
   ```

4. ✅ **Test Phase1 og Phase2**
   ```bash
   # Test komplett import-flyt
   php bin/console matrikkel:phase1-import --kommune=4601 --limit=10
   php bin/console matrikkel:phase2-import --kommune=4601
   ```

---

### **Fase 2: Identifiser Avhengigheter** (30 min)

**Sjekk om noen Services kun brukes av gamle commands:**

```bash
# Søk etter bruk av hver Service
cd /opt/matrikkel

# AdresseService
grep -r "AdresseService" src/Console/*.php src/Controller/*.php

# BruksenhetService  
grep -r "BruksenhetService" src/Console/*.php src/Controller/*.php

# KommuneService
grep -r "KommuneService" src/Console/*.php src/Controller/*.php

# KodelisteService
grep -r "KodelisteService" src/Console/*.php src/Controller/*.php

# MatrikkelenhetService
grep -r "MatrikkelenhetService" src/Console/*.php src/Controller/*.php

# MatrikkelsokService
grep -r "MatrikkelsokService" src/Console/*.php src/Controller/*.php
```

**Forventet resultat:**
- Alle disse Services brukes i `MatrikkelApiController.php` (REST API)
- De brukes OGSÅ i gamle commands som skal fjernes
- **KONKLUSJON**: Services MÅ beholdes, bare command-filene fjernes

---

### **Fase 3: Fjern Gamle Commands** (1 time)

#### **Steg 1: Flytt til deprecated-mappe (sikkerhetsnett)**

```bash
cd /opt/matrikkel
mkdir -p src/Console/deprecated

```bash
# Flytt gamle commands
mv src/Console/AdresseCommand.php src/Console/deprecated/
mv src/Console/BruksenhetCommand.php src/Console/deprecated/
mv src/Console/KommuneCommand.php src/Console/deprecated/
mv src/Console/KodelisteCommand.php src/Console/deprecated/
mv src/Console/MatrikkelenhetCommand.php src/Console/deprecated/
mv src/Console/MatrikkelsokCommand.php src/Console/deprecated/
mv src/Console/MatrikkelenhetImportCommand.php src/Console/deprecated/
mv src/Console/KommuneImportCommand.php src/Console/deprecated/
mv src/Console/AddressImportCommand.php src/Console/deprecated/
```
```

#### **Steg 2: Test at alt fungerer**

```bash
# Sjekk at kun de riktige commands vises
php bin/console list matrikkel

# Forventet output:
# matrikkel:debug-matrikkelenhet  
# matrikkel:phase1-import         ⭐
# matrikkel:phase2-import         ⭐
# matrikkel:ping                  ⭐
# matrikkel:test-nedlastning
```

#### **Steg 3: Test REST API fortsatt fungerer**

```bash
curl http://localhost:8083/api/ping
curl "http://localhost:8083/api/adresse/sok?q=Oslo"
curl http://localhost:8083/api/kommune/4601
```

#### **Steg 4: Test Phase1 og Phase2**

```bash
php bin/console matrikkel:phase1-import --kommune=4601 --limit=5
php bin/console matrikkel:phase2-import --kommune=4601
```

**Hvis alt fungerer**: Commit endringene
```bash
git add .
git commit -m "Deprecated old console commands - moved to src/Console/deprecated/"
git push
```

**Hvis noe feiler**: Flytt tilbake fra deprecated/ og analyser problemet

---

### **Fase 5: Permanent Sletting** (etter 1-2 uker testing)

#### **Oppdater README.md**

Fjern seksjon om gamle commands, behold kun:

```markdown
### Available Console Commands

**Test API connection:**
```bash
php bin/console matrikkel:ping
```

**Import data (two-phase approach):**

```bash
# Phase 1: Import base data (kommune, matrikkelenheter, personer, eierforhold)
php bin/console matrikkel:phase1-import --kommune=4601 --limit=100 --organisasjonsnummer=964338531

# Phase 2: Import filtered data (veger, bygninger, bruksenheter, adresser)
php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=964338531
```

**Debug commands:**
```bash
# Debug matrikkelenhet structure from API
php bin/console matrikkel:debug-matrikkelenhet

# Test NedlastningClient bulk downloads
php bin/console matrikkel:test-nedlastning
```

**Note**: For searching addresses, property units, cadastral units, etc., use the REST API endpoints. See REST API section below.
```

#### **Oppdater IMPLEMENTATION_PLAN.md**

Marker gamle commands som deprecated:

```markdown
## ⚠️ Deprecated Commands

The following commands have been replaced by Phase1/Phase2 import and REST API:

- ~~`matrikkel:adresse`~~ → Use REST API `GET /api/adresse/{id}`
- ~~`matrikkel:bruksenhet`~~ → Use REST API `GET /api/bruksenhet/{id}`
- ~~`matrikkel:kommune`~~ → Use REST API `GET /api/kommune/{id}`
- ~~`matrikkel:kodeliste`~~ → Use REST API `GET /api/kodeliste/{id}`
- ~~`matrikkel:matrikkelenhet`~~ → Use REST API `GET /api/matrikkelenhet/{id}`
- ~~`matrikkel:sok`~~ → Use REST API `GET /api/sok?q=...`
- ~~`matrikkel:matrikkelenhet-import`~~ → Use `matrikkel:phase1-import`
- ~~`matrikkel:kommune-import`~~ → Use `matrikkel:phase1-import`
- ~~`matrikkel:adresse-import`~~ → Use `matrikkel:phase2-import`
```

---

### **Fase 4: Oppdater Dokumentasjon** (30 min)

**Når du er 100% sikker på at alt fungerer:**

```bash
# Slett deprecated-mappen permanent
rm -rf src/Console/deprecated/

# Commit
git add .
git commit -m "Permanently removed old console commands - replaced by Phase1/Phase2 and REST API"
git push
```

---

## 📋 Oppsummering: Hva Skal Beholdes?

### ✅ **Commands å Beholde:**

1. **`matrikkel:phase1-import`** - Grunnimport (kommune, matrikkelenheter, personer, eierforhold)
2. **`matrikkel:phase2-import`** - Filtrert import (veger, bygninger, bruksenheter, adresser)
3. **`matrikkel:ping`** - Test API-tilkobling
4. **`matrikkel:debug-matrikkelenhet`** - Debug-verktøy (nyttig for utvikling)
5. **`matrikkel:test-nedlastning`** - Test NedlastningClient (nyttig for testing)

**Totalt: 5 commands** (ned fra 14)

---

### ❌ **Commands å Fjerne:**

1. **`matrikkel:adresse`** → REST API
2. **`matrikkel:bruksenhet`** → REST API
3. **`matrikkel:kommune`** → REST API
4. **`matrikkel:kodeliste`** → REST API
5. **`matrikkel:matrikkelenhet`** → REST API
6. **`matrikkel:sok`** → REST API
7. **`matrikkel:matrikkelenhet-import`** → Phase1 import
8. **`matrikkel:kommune-import`** → Phase1 import
9. **`matrikkel:adresse-import`** → Phase2 import

**Totalt: 9 commands fjernet**

---

## 🎯 Fordeler med Opprydding

1. **Enklere vedlikehold**: Færre commands å holde oppdatert
2. **Konsistent API**: REST API er standard for moderne integrasjoner
3. **Bedre dokumentasjon**: Tydeligere hva som er anbefalt workflow
4. **Mindre forvirring**: Brukere vet at Phase1/Phase2 + REST API er veien å gå
5. **Raskere onboarding**: Nye utviklere forstår arkitekturen enklere

---

## ⚠️ Risiko-Analyse

### **Lav Risiko:**
- Alle gamle commands er erstattet av REST API eller Phase1/Phase2
- Services (AdresseService, BruksenhetService, etc.) beholdes
- REST API bruker samme Services, så logikken er den samme

### **Mulig Risiko:**
- Hvis noen eksterne systemer bruker de gamle commands direkte
- **Løsning**: Gi en deprecation-periode på 1-2 uker før permanent sletting

### **Ingen Risiko:**
- Phase1 og Phase2 er allerede testet og fungerer
- REST API er allerede i produksjon og testet
- Database-strukturen påvirkes ikke

---

## 📅 Tidsestimat

| Fase | Tid | Beskrivelse |
|------|-----|-------------|
| Fase 1 | 1 time | Sikkerhetskopi og analyse |
| Fase 2 | 30 min | Identifiser avhengigheter |
| Fase 3 | 1 time | Flytt til deprecated-mappe |
| Fase 4 | 30 min | Oppdater dokumentasjon |
| **TESTING** | 1-2 uker | Kjør i produksjon med deprecated-mappe |
| Fase 5 | 15 min | Permanent sletting |

**Total tid (aktiv arbeid)**: ~3 timer + 1-2 ukers testing-periode

---

## ✅ Suksess-Kriterier

Oppryddingen er vellykket når:

1. ✅ Kun 5 commands vises i `php bin/console list matrikkel`
2. ✅ REST API fungerer for alle endpoints
3. ✅ Phase1 og Phase2 import fungerer perfekt
4. ✅ Ingen feilmeldinger i logs
5. ✅ README.md og dokumentasjon er oppdatert
6. ✅ Alle Services (AdresseService, etc.) fungerer som før
7. ✅ Ingen eksterne systemer klager på manglende commands

---

## 🔄 Tilbake-Rulle Plan

Hvis noe går galt:

```bash
# Flytt tilbake fra deprecated
mv src/Console/deprecated/* src/Console/

# Eller revert Git-commit
git revert HEAD
git push
```

---

**Anbefaling**: Start med **Fase 1-3** i dag, kjør testing i 1 uke, deretter **Fase 4-5**.
