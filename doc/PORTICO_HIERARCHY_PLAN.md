# Portico Lokasjonshierarki - Implementasjonsplan

**Opprettet:** 2025-12-12  
**Status:** Planlegging  
**Mål:** Eksportere Matrikkel-data til Portico med 4-nivå lokasjonshierarki

---

## 📋 Oversikt

Portico krever lokasjonshierarki i 4 nivåer:

```
Eiendom (5000)
├─ Bygg (5000-01)
│  ├─ Inngang (5000-01-01)
│  │  ├─ Bruksenhet (5000-01-01-001)
│  │  ├─ Bruksenhet (5000-01-01-002)
│  │  └─ Bruksenhet (5000-01-01-003)
│  └─ Inngang (5000-01-02)
└─ Bygg (5000-02)
```

### Lokasjonskode-struktur

| Nivå | Eksempel | Beskrivelse | Kilde |
|------|----------|-------------|-------|
| **Eiendom** | `5000` | Kombinasjon av gardsnummer + bruksnummer | `matrikkel_matrikkelenheter` |
| **Bygg** | `5000-01` | Løpenummer per eiendom | `matrikkel_bygninger` (sortert `bygning_id`) |
| **Inngang** | `5000-01-01` | Løpenummer per bygg | Unike `(husnummer, bokstav)` per bygning |
| **Bruksenhet** | `5000-01-01-001` | Løpenummer per inngang | `matrikkel_bruksenheter` (sortert etasje, lopenummer) |

---

## 🎯 Sorteringsregler

### Bygninger (per eiendom)
- **Primær:** `bygning_id` (stigende, kronologisk)
- **Resultat:** Første bygning = 01, andre = 02, osv.

### Innganger (per bygg)
- **Primær:** `husnummer` (stigende)
- **Sekundær:** `bokstav` (alfabetisk A, B, C...)
- **Tertiær:** `veg_id` (hvis samme husnummer på flere gater)
- **Resultat:** Naturlig adresseorden (10, 12, 12A, 12B, 14...)

### Bruksenheter (per inngang)
- **Primær:** `etasjenummer` (stigende, NULL først)
- **Sekundær:** `lopenummer` fra Matrikkel API (stigende)
- **Tertiær:** `bruksareal` (descending, større enheter først)
- **Resultat:** Bunnplan før 1. etg, store leiligheter før små

---

## 🗄️ Database Schema Endringer

### 1. Bygninger - Legg til løpenummer per eiendom

```sql
-- Legg til løpenummer-kolonne på bygninger
ALTER TABLE matrikkel_bygninger
ADD COLUMN lopenummer_i_eiendom INTEGER,
ADD COLUMN lokasjonskode_bygg VARCHAR(50);

-- Indekser for rask oppslag
CREATE INDEX idx_bygning_lopenummer 
  ON matrikkel_bygninger(lopenummer_i_eiendom);
CREATE INDEX idx_bygning_lokasjonskode_bygg 
  ON matrikkel_bygninger(lokasjonskode_bygg);
```

### 2. Ny tabell: Innganger

```sql
-- Opprett inngang-tabell (entrances)
CREATE TABLE matrikkel_innganger (
    inngang_id BIGSERIAL PRIMARY KEY,
    bygning_id BIGINT NOT NULL,
    veg_id BIGINT,
    husnummer INTEGER NOT NULL,
    bokstav VARCHAR(1),
    lopenummer_i_bygg INTEGER NOT NULL,
    lokasjonskode_inngang VARCHAR(50) NOT NULL,
    uuid VARCHAR(36),
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_inngang_bygning 
        FOREIGN KEY (bygning_id) 
        REFERENCES matrikkel_bygninger(bygning_id) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_inngang_veg
        FOREIGN KEY (veg_id)
        REFERENCES matrikkel_veger(veg_id)
        ON DELETE SET NULL,
    
    -- Sikrer unik inngang per bygg
    UNIQUE (bygning_id, veg_id, husnummer, bokstav)
);

-- Indekser
CREATE INDEX idx_inngang_bygning ON matrikkel_innganger(bygning_id);
CREATE INDEX idx_inngang_adresse ON matrikkel_innganger(veg_id, husnummer, bokstav);
CREATE INDEX idx_inngang_lokasjonskode ON matrikkel_innganger(lokasjonskode_inngang);
CREATE INDEX idx_inngang_lopenummer ON matrikkel_innganger(bygning_id, lopenummer_i_bygg);
```

