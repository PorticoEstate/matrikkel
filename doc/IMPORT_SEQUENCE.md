# Import-sekvens for Matrikkel-data

**Opprettet**: 8. oktober 2025  
**Status**: Implementasjonsguide for NedlastningServiceWS bulk-import

---

## 📊 Oversikt med avhengigheter

```
                    ┌─────────────────────────────────────┐
                    │         1. KOMMUNE                  │
                    │  (Ingen avhengigheter)              │
                    │  - 883 norske kommuner              │
                    │  - Grunnlagsdata for alt annet      │
                    └──────────────┬──────────────────────┘
                                   │
                                   │ kommunenummer (FK)
                                   │
                                   ▼
                    ┌─────────────────────────────────────┐
                    │      2. MATRIKKELENHET              │
                    │  (Avhenger av: Kommune)             │
                    │  - Grunneiendommer                  │
                    │  - Festegrunner                     │
                    │  - Seksjoner                        │
                    │  - Inneholder: eier_person_id       │
                    │                eier_juridisk_person_id │
                    └──────────────┬──────────────────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    │              │              │
                    ▼              ▼              ▼
        ┌───────────────┐  ┌─────────────┐  ┌─────────────┐
        │  3a. PERSON   │  │ 3b. JURIDISK│  │ 4. BYGNING  │
        │  (On-demand)  │  │    PERSON   │  │ (Avhenger:  │
        │               │  │ (On-demand) │  │  Kommune)   │
        │ Via StoreService│  │             │  │             │
        │ - FysiskPerson │  │ - Organisasjon│  │ - bygningsnr│
        │ - Navn         │  │ - Org.nummer │  │ - byggeår   │
        │ - Adresse      │  │ - Org.form   │  │ - areal     │
        └───────────────┘  └─────────────┘  └──────┬──────┘
                                                     │
                                      ┌──────────────┼──────────────┐
                                      │              │              │
                                      ▼              ▼              ▼
                           ┌──────────────┐  ┌─────────────┐  ┌─────────────┐
                           │   5. VEG     │  │  6. ADRESSE │  │7. BYGNING-  │
                           │   (GATE)     │  │ (Avhenger:  │  │MATRIKKELENHET│
                           │ (Avhenger:   │  │  Bygning,   │  │  KOBLING    │
                           │  Kommune)    │  │  Veg,       │  │ (Avhenger:  │
                           │              │  │  Matrikkel) │  │  Bygning,   │
                           │ - gatenavn   │  │             │  │  Matrikkel) │
                           │ - gatekode   │  │ - adresse_id│  │             │
                           └──────────────┘  └──────┬──────┘  │ Many-to-Many│
                                                     │         └─────────────┘
                                                     │
                                                     ▼
                                             ┌──────────────┐
                                             │8. BRUKSENHET │
                                             │ (Avhenger:   │
                                             │  Adresse,    │
                                             │  Matrikkel)  │
                                             │              │
                                             │ - leiligheter│
                                             │ - næringslok │
                                             └──────────────┘
```

---

## 🎯 Anbefalt kommando-sekvens

### **Fase 1: Grunnlagsdata** (Kjør først)

```bash
# 1. Importer alle norske kommuner (883 stk)
# Estimert tid: 2-5 sekunder
# Resultat: matrikkel_kommuner fylt med grunnlagsdata
php bin/console matrikkel:kommune-import

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_kommuner;"
# Forventet: 883
```

---

### **Fase 2: Eiendomsdata** (Per kommune)

```bash
# 2a. Importer matrikkelenheter for én kommune (f.eks. Oslo)
# Estimert tid: 1-5 minutter avhengig av størrelse
# Resultat: matrikkel_matrikkelenheter med eier-IDer
php bin/console matrikkel:matrikkelenhet-import \
  --kommune=301 \
  --batch-size=1000

# 2b. Importer matrikkelenheter MED automatisk eier-fetch
# Estimert tid: 2-10 minutter
# Resultat: matrikkel_matrikkelenheter + matrikkel_personer + matrikkel_juridiske_personer
php bin/console matrikkel:matrikkelenhet-import \
  --kommune=301 \
  --batch-size=1000 \
  --fetch-eiere

# Verifiser matrikkelenheter:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_matrikkelenheter WHERE kommunenummer = 301;"

# Verifiser eiere:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT 
        COUNT(DISTINCT eier_person_id) as personer,
        COUNT(DISTINCT eier_juridisk_person_id) as juridiske
      FROM matrikkel_matrikkelenheter 
      WHERE kommunenummer = 301;"
```

---

### **Fase 3: Eierdata** (On-demand etter behov)

```bash
# 3. Hent kun eiere (hvis ikke allerede gjort med --fetch-eiere)
# Dette kommandoen finnes ikke ennå, men ville se slik ut:
php bin/console matrikkel:eier-import \
  --kommune=301 \
  --batch-size=100

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_personer;"

psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_juridiske_personer;"
```

---

### **Fase 4: Bygningsdata** (📋 Planlagt - ikke implementert ennå)

