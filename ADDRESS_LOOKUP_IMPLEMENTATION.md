# Address-Based Data Supplement Implementation

## Implementation Complete ✓

Three new classes have been created to support looking up and supplementing address data via street address components (gatenavn + husnummer + bokstav):

### 1. **AddressLookupService** (`src/Service/AddressLookupService.php`)

Core service implementing street address lookup via SOAP API with automatic disambiguation:

**Key Method:**
```php
public function findByStreetAddress(
    string $gatenavn,      // Street name, e.g., "Storgata"
    int $husnummer,        // House number, e.g., 42
    ?string $bokstav,      // Optional letter suffix, e.g., "A"
    int $kommunenummer,    // Municipality number, e.g., 4627
    ?SymfonyStyle $io = null,  // Console I/O for prompts
    bool $fetchRelated = true   // Fetch linked matrikkelenhet/bruksenheter
): ?FoundAddress
```

**Features:**
- ✅ Automatically finds Veg by street name via `findVegerMedNavn()`
- ✅ **User disambiguation prompts** when multiple streets have the same name
- ✅ Finds specific vegadresse via `findVegadresse()` with husnummer + bokstav
- ✅ Fetches full address object from SOAP API
- ✅ Optionally fetches linked matrikkelenheter and bruksenheter
- ✅ Returns structured `FoundAddress` DTO with all details

**Lookup Workflow:**
```
Input: kommune=4627, gatenavn="Storgata", husnummer=42, bokstav="A"
  ↓
Step 1: AdresseClient.findVegerMedNavn()
  → Returns: List of Veg objects matching "Storgata" in kommune 4627
  → If multiple matches: **Prompts user to choose** (interactive table)
  ↓
Step 2: Extract adressekode from chosen Veg
  ↓
Step 3: AdresseClient.findVegadresse(kommune, adressekode, husnummer, bokstav)
  → Returns: VegadresseId (integer address ID)
  ↓
Step 4: StoreClient.getObjects([new AdresseId(vegadresseId)])
  → Returns: Full Vegadresse object with all properties
  ↓
Step 5 (Optional): Fetch linked matrikkelenhet and bruksenheter
  → StoreClient.getObjects([new MatrikkelenhetId()])
  → Local DB query for bruksenheter
  ↓
Result: FoundAddress DTO with all data
```

### 2. **FoundAddress** (`src/Service/FoundAddress.php`)

Data transfer object containing all address lookup results:

```php
class FoundAddress
{
    public readonly int $vegadresseId,
    public readonly string $gatenavn,
    public readonly int $husnummer,
    public readonly ?string $bokstav,
    public readonly int $adressekode,
    public readonly int $vegId,
    public readonly object $vegadresseObject,
    public readonly ?object $matrikkelObject,      // Nullable
    public readonly array $bruksenhetObjects       // May be empty
}
```

### 3. **SupplementByAddressCommand** (`src/Console/SupplementByAddressCommand.php`)

Console command for interactive address lookup and supplementation.

## Usage

### Single Address Lookup (Interactive)

```bash
php bin/console matrikkel:supplement-by-address \
  --kommune=4627 \
  --gatenavn="Storgata" \
  --husnummer=42 \
  --bokstav=A
```

**Output Example:**
```
Supplement Address Data by Street Components
==============================================

Søker etter: Storgata 42 A i kommune 4627

// If multiple streets named "Storgata" exist:
Found 2 gater with name "Storgata"

┌─┬────────┬─────────────┬─────────────┬─────────────┐
│ # │ Veg ID │ Adressekode │ Kortnavn    │ Stedsnummer │
├─┼────────┼─────────────┼─────────────┼─────────────┤
│ 1 │ 54321  │ 1234        │ Stor. (N)   │ ─           │
│ 2 │ 54322  │ 1235        │ Stor. (S)   │ ─           │
└─┴────────┴─────────────┴─────────────┴─────────────┘

Velg gate (nummer 1-2, eller 'q' for å avbryte): 1

✓ FOUND: Storgata 42 A (Veg ID: 54321, Adressekode: 1234)

Vegadresse details:
==================
Vegadresse ID:      12345678
Type:              VEGADRESSE
Kortnavn:          Stor.
Tilleggsnavn:      —

Linked Matrikkelenhet:
======================
Matrikkelenhet ID:  987654321
Matrikkelnummer:    4627/12/345
Areal:              2500 m²

Linked Bruksenheter: 3 unit(s)
  - ID: 111, Type: BOLIG, Etasje: 1
  - ID: 112, Type: BOLIG, Etasje: 2
  - ID: 113, Type: BOLIG, Etasje: 3
```

### Batch Lookup from CSV

```bash
php bin/console matrikkel:supplement-by-address \
  --file=addresses.csv
```

**CSV Format** (`addresses.csv`):
```csv
kommunenr,gatenavn,husnummer,bokstav
4627,Storgata,42,A
4627,Osloveien,100,
4601,Åsane Alle,15,B
```