### 3. Bruksenheter - Legg til lokasjonskoder

```sql
-- Legg til inngang-referanse og lokasjonskoder
ALTER TABLE matrikkel_bruksenheter
ADD COLUMN inngang_id BIGINT,
ADD COLUMN lopenummer_i_inngang INTEGER,
ADD COLUMN lokasjonskode_bruksenhet VARCHAR(50),
ADD CONSTRAINT fk_bruksenhet_inngang 
    FOREIGN KEY (inngang_id) 
    REFERENCES matrikkel_innganger(inngang_id) 
    ON DELETE SET NULL;

-- Indekser
CREATE INDEX idx_bruksenhet_inngang ON matrikkel_bruksenheter(inngang_id);
CREATE INDEX idx_bruksenhet_lopenummer ON matrikkel_bruksenheter(inngang_id, lopenummer_i_inngang);
CREATE INDEX idx_bruksenhet_lokasjonskode ON matrikkel_bruksenheter(lokasjonskode_bruksenhet);
```

### 4. Matrikkelenheter - Legg til eiendomskode

```sql
-- Legg til eiendom-lokasjonskode på matrikkelenheter
ALTER TABLE matrikkel_matrikkelenheter
ADD COLUMN lokasjonskode_eiendom VARCHAR(50);

CREATE INDEX idx_matrikkelenhet_lokasjonskode 
  ON matrikkel_matrikkelenheter(lokasjonskode_eiendom);
```

---

## 🔧 Implementasjonssekvens

### ✅ Fase 1: Database Migrering

- [x] **1.1** Opprett migrasjonsfil `migrations/V2__portico_hierarchy.sql`
- [x] **1.2** Kjør migrering i test-database
- [x] **1.3** Verifiser at alle foreign keys fungerer
- [x] **1.4** Test rollback-scenario
- [x] **1.5** Kjør migrering i prod-database (når klar)

**Filer å opprette:**
- `migrations/V2__portico_hierarchy.sql`

---

### ✅ Fase 2: Service-lag for Hierarkiorganisering

- [x] **2.1** Opprett `src/Service/HierarchyOrganizationService.php`
  - Metoder implementert: `organizeEiendom`, `organizeBygning`, `generateLokasjonskodeEiendom`, formatters for koder
  - TODO: Tilpasninger ved spesialtilfeller uten adresse (håndteres senere ved behov)

- [x] **2.2** Opprett `src/Repository/InngangRepository.php`
  - Metoder: `findOrCreate`, `findByBygningId`, `updateLopenummer`, `updateLokasjonskode`

- [x] **2.3** Utvid `src/Repository/BygningRepository.php`
  - Metoder: `getBygningerForEiendom`, `updateLopenummerIEiendom`, `updateLokasjonskode`

- [x] **2.4** Utvid `src/Repository/BruksenhetRepository.php`
  - Metoder: `findByBygningIdWithAdresse`, `findByInngangId`, `updateLopenummerIInngang`, `updateInngangReference`, `updateLokasjonskode`

**Filer å opprette:**

- `src/Service/HierarchyOrganizationService.php`
- `src/Repository/InngangRepository.php`

**Filer å utvide:**

- `src/Repository/BygningRepository.php`
- `src/Repository/BruksenhetRepository.php`
- `src/Repository/MatrikkelenhetRepository.php`

---

### ✅ Fase 3: Konsollkommando for Hierarkiorganisering

- [x] **3.1** Opprett `src/Console/OrganizeHierarchyCommand.php`
  - Argument: `--kommune=XXXX` (organiserer alle eiendommer i kommunen)
  - Argument: `--matrikkelenhet=XXXXXX` (organiserer en spesifikk eiendom)
  - Argument: `--force` (overskriver eksisterende løpenummer)
  - Progressbar for store datamengder

- [x] **3.2** Test kommando på test-data

  ```bash
  php bin/console matrikkel:organize-hierarchy --kommune=4627
  ```

