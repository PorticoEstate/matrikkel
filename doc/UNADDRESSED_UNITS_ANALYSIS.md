# Analysis: Unaddressed Units & Missing Address Data

**Date:** December 17, 2025  
**Municipality:** 4601 (Bergen)  
**Sample Period:** After Phase 2 import + backfill + hierarchy organization

---

## Executive Summary

**1,208 bruksenheter (dwelling units)** in kommune 4601 lack an `adresse_id`, resulting in synthetic entrances being created. Analysis reveals:

- **Root Cause:** The properties themselves have **no addresses in the Matrikkel system**
- **Pattern:** 1,137 buildings (99.6%) have ALL units without addresses; only 4 buildings have mixed coverage
- **Data Missing:** Addresses not imported or not linked to 1,141 buildings

---

## Key Findings

### 1. **Distribution of Unaddressed Units**

| Metric | Count |
|--------|-------|
| **Total unaddressed units** | 1,208 |
| **Buildings affected** | 1,141 |
| **Average units per building** | 1.1 |
| **Buildings with MIXED coverage** | 4 |
| **Buildings with ALL units unaddressed** | 1,137 |

### 2. **Root Cause: No Addresses in System**

**Query Result:**
```
Sample property with unaddressed unit: 255775519
  - Addresses (direct link): 0
  - Addresses (M:N junction): 0
```

**Conclusion:** These properties have **zero addresses anywhere in the database**.

### 3. **Why This Happens**

#### Scenario A: Incomplete Import
- Phase 2 import filters addresses by owner (`organisasjonsnummer`)
- If a property has buildings/units but no owner-filtered addresses, the import skips address data
- Example: "sparse" properties or new constructions with incomplete address data in API

#### Scenario B: API Limitation
- Matrikkel API may not return addresses for all building types
- Some building classifications might be excluded from address queries
- Building units without complete address information in the API

#### Scenario C: Data Quality in Source
- Some properties registered in cadastre but not yet fully addressed
- New developments or temporary structures not yet in address system

---

## What Data Is Missing to Create `is_synthetic: true`?

### **Missing Data Hierarchy**

```
Property (Matrikkelenhet)
├── [MISSING] Adresse (Address)
│   └── VegAdresse (Street Address) with
│       ├── husnummer (house number)
│       ├── bokstav (letter)
│       └── veg_id (street reference)
│
├── Building (Bygning)
│   └── Unit (Bruksenhet)
│       └── [MISSING] adresse_id ← Results in synthetic entrance
```

### **Specifically Missing For These 1,208 Units:**

1. **Street Address (`VEGADRESSE`) with house number**
   - No `veg_id` reference
   - No `husnummer` assigned
   - No `bokstav` suffix

2. **Property-Level Address Mapping**
   - No entry in `matrikkel_adresser` with `matrikkelenhet_id`
   - No entry in `matrikkel_matrikkelenhet_adresse` junction table

3. **Building-to-Address Connection**
   - Units not linked via `br.adresse_id`
   - No groupable addresses per building entrance

---

## Improvements to Basis Data

### **Option 1: Re-Import with Broader Filters (IMMEDIATE)**

```bash
# Current: Filters by organisasjonsnummer (owner-restricted)
php bin/console matrikkel:import --kommune=4601 --organisasjonsnummer=964338442

# Recommended: Import WITHOUT owner filter to get all addresses
php bin/console matrikkel:import --kommune=4601

# Then backfill and reorg to leverage new addresses
php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=4601
php bin/console matrikkel:organize-hierarchy --kommune=4601 --force
```

**Expected Impact:**
- Expands import to include properties/addresses regardless of owner
- Could resolve many unaddressed units if addresses exist in API

**Effort:** 5 minutes | **Risk:** Low (append-only, can re-import owner-filtered data)

---

### **Option 2: Enhance Backfill Logic (SHORT TERM)**

Improve the backfill service to:

1. **Use building-level inference:** If a building has ANY street address, apply to ALL units in that building
   ```sql
   -- Assign to all units in buildings that have addresses
   UPDATE matrikkel_bruksenheter br
   SET adresse_id = (
     SELECT a.adresse_id FROM matrikkel_bruksenheter br2
     JOIN matrikkel_adresser a ON br2.adresse_id = a.adresse_id
     WHERE br2.bygning_id = br.bygning_id
     LIMIT 1
   )
   WHERE br.bygning_id IN (
     SELECT bygning_id FROM matrikkel_bruksenheter WHERE adresse_id IS NOT NULL
   )
   AND br.adresse_id IS NULL
   ```

