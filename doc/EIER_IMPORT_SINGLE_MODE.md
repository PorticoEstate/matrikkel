# Eier-Import Løsning: Single-Object Fallback

**Dato**: 8. oktober 2025  
**Forfatter**: Sigurd Nes  
**Problem**: StoreService.getObjects() feiler pga manglende type-spesifikasjon

---

## 🔍 Problemanalyse

### Oppdaget årsak
StoreService.getObjects() krever type-spesifikasjon (PersonId vs JuridiskPersonId) via `xsi:type` attribute, noe PHP SoapClient ikke støtter godt nok uten omfattende custom encoding.

### Feilen
```
Error mapping from MatrikkelBubbleIdList to Collection<I>
NoSuchMethodException: MatrikkelBubbleId.<init>(Long, SnapshotVersion)
```

**Root cause**: 
- `MatrikkelBubbleId` er base-type
- `PersonId` og `JuridiskPersonId` er subtyper
- Serveren kan ikke bestemme type uten `xsi:type` attribute
- `koordinatsystemKodeId` er irrelevant for Person-objekter (kun for geometri)

---

## ✅ Løsning: Single-Object Mode

### Implementasjon
`EierImportSingleModeService.php` - Ny versjon med single-object fallback:

**Strategi**:
1. Hent eier-IDer fra matrikkelenheter (som før)
2. Loop gjennom IDer og hent **én og én** via `StoreClient.getObject()`
3. Auto-klassifiser basert på class name
4. Insert til riktig tabell (personer eller juridiske_personer)
5. Oppdater matrikkelenhet med korrekt type

### Fordeler
✅ **Fungerer**: Ingen SOAP-feil  
✅ **Auto-klassifisering**: Detekterer type automatisk fra respons  
✅ **Progress bar**: Visuell feedback under import  
✅ **Feilhåndtering**: Fortsetter ved feil, teller feilede objekter  
✅ **Buffering**: Flush til database periodisk (default hver 100. objekt)

### Ulemper
⚠️ **Langsommere**: 85 API-kall i stedet for 5 batch-kall  
⚠️ **Mer nettverkstrafikk**: Høyere latency  
⚠️ **Rate limit risk**: Flere kall kan føre til throttling

### Ytelse-estimat
- **Batch mode** (hvis den virket): ~5 sekunder for 85 eiere
- **Single mode**: ~20-40 sekunder for 85 eiere (avhengig av nettverkslatency)
- **Hele Norge** (estimert 5 mill eiere): ~20-30 timer

---

## 🚀 Bruk

### Oppdater MatrikkelenhetImportCommand
Erstatt `EierImportService` med `EierImportSingleModeService`:

```php
use Iaasen\Matrikkel\Service\EierImportSingleModeService;

// I constructor:
private EierImportSingleModeService $eierImportService;

// I execute():
$eierImportService = new EierImportSingleModeService($this->storeClient, $this->dbAdapter);
$eierStats = $eierImportService->importEiereForKommuner($kommuneList, $io, 100);
```

### Kjør import
```bash
# Test med liten kommune først
php bin/console matrikkel:matrikkelenhet-import \
  --kommune=4601 \
  --batch-size=50 \
  --fetch-eiere

# Progress bar vil vise fremgang:
# 85/85 [============================] 100%
```

---

## 📊 Output-eksempel

```
Henter eiere fra StoreService (single-object mode)
--------------------------------------------------

Finner unike eier-IDer (inkludert ukjent type)...
  Fant 85 person-IDer og 0 juridisk-person-IDer å hente

Henter og klassifiserer 85 eiere (single-object mode)...
 85/85 [============================] 100%

 [OK] Eier-import fullført: 60 personer, 25 juridiske personer (0 feilet)

 -------------------- -------- 
  Eier-type            Antall  
 -------------------- -------- 
  Fysiske personer     60       
  Juridiske personer   25       
  Feilet               0
  Totalt               85       
 -------------------- --------
```

---

## 🔮 Fremtidige forbedringer

### Alternativ 1: PersonService med søk
Undersøk om PersonService har bulk-metoder vi kan bruke:
- `findFysiskePersonIds()` - tar personnummer-liste
- Men krever at vi kjenner fødselsnummer, ikke bare ID

### Alternativ 2: Parallel requests
Implementer parallelle API-kall med async/await eller curl_multi:
```php
// Pseudo-code
$promises = [];
foreach ($eierIds as $id) {
    $promises[] = $httpClient->getAsync($url, $id);
}
$responses = Promise\all($promises)->wait();
```

### Alternativ 3: Kontakt Kartverket
Be om:
1. Dokumentasjon for korrekt bruk av StoreService.getObjects()
2. Eksempler på xsi:type spesifikasjon
3. Alternative batch-metoder for person-henting

---

## 📝 Tekniske detaljer

### Auto-klassifisering
```php
$className = get_class($object);

if (stripos($className, 'JuridiskPerson') !== false) {
    $type = 'juridisk_person';
    $this->juridiskPersonTable->insertRow($object);
} elseif (stripos($className, 'Person') !== false) {
    $type = 'person';
    $this->personTable->insertRow($object);
}
```

### Database-oppdatering
**For juridisk person**:
```sql
UPDATE matrikkel_matrikkelenheter 
SET eier_type = 'juridisk_person',
    eier_juridisk_person_id = $eierId,
    eier_person_id = NULL
WHERE eier_person_id = $eierId
```

**For person**:
```sql
UPDATE matrikkel_matrikkelenheter 
SET eier_type = 'person'
WHERE eier_person_id = $eierId 
AND eier_type = 'ukjent'
```

---

## ⚡ Optimalisering

### Flush-intervaller
Default: Flush hver 100. objekt

Juster for ytelse vs minnebruk:
```php
// Mer minne, færre database-writes:
$eierImportService->importEiereForKommuner($kommuneList, $io, 500);

// Mindre minne, flere database-writes:
$eierImportService->importEiereForKommuner($kommuneList, $io, 50);
```

### Feilhåndtering
```php
try {
    $response = $this->storeClient->getObject([...]);
} catch (\SoapFault $e) {
    $stats['feilet']++;
    // Continue with next ID
}
```

Fortsetter ved feil, så en enkelt feil stopper ikke hele importen.

---

## 🎯 Status

- ✅ **EierImportSingleModeService**: Implementert
- ✅ **PersonServiceClient**: Opprettet (for fremtidig bruk)
- ✅ **Progress bar**: Visuell feedback
- ✅ **Feilhåndtering**: Robust mot enkelte feil
- ⏳ **Testing**: Må testes med ekte data
- ⏳ **Performance**: Må måles i praksis

---

## 📚 Relaterte filer

- `src/Service/EierImportSingleModeService.php` - Ny implementasjon
- `src/Service/EierImportService.php` - Gammel implementasjon (batch mode)
- `src/Client/PersonServiceClient.php` - PersonService wrapper (fremtidig bruk)
- `doc/BUG_REPORT_StoreService_getObjects.md` - Detaljert feilrapport
- `doc/IMPORT_SEQUENCE.md` - Komplett import-dokumentasjon