**Filer å opprette:**

- `src/Console/OrganizeHierarchyCommand.php`

---

### ✅ Fase 4: Portico Export API

- [x] **4.1** Opprett `src/Controller/PorticoExportController.php`
  - Endepunkt: `GET /api/portico/export`
  - Parameter: `?kommune=XXXX&organisasjonsnummer=XXXXXX`
  - Parameter: `?matrikkelenhet=XXXXXX` (eksporter én eiendom)
  - Returner hierarkisk JSON struktur

- [x] **4.2** Opprett `src/Service/PorticoExportService.php`
  - Metode: `export(int $kommune, ?string $organisasjonsnummer): array`
  - Bygger hierarkisk JSON med alle 4 nivåer

- [x] **4.3** Legg til rute i `config/routes/api.yaml`

  ```yaml
  portico:
      resource: '../../src/Controller/PorticoExportController.php'
      type: attribute
  ```

- [x] **4.4** Test API lokalt

  ```bash
  curl "http://localhost:8083/api/portico/export?kommune=4627"
  ```

**Filer å opprette:**

- `src/Controller/PorticoExportController.php`
- `src/Service/PorticoExportService.php`

**Filer å utvide:**

- `config/routes/api.yaml`

---

### ✅ Fase 5: Testing og Validering

- [ ] **5.1** Unit tests for `HierarchyOrganizationService`
- [ ] **5.2** Integration test for hele hierarkiet
- [ ] **5.3** Verifiser at løpenummer er konsistente ved gjentagende kjøringer
- [ ] **5.4** Test med store datasett (f.eks hele Bergen kommune)
- [ ] **5.5** Valider JSON-eksport mot Portico's schema (hvis tilgjengelig)
- [ ] **5.6** Test edge cases:
  - Bygning uten adresse
  - Bruksenhet uten bygning
  - Flere bygninger på samme matrikkelenhet
  - Samme husnummer på flere bygninger

**Filer å opprette:**

- `tests/Service/HierarchyOrganizationServiceTest.php`
- `tests/Controller/PorticoExportControllerTest.php`

---

### ✅ Fase 6: Dokumentasjon

- [ ] **6.1** Oppdater `README.md` med Portico export kommando
- [ ] **6.2** Oppdater `.github/copilot-instructions.md` med hierarki-info
- [ ] **6.3** Dokumenter API endepunkt i OpenAPI/Swagger (hvis brukt)
- [ ] **6.4** Lag eksempel-payload for Portico export
- [ ] **6.5** Dokumenter kjørerekkefølge for produksjon:
  1. Importer data (Phase 1 + Phase 2)
  2. Organiser hierarki
  3. Eksporter til Portico

**Filer å oppdatere:**

- `README.md`
- `.github/copilot-instructions.md`

---

## 🔍 SQL Eksempler

### Hent komplett hierarki for en eiendom

```sql
SELECT 
  -- Eiendom
  m.matrikkelenhet_id,
  m.lokasjonskode_eiendom AS eiendom_kode,
  m.matrikkelnummer_tekst,
  m.kommunenummer,
  
  -- Bygning
  b.bygning_id,
  b.lopenummer_i_eiendom,
  b.lokasjonskode_bygg AS bygg_kode,
  b.matrikkel_bygning_nummer,
  b.bygningstype_kode_id,
  
  -- Inngang
  i.inngang_id,
  i.lopenummer_i_bygg,
  i.lokasjonskode_inngang AS inngang_kode,
  i.husnummer,
  i.bokstav,
  v.adressenavn AS gatenavn,
  
  -- Bruksenhet
  br.bruksenhet_id,
  br.lopenummer_i_inngang,
  br.lokasjonskode_bruksenhet AS bruksenhet_kode,
  br.bruksareal,
  br.etasjenummer,
  br.antall_rom

FROM matrikkel_matrikkelenheter m

-- Bygninger via junction table
LEFT JOIN matrikkel_bygning_matrikkelenhet bm 
  ON m.matrikkelenhet_id = bm.matrikkelenhet_id
LEFT JOIN matrikkel_bygninger b 
  ON bm.bygning_id = b.bygning_id

-- Innganger
LEFT JOIN matrikkel_innganger i 
  ON b.bygning_id = i.bygning_id

-- Veger (for gatenavn)
LEFT JOIN matrikkel_veger v 
  ON i.veg_id = v.veg_id

-- Bruksenheter
LEFT JOIN matrikkel_bruksenheter br 
  ON i.inngang_id = br.inngang_id

WHERE m.matrikkelenhet_id = ?

ORDER BY 
  b.lopenummer_i_eiendom, 
  i.lopenummer_i_bygg, 
  br.lopenummer_i_inngang;
```