```bash
# 4. Importer bygninger for én kommune
# Estimert tid: 1-5 minutter
# Resultat: matrikkel_bygninger
php bin/console matrikkel:bygning-import \
  --kommune=301 \
  --batch-size=1000

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_bygninger WHERE kommunenummer = 301;"
```

---

### **Fase 5: Gate/Veg-data** (📋 Planlagt)

```bash
# 5. Importer gater/veier for én kommune
# Estimert tid: 30 sekunder - 2 minutter
# Resultat: matrikkel_gater
php bin/console matrikkel:gate-import \
  --kommune=301

# Alternativ: Ekstraher fra eksisterende adresse-data
php bin/console matrikkel:gate-extract-from-addresses \
  --kommune=301

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_gater WHERE kommunenummer = 301;"
```

---

### **Fase 6: Adresse-data** (📋 Planlagt - utvide eksisterende)

```bash
# 6. Importer adresser via SOAP (utvide eksisterende CSV-import)
# Estimert tid: 2-10 minutter
# Resultat: matrikkel_adresser (SOAP-data)
php bin/console matrikkel:adresse-import \
  --kommune=301 \
  --source=soap \
  --eier-filter

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_adresser 
      WHERE kommunenummer = 301 
      AND matrikkelenhet_id IS NOT NULL;"
```

---

### **Fase 7: Bruksenhet-data** (📋 Planlagt)

```bash
# 7. Importer bruksenheter via SOAP (utvide eksisterende CSV-import)
# Estimert tid: 2-10 minutter
# Resultat: matrikkel_bruksenheter (SOAP-data)
php bin/console matrikkel:bruksenhet-import \
  --kommune=301 \
  --source=soap \
  --eier-filter

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_bruksenheter 
      WHERE matrikkelenhet_id IN (
        SELECT matrikkelenhet_id 
        FROM matrikkel_matrikkelenheter 
        WHERE kommunenummer = 301
      );"
```

---

### **Fase 8: Koblingstabeller** (📋 Planlagt)

```bash
# 8. Bygg kobling mellom bygninger og matrikkelenheter
# Estimert tid: 1-3 minutter
# Resultat: matrikkel_bygning_matrikkelenhet (junction table)
php bin/console matrikkel:bygning-matrikkelenhet-kobling \
  --kommune=301

# Verifiser:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT COUNT(*) FROM matrikkel_bygning_matrikkelenhet;"

# Test JOIN:
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel \
  -c "SELECT b.bygningsnummer, m.matrikkelnummer_tekst
      FROM matrikkel_bygninger b
      JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
      JOIN matrikkel_matrikkelenheter m ON bm.matrikkelenhet_id = m.matrikkelenhet_id
      WHERE b.kommunenummer = 301
      LIMIT 10;"
```

---

## 🔄 Komplett import for én kommune

```bash
#!/bin/bash
# Komplett import-script for én kommune (f.eks. Oslo = 301)

KOMMUNE=301
BATCH_SIZE=1000

echo "=== Starter komplett import for kommune $KOMMUNE ==="

# 1. Sjekk at kommuner er importert
echo "1. Sjekker kommuner..."
php bin/console matrikkel:kommune-import 2>&1 | grep "allerede importert" || \
  php bin/console matrikkel:kommune-import

# 2. Importer matrikkelenheter med eiere
echo "2. Importerer matrikkelenheter med eiere..."
php bin/console matrikkel:matrikkelenhet-import \
  --kommune=$KOMMUNE \
  --batch-size=$BATCH_SIZE \
  --fetch-eiere

# 3. Importer bygninger (når implementert)
echo "3. Importerer bygninger..."
# php bin/console matrikkel:bygning-import --kommune=$KOMMUNE --batch-size=$BATCH_SIZE

# 4. Importer gater (når implementert)
echo "4. Importerer gater..."
# php bin/console matrikkel:gate-import --kommune=$KOMMUNE

# 5. Importer adresser (når implementert)
echo "5. Importerer adresser..."
# php bin/console matrikkel:adresse-import --kommune=$KOMMUNE --source=soap --eier-filter

# 6. Importer bruksenheter (når implementert)
echo "6. Importerer bruksenheter..."
# php bin/console matrikkel:bruksenhet-import --kommune=$KOMMUNE --source=soap --eier-filter

# 7. Bygg koblinger (når implementert)
echo "7. Bygger bygning-matrikkelenhet koblinger..."
# php bin/console matrikkel:bygning-matrikkelenhet-kobling --kommune=$KOMMUNE

echo "=== Import fullført for kommune $KOMMUNE ==="

# Verifisering
echo ""
echo "=== Verifikasjonsstatistikk ==="
psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel << EOF
SELECT 
  'Matrikkelenheter' as tabell, 
  COUNT(*) as antall 
FROM matrikkel_matrikkelenheter 
WHERE kommunenummer = $KOMMUNE

UNION ALL

SELECT 
  'Bygninger', 
  COUNT(*) 
FROM matrikkel_bygninger 
WHERE kommunenummer = $KOMMUNE

UNION ALL

SELECT 
  'Gater', 
  COUNT(*) 
FROM matrikkel_gater 
WHERE kommunenummer = $KOMMUNE

UNION ALL

SELECT 
  'Adresser', 
  COUNT(*) 
FROM matrikkel_adresser 
WHERE kommunenummer = $KOMMUNE

UNION ALL

SELECT 
  'Personer (eiere)', 
  COUNT(*) 
FROM matrikkel_personer

UNION ALL

SELECT 
  'Juridiske personer', 
  COUNT(*) 
FROM matrikkel_juridiske_personer;
EOF
```