**Output:**
```
Supplement Address Data by Street Components
==============================================

Line 1: Looking up Storgata 42 A (kommune 4627)
  ✓ Found: Veg ID 54321
Line 2: Looking up Osloveien 100 (kommune 4627)
  ✓ Found: Veg ID 54323
Line 3: Looking up Åsane Alle 15 B (kommune 4601)
  ✗ Not found

Results: 2 of 3 addresses found
```

### Programmatic Use (No User Prompts)

```php
// Inject service
private AddressLookupService $addressLookupService;

// Lookup without prompts (uses first match if multiple streets)
$found = $this->addressLookupService->findByStreetAddress(
    gatenavn: "Storgata",
    husnummer: 42,
    bokstav: "A",
    kommunenummer: 4627,
    io: null,  // No prompts
    fetchRelated: true
);

if ($found) {
    echo "Found: " . $found->gatenavn . " " . $found->husnummer;
    echo "Veg ID: " . $found->vegId;
    echo "Matrikkelenhet: " . $found->matrikkelObject?->matrikkelnummerTekst;
    
    foreach ($found->bruksenhetObjects as $unit) {
        echo "Unit: " . $unit->id;
    }
}
```

## Street Name Disambiguation

When `findVegerMedNavn()` returns multiple streets with the same name:

1. **Interactive Mode** (console with `$io` parameter):
   - Shows numbered table with columns: Veg ID, Adressekode, Kortnavn, Stedsnummer
   - User selects by number or types 'q' to cancel
   - Command continues with selected street

2. **Batch Mode** (CSV file or `$io=null`):
   - Automatically uses first match
   - Logs result but doesn't block
   - Continues processing next address

3. **Programmatic Mode** (without `$io`):
   - Returns first match silently
   - No user interaction

## Error Handling

Service catches and handles:
- ✅ Street not found → Returns null
- ✅ Multiple matches → Prompts user (if `$io` provided)
- ✅ Address number not found → Continues to next veg in list
- ✅ SOAP API errors → Returns null with optional warning
- ✅ Missing related objects → Returns partial data (matrikkelenhet/bruksenheter may be null/empty)

## Technical Architecture

### SOAP API Methods Used

| Method | Client | Purpose |
|--------|--------|---------|
| `findVegerMedNavn()` | AdresseClient | Find veg(er) by street name + kommune |
| `findVegadresse()` | AdresseClient | Find specific street address by adressekode + husnummer + bokstav |
| `getObjects()` | StoreClient | Batch fetch full objects by ID |

### Database Queries

- Local `matrikkel_bruksenheter` table for related units (avoids additional SOAP calls)

### ID Types

- `AdresseId` - Used for all addresses (vegadresse and matrikkeladresse)
- `MatrikkelenhetId` - Cadastral units
- `BruksenhetId` - Dwelling units

## Configuration

Service is auto-registered in `config/services.yaml`:

```yaml
Iaasen\Matrikkel\Service\AddressLookupService:
    arguments:
        - '@Iaasen\Matrikkel\Client\AdresseClient'
        - '@Iaasen\Matrikkel\Client\StoreClient'
        - '@database_connection'
```

## Edge Cases Handled

| Case | Behavior |
|------|----------|
| Multiple streets with same name | User prompt (if `$io`) or first match (silent) |
| House number doesn't exist on street | Returns null with warning |
| Address without house letter | Works fine (bokstav can be null) |
| Address without matrikkelenhet link | Returns address only, matrikkelenhet=null |
| Address with no bruksenheter | Returns empty bruksenheter array |
| SOAP API timeout/error | Returns null gracefully |
| CSV with missing columns | Skips line with warning |

## Next Steps

To fully integrate address supplementation into import workflow:

1. **Extend console command** to write discovered data to local database
   - INSERT into `matrikkel_adresser` and `matrikkel_vegadresser`
   - Create M:N relationships in `matrikkel_matrikkelenhet_adresse`

2. **Add data validation** before writing to DB
   - Check for duplicates
   - Verify foreign key constraints

3. **Add batch import mode**
   - Accept CSV/JSON of missing addresses
   - Generate import log with statistics

4. **Optimize performance**
   - Cache veg objects locally per kommune
   - Batch SOAP calls for multiple addresses

## Files Created/Modified

- ✅ Created: `src/Service/AddressLookupService.php` (316 lines)
- ✅ Created: `src/Service/FoundAddress.php` (24 lines)
- ✅ Created: `src/Console/SupplementByAddressCommand.php` (293 lines)
- ⚠️ TODO: Register command in `config/services.yaml` (if not auto-discovered)

## Testing

Syntax validation complete:
```bash
php -l src/Service/AddressLookupService.php      # ✓ No errors
php -l src/Service/FoundAddress.php              # ✓ No errors
php -l src/Console/SupplementByAddressCommand.php # ✓ No errors
```

Ready for integration testing!