### Tell enheter per nivå

```sql
SELECT 
  m.lokasjonskode_eiendom,
  m.matrikkelnummer_tekst,
  COUNT(DISTINCT b.bygning_id) AS antall_bygninger,
  COUNT(DISTINCT i.inngang_id) AS antall_innganger,
  COUNT(br.bruksenhet_id) AS antall_bruksenheter
FROM matrikkel_matrikkelenheter m
LEFT JOIN matrikkel_bygning_matrikkelenhet bm ON m.matrikkelenhet_id = bm.matrikkelenhet_id
LEFT JOIN matrikkel_bygninger b ON bm.bygning_id = b.bygning_id
LEFT JOIN matrikkel_innganger i ON b.bygning_id = i.bygning_id
LEFT JOIN matrikkel_bruksenheter br ON i.inngang_id = br.inngang_id
WHERE m.kommunenummer = ?
GROUP BY m.matrikkelenhet_id, m.lokasjonskode_eiendom, m.matrikkelnummer_tekst
ORDER BY antall_bruksenheter DESC;
```

### Finn bruksenheter uten inngang

```sql
-- Identifiser data-kvalitet issues
SELECT 
  br.bruksenhet_id,
  br.matrikkelenhet_id,
  br.bygning_id,
  br.adresse_id,
  m.matrikkelnummer_tekst,
  b.matrikkel_bygning_nummer
FROM matrikkel_bruksenheter br
LEFT JOIN matrikkel_innganger i ON br.inngang_id = i.inngang_id
LEFT JOIN matrikkel_matrikkelenheter m ON br.matrikkelenhet_id = m.matrikkelenhet_id
LEFT JOIN matrikkel_bygninger b ON br.bygning_id = b.bygning_id
WHERE br.inngang_id IS NULL
  AND br.bygning_id IS NOT NULL
ORDER BY m.matrikkelnummer_tekst;
```

---

## 📤 Forventet JSON Output (Portico Export)

```json
{
  "data": {
    "eiendommer": [
      {
        "lokasjonskode": "5000",
        "matrikkelenhet_id": 123456789,
        "matrikkelnummer": "4627-100/50",
        "kommunenummer": 4627,
        "kommunenavn": "Askøy",
        "bygninger": [
          {
            "lokasjonskode": "5000-01",
            "bygning_id": 987654321,
            "bygning_nummer": 100001234,
            "lopenummer": 1,
            "bygningstype_kode": 111,
            "byggeaar": 1985,
            "bruksareal": 450.5,
            "innganger": [
              {
                "lokasjonskode": "5000-01-01",
                "inngang_id": 555,
                "lopenummer": 1,
                "gatenavn": "Strandgata",
                "husnummer": 12,
                "bokstav": null,
                "bruksenheter": [
                  {
                    "lokasjonskode": "5000-01-01-001",
                    "bruksenhet_id": 777888,
                    "lopenummer": 1,
                    "etasjenummer": 1,
                    "bruksareal": 85.2,
                    "antall_rom": 3,
                    "bruksenhettype_kode": 101
                  },
                  {
                    "lokasjonskode": "5000-01-01-002",
                    "bruksenhet_id": 777889,
                    "lopenummer": 2,
                    "etasjenummer": 2,
                    "bruksareal": 92.3,
                    "antall_rom": 4,
                    "bruksenhettype_kode": 101
                  }
                ]
              },
              {
                "lokasjonskode": "5000-01-02",
                "inngang_id": 556,
                "lopenummer": 2,
                "gatenavn": "Strandgata",
                "husnummer": 12,
                "bokstav": "A",
                "bruksenheter": [...]
              }
            ]
          },
          {
            "lokasjonskode": "5000-02",
            "bygning_id": 987654322,
            "lopenummer": 2,
            "innganger": [...]
          }
        ]
      }
    ]
  },
  "timestamp": "2025-12-12T14:30:00+00:00",
  "status": "success"
}
```