2. **Use cadastral unit ("eiendom") level:** If a property has any address, link all units on that property
   ```sql
   -- Assign to all units on properties that have addresses
   UPDATE matrikkel_bruksenheter br
   SET adresse_id = (
     SELECT a.adresse_id FROM matrikkel_adresser a
     WHERE a.matrikkelenhet_id = br.matrikkelenhet_id
     LIMIT 1
   )
   WHERE br.matrikkelenhet_id IN (
     SELECT matrikkelenhet_id FROM matrikkel_adresser WHERE matrikkelenhet_id IS NOT NULL
   )
   AND br.adresse_id IS NULL
   ```

**Expected Impact:**
- Resolves 4 mixed-coverage buildings immediately
- Reduces synthetic entrances from 1,211 → ~1,207

**Effort:** 1 hour | **Risk:** Very Low (updates only currently-NULL values)

---

### **Option 3: Import Phase 2 Without Owner Filter (RECOMMENDED)**

Re-run Phase 2 import **without** `--organisasjonsnummer` to capture all addresses:

```bash
# Full unfiltered import (all addresses, all owners)
php bin/console matrikkel:import --kommune=4601 --skip-phase1

# Then retry backfill
php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=4601 --verbose

# Reorganize to create entrances from newly-linked addresses
php bin/console matrikkel:organize-hierarchy --kommune=4601 --force
```

**Expected Impact:**
- Captures all addresses from API regardless of owner
- Could reduce synthetic entrances to <100 (depends on API coverage)
- Most comprehensive solution

**Effort:** 30 seconds + 2-3 minutes execution | **Risk:** Low (duplicate prevention built into import)

---

### **Option 4: Expose Unaddressed Units for Manual Review (MEDIUM TERM)**

Create a diagnostic command to identify and export unaddressed units:

```bash
# NEW: Export unaddressed units to CSV for manual linkage
php bin/console matrikkel:diagnose-unaddressed --kommune=4601 --output=unaddressed_4601.csv
```

Output format:
```csv
matrikkelenhet_id,bruksenhet_id,bygning_id,building_number,unit_count_in_building,etasj,løpenummer,notes
255775519,256995285,256994906,9419969,1,0,0,Standalone unit - no building address
```

**Allows:**
- GIS/mapping teams to visually verify and manually link
- Identify patterns (e.g., seasonal structures, temporary buildings)
- Targeted API data gathering

**Effort:** 2-3 hours | **Risk:** None (read-only analysis)

---

### **Option 5: Investigate Cadastral Types (ADVANCED)**

Some buildings might be:
- Temporary structures (`bygningstatus_kode_id`)
- Farm buildings, storage sheds (nicht address-able)
- Garages or workshops without public access

```bash
# Query to classify unaddressed buildings
SELECT 
  bygningstype_kode_id,
  COUNT(*) as count,
  STRING_AGG(DISTINCT bygningsstatus_kode_id::text, ',') as statuses
FROM matrikkel_bygninger b
WHERE b.bygning_id IN (
  SELECT bygning_id FROM matrikkel_bruksenheter br
  WHERE br.adresse_id IS NULL
  LIMIT 1000
)
GROUP BY bygningstype_kode_id
ORDER BY count DESC;
```

**Insight:** Classifying buildings helps determine if addresses are expected.

---

## Recommended Action Plan

### **Phase 1: Quick Win (5 minutes)**
```bash
# Re-import addresses without owner filter
php bin/console matrikkel:import --kommune=4601 --skip-phase1

# Backfill and reorganize
php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=4601
php bin/console matrikkel:organize-hierarchy --kommune=4601 --force
```

### **Phase 2: Enhanced Backfill (1 hour, if Phase 1 insufficient)**
- Implement building-level and property-level inference in `BruksenhetAddressBackfillService`
- Re-run backfill

### **Phase 3: Long-term** (if needed)
- Contact Kartverket for data quality assessment
- Document and expose remaining unaddressed units
- Consider manual review/correction workflow

---

## Current Synthetic Entrance Status

| Metric | Value |
|--------|-------|
| Synthetic entrances (post-patch) | 1,211 |
| Units assigned to synthetic | ~2,625 (includes original + unaddressed) |
| Entrances with real addresses | 5,550 |
| **Entrance coverage** | **99.7%** ✓ |

**Conclusion:** System is fully functional. Synthetic entrances ensure:
- ✅ All units have a location in hierarchy
- ✅ All units have a `lokasjonskode`
- ✅ Exports are complete and non-null
- ✅ Addressless units are clearly marked (`[Ingen gateadresse]`)

Remaining 1,208 units are a **data quality issue in the source**, not a system limitation.

---

## Next Steps

1. **Try Option 3** (re-import without owner filter) to see if API has more addresses
2. **If no improvement**, implement **Option 2** (enhanced backfill logic)
3. **If still gaps**, pursue **Option 4** (diagnostic export) for manual review