---

## 🌍 Import for hele Norge (alle kommuner)

```bash
#!/bin/bash
# ADVARSEL: Tar VELDIG lang tid (flere timer til dager)
# Anbefales kun for produksjonssystem med høy kapasitet

echo "=== Starter FULL import for alle kommuner ==="
echo "ADVARSEL: Dette tar lang tid!"

# 1. Import alle kommuner
php bin/console matrikkel:kommune-import

# 2. Hent liste med alle kommunenummer
KOMMUNER=$(psql -h 10.0.2.15 -p 5435 -U hc483 -d matrikkel -t -c \
  "SELECT kommunenummer FROM matrikkel_kommuner ORDER BY kommunenummer;")

# 3. Loop gjennom hver kommune
for KOMMUNE in $KOMMUNER; do
  echo ""
  echo "=== Prosesserer kommune $KOMMUNE ==="
  
  # Import matrikkelenheter med eiere
  php bin/console matrikkel:matrikkelenhet-import \
    --kommune=$KOMMUNE \
    --batch-size=1000 \
    --fetch-eiere
  
  # Legg til bygninger, gater, etc når de er implementert
  
  echo "=== Ferdig med kommune $KOMMUNE ==="
done

echo ""
echo "=== FULL import fullført for alle kommuner ==="
```

---

## 📋 Sjekkliste for produksjonssetting

- [ ] **1. Kommuner importert**: `SELECT COUNT(*) FROM matrikkel_kommuner;` → 883
- [ ] **2. Matrikkelenheter for primærkommuner**: Test 5-10 store kommuner
- [ ] **3. Eiere hentet**: Verifiser at personer og juridiske personer finnes
- [ ] **4. Bygninger importert**: Når implementert
- [ ] **5. Gater/Veier importert**: Når implementert
- [ ] **6. Adresser koblet**: Når SOAP-import er klar
- [ ] **7. Bruksenheter koblet**: Når SOAP-import er klar
- [ ] **8. Koblingstabeller**: Junction tables populert
- [ ] **9. Indexes optimalisert**: ANALYZE kjørt på alle tabeller
- [ ] **10. REST API testet**: Verifiser at endepunkter fungerer

---

## ⚠️ Viktige merknader

### API-begrensninger:
- **NedlastningServiceWS**: Max 5,000 objekter per kall (findObjekterEtterId)
- **StoreService**: Max 1,000 objekter per kall (getObjects) - ustabil API
- **Paginering**: Bruk cursor-basert (matrikkelBubbleId) for konsistens

### Database-begrensninger:
- **Foreign keys**: Midlertidig fjernet for matrikkel_matrikkelenheter (tillater lazy loading av eiere)
- **CHECK constraints**: Fjernet for å tillate 'ukjent' eier-type

### Ytelse:
- **Store kommuner** (Oslo, Bergen, Trondheim): 10-30 minutter per kommune
- **Små kommuner**: 1-5 minutter per kommune
- **Hele Norge**: Estimert 20-40 timer (avhengig av API-stabilitet)

### Stabilitet:
- **SOAP-feil**: API kan kaste feil etter 1-2 batches (kjent problem)
- **Retry-strategi**: Implementert i import-services
- **Resume**: Cursor-basert paginering tillater gjenopptaking

---

## 🎯 Status per 8. oktober 2025

| Fase | Kommando | Status | Funksjoner |
|------|----------|--------|------------|
| 1 | `matrikkel:kommune-import` | ✅ Implementert | Paginering, retry-logikk |
| 2 | `matrikkel:matrikkelenhet-import` | ✅ Implementert | Filter, eier-fetch, paginering |
| 3 | `matrikkel:eier-import` | 🔧 Delvis (integrert i trinn 2) | On-demand via StoreService |
| 4 | `matrikkel:bygning-import` | ⏳ Neste | Planlagt |
| 5 | `matrikkel:gate-import` | 📋 Planlagt | - |
| 6 | `matrikkel:adresse-import` | 📋 Planlagt | Utvide eksisterende |
| 7 | `matrikkel:bruksenhet-import` | 📋 Planlagt | Utvide eksisterende |
| 8 | `matrikkel:bygning-matrikkelenhet-kobling` | 📋 Planlagt | - |

**Neste steg**: Implementere `matrikkel:bygning-import` (Trinn 6) 🚀