---

## 🧪 Testplan

### Test 1: Enkelt hierarki

- **Gitt:** 1 eiendom, 1 bygning, 1 inngang, 3 bruksenheter
- **Forventet:** Lokasjonskoder 5000, 5000-01, 5000-01-01, 5000-01-01-001/002/003

### Test 2: Flere bygninger

- **Gitt:** 1 eiendom, 3 bygninger
- **Forventet:** Bygg-koder 5000-01, 5000-02, 5000-03 (sortert etter bygning_id)

### Test 3: Flere innganger per bygg

- **Gitt:** 1 bygning, adresser: 10, 12A, 12B, 14
- **Forventet:** Inngang-koder 5000-01-01, 5000-01-02, 5000-01-03, 5000-01-04

### Test 4: Komplekst hierarki

- **Gitt:** 2 eiendommer, 5 bygninger, 12 innganger, 45 bruksenheter
- **Forventet:** Alle lokasjonskoder unike og konsistente

### Test 5: Edge case - Bruksenhet uten bygning

- **Gitt:** Bruksenhet med matrikkelenhet_id, men bygning_id = NULL
- **Forventet:** Skip eller special handling (dokumenter beslutning)

### Test 6: Idempotens

- **Gitt:** Kjør organize-hierarchy kommando 2 ganger
- **Forventet:** Identiske lokasjonskoder begge ganger

### Test 7: Performance

- **Gitt:** Hele Bergen kommune (ca. 30,000+ eiendommer)
- **Forventet:** Organisering fullført < 5 minutter

---

## 🚀 Produksjonskjøring

### Initiell setup (første gang)

```bash
# 1. Kjør database migrering
php bin/console doctrine:migrations:migrate --no-interaction

# 2. Importer data (hvis ikke allerede gjort)
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442

# 3. Organiser hierarki
php bin/console matrikkel:organize-hierarchy --kommune=4627

# 4. Eksporter til Portico
curl "http://localhost:8083/api/portico/export?kommune=4627" > portico_export.json
```

### Oppdatering ved nye data

```bash
# 1. Re-import oppdatert data
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442

# 2. Re-organisér hierarki (med --force for å overskrive)
php bin/console matrikkel:organize-hierarchy --kommune=4627 --force

# 3. Eksporter på nytt
curl "http://localhost:8083/api/portico/export?kommune=4627" > portico_export_updated.json
```

---

## 📝 Beslutninger og Antakelser

### Beslutning 1: Lagre lokasjonskoder i database

**Begrunnelse:** Sikrer konsistens ved påfølgende eksporter. Unngår at løpenummer endres hvis data reimporteres.

### Beslutning 2: Sortering av bygninger etter bygning_id

**Begrunnelse:** Kronologisk orden; første bygning registrert får løpenummer 01.

### Beslutning 3: Inngang = unik (bygning_id, veg_id, husnummer, bokstav)

**Begrunnelse:** Samme adresse kan forekomme på flere bygninger; må skille per bygg.

### Beslutning 4: Bruksenheter sortert etter etasjenummer

**Begrunnelse:** Gir naturlig orden fra bunn til topp; brukervennlig.

### Antakelse 1: Eiendomskode er fri å velge

**Status:** Bruker formatert matrikkelnummer (f.eks "4627-100-50" → "5000"). Kan endres.

### Antakelse 2: Portico kan håndtere NULL-verdier

**Status:** Hvis bruksenhet mangler bygning eller inngang, inkluderes de i payload med null.

---

## 🔄 Neste Steg

1. **Review:** Gjennomgå denne planen med team/arkitekt
2. **Godkjenn:** Få godkjenning på database schema endringer
3. **Implementer:** Start med Fase 1 (database migrering)
4. **Test:** Kjør testplan etter hver fase
5. **Deploy:** Produksjonssetting når alle faser er OK

---

**Eier:** [Ditt navn]  
**Sist oppdatert:** 2025-12-12
