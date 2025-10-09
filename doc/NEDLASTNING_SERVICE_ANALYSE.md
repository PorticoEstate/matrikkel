# NedlastningServiceWS - Analyse og Anbefaling

**Dato**: 7. oktober 2025  
**Status**: ⭐ ANBEFALT for bulk-import av Matrikkel-data

---

## 📋 Oppsummering

NedlastningServiceWS er Kartverkets **dedikerte tjeneste for bulk-nedlasting** av Matrikkel-data. Denne tjenesten er designet spesifikt for å hente store datamengder effektivt og er **den anbefalte løsningen** når du skal importere komplette datasett per kommune.

---

## 🔍 Teknisk Beskrivelse

### WSDL Lokasjon
- **Fil**: `doc/wsdl/NedlastningServiceWS.wsdl`
- **Schema**: `doc/wsdl/NedlastningServiceWS_schema1.xsd`
- **Namespace**: `http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/service/nedlastning`

### Tilgjengelige Metoder

#### 1. `findIdsEtterId`
Henter **kun ID-er** for objekter (rask, minimal datamengde).

**Input:**
```xml
<findIdsEtterId>
    <matrikkelBubbleId>
        <value>0</value> <!-- Start-ID, bruk 0 for første batch -->
    </matrikkelBubbleId>
    <domainklasse>Matrikkelenhet</domainklasse>
    <filter>kommunenummer=0301</filter> <!-- Valgfritt -->
    <maksAntall>1000</maksAntall>
    <matrikkelContext>
        <!-- Autentisering -->
    </matrikkelContext>
</findIdsEtterId>
```

**Output:**
```xml
<findIdsEtterIdResponse>
    <return>
        <item><value>123456</value></item>
        <item><value>123457</value></item>
        <!-- ... opptil maksAntall items -->
    </return>
</findIdsEtterIdResponse>
```

**Bruksområde**: 
- Finn alle ID-er for objekter i en kommune
- Sjekk om nye objekter er lagt til siden sist
- Sammenlign lokalt datasett med Matrikkel


#### 2. `findObjekterEtterId`
Henter **komplette objekter** med all data.

**Input:** (samme som `findIdsEtterId`)

**Output:**
```xml
<findObjekterEtterIdResponse>
    <return>
        <item xsi:type="Matrikkelenhet">
            <id><value>123456</value></id>
            <matrikkelnummer>
                <kommunenummer>0301</kommunenummer>
                <gardsnummer>100</gardsnummer>
                <bruksnummer>5</bruksnummer>
                <!-- ... all data for matrikkelenhet -->
            </matrikkelnummer>
            <eierforhold>
                <!-- ... eierforhold-liste -->
            </eierforhold>
        </item>
        <!-- ... opptil maksAntall items -->
    </return>
</findObjekterEtterIdResponse>
```

**Bruksområde**: 
- Bulk-import av komplette objekter
- Initial synkronisering av database
- **ANBEFALT for ditt brukstilfelle**

---

## 📊 Støttede Objekttyper (Domainklasse)

Tjenesten støtter **70+ objekttyper** via `Domainklasse` enum. De mest relevante for deg:

### ⭐ Primære objekter (dine 7 tabeller)

| Domainklasse | Beskrivelse | Relevant for |
|--------------|-------------|--------------|
| `Kommune` | Norske kommuner | `matrikkel_kommuner` |
| `Matrikkelenhet` | Alle matrikkelenheter | `matrikkel_matrikkelenheter` |
| `Grunneiendom` | Grunneiendommer (subtype av Matrikkelenhet) | `matrikkel_matrikkelenheter` |
| `Festegrunn` | Festegrunner (subtype av Matrikkelenhet) | `matrikkel_matrikkelenheter` |
| `Seksjon` | Seksjoner (subtype av Matrikkelenhet) | `matrikkel_matrikkelenheter` |
| `Bygg` | Bygninger (generisk) | `matrikkel_bygninger` |
| `Bygning` | Bygninger (spesifikk type) | `matrikkel_bygninger` |
| `Bruksenhet` | Bruksenheter/leiligheter | `matrikkel_bruksenheter` |
| `Adresse` | Adresser (generisk) | `matrikkel_adresser` |
| `Vegadresse` | Vegadresser | `matrikkel_adresser` |
| `Matrikkeladresse` | Matrikkeladresser | `matrikkel_adresser` |
| `Veg` | Gater/veier | `matrikkel_gater` |

### 📦 Andre relevante objekter

| Domainklasse | Beskrivelse |
|--------------|-------------|
| `Teig` | Teiger (geometri for matrikkelenhet) |
| `Teiggrense` | Grenser mellom teiger |
| `Kulturminne` | Kulturminner |
| `Forretning` | Oppmålingsforretninger |
| `Krets` | Administrative kretser |
| `Postnummeromrade` | Postnummerområder |

**TOTALT**: 70+ objekttyper tilgjengelig!

---

## 🎯 Paginering med Cursor-basert ID

NedlastningServiceWS bruker **cursor-basert paginering** via `matrikkelBubbleId`:

### Hvordan det fungerer:

1. **Første kall**: Sett `matrikkelBubbleId = 0`
2. **Motta objekter**: Få inntil `maksAntall` objekter
3. **Hent siste ID**: Ta `id.value` fra siste objekt i listen
4. **Neste kall**: Bruk siste ID som ny `matrikkelBubbleId`
5. **Gjenta**: Fortsett til tom liste returneres (færre enn `maksAntall`)

### Fordeler:
✅ **Ingen offset-problemer** (ineffektiv ved store datasett)  
✅ **Konsistent state** selv om nye objekter legges til under nedlasting  
✅ **Kan gjenoppta** hvis prosessen feiler (lagre siste ID)  
✅ **Effektiv** - database trenger kun indeks-søk på ID

### Eksempelkode (pseudokode):

```php
$lastId = 0;
$batchSize = 1000;
$allObjects = [];

do {
    $batch = $nedlastningClient->findObjekterEtterId(
        $lastId,
        'Matrikkelenhet',
        'kommunenummer=0301',
        $batchSize,
        $context
    );
    
    foreach ($batch as $object) {
        $allObjects[] = $object;
        $lastId = $object->getId()->getValue();
    }
    
    echo "Hentet " . count($batch) . " objekter, siste ID: $lastId\n";
    
} while (count($batch) === $batchSize);

echo "Totalt hentet: " . count($allObjects) . " objekter\n";
```

---

## 🔍 Filter-parameter

NedlastningServiceWS støtter en **`filter` string-parameter** for å begrense resultatet.

### ⚠️ Ukjent syntaks - må testes!

Filter-parameteren er dokumentert som `xs:string`, men syntaksen er ikke detaljert i WSDL/XSD.

### Mulige syntakser å teste:

```
# Enkelt equality filter
"kommunenummer=0301"

# SQL-lignende WHERE-clause
"kommunenummer = '0301'"

# Compound filter
"kommunenummer=0301 AND tinglyst=true"

# JSON-lignende filter (usannsynlig)
{"kommunenummer": "0301"}
```

### 📝 Testing anbefalt:

1. Test med `null` / tom string (ingen filter)
2. Test med `"kommunenummer=0301"`
3. Test med `"kommunenummer='0301'"`
4. Sjekk feilmeldinger for å lære syntaks
5. Dokumenter funksjonell syntaks i koden

### Alternative strategi (hvis filter ikke fungerer):

Hvis filter ikke støttes eller syntaksen er vanskelig:
1. Last ned **alle** objekter av en type (uten filter)
2. Filtrer **lokalt** på kommune etter henting
3. Dette er fortsatt raskere enn mange små SOAP-kall!

---

## ⚠️ Eier-filtrering

**VIKTIG**: NedlastningServiceWS støtter **IKKE** filtrering på eier direkte.

### Anbefalt strategi:

1. **Last ned per kommune** (via filter hvis det fungerer)
2. **Lagre alle objekter** lokalt i PostgreSQL
3. **Filtrer på eier lokalt** via SQL:
   ```sql
   SELECT m.* 
   FROM matrikkel_matrikkelenheter m
   WHERE m.kommunenummer = '0301'
     AND m.eier_id = 12345
     AND m.eier_type = 'Person';
   ```
4. **JOIN til andre tabeller** for relatert data:
   ```sql
   SELECT a.*, m.eier_navn
   FROM matrikkel_adresser a
   JOIN matrikkel_matrikkelenheter m ON a.matrikkelenhet_id = m.matrikkelenhet_id
   WHERE m.eier_id = 12345;
   ```

### Hvorfor denne strategien?

- ✅ **Matrikkel API har ikke eier-filter** i noen tjeneste
- ✅ **PostgreSQL er ekstremt rask** med riktige indexes
- ✅ **Lokale data = ingen API rate limits**
- ✅ **Komplekse queries** er enkle i SQL
- ✅ **Data caching** reduserer API-belastning

---

## 💡 Anbefalt Implementasjonsstrategi

### Fase 1: Implementer NedlastningClient

```php
<?php
namespace Iaasen\Matrikkel\Client;

class NedlastningClient extends AbstractSoapClient
{
    protected function getWsdlUrl(): string
    {
        return 'https://wsfelles.matrikkel.no/matrikkelapi/wsapi/v1/NedlastningServiceWS?wsdl';
    }
    
    protected function getTestWsdlUrl(): string
    {
        return 'https://wsfelles-test.matrikkel.no/matrikkelapi/wsapi/v1/NedlastningServiceWS?wsdl';
    }
    
    public function findIdsEtterId(
        int $matrikkelBubbleId,
        string $domainklasse,
        ?string $filter,
        int $maksAntall,
        array $matrikkelContext
    ): array {
        return $this->call('findIdsEtterId', [
            'matrikkelBubbleId' => ['value' => $matrikkelBubbleId],
            'domainklasse' => $domainklasse,
            'filter' => $filter,
            'maksAntall' => $maksAntall,
            'matrikkelContext' => $matrikkelContext
        ]);
    }
    
    public function findObjekterEtterId(
        int $matrikkelBubbleId,
        string $domainklasse,
        ?string $filter,
        int $maksAntall,
        array $matrikkelContext
    ): array {
        return $this->call('findObjekterEtterId', [
            'matrikkelBubbleId' => ['value' => $matrikkelBubbleId],
            'domainklasse' => $domainklasse,
            'filter' => $filter,
            'maksAntall' => $maksAntall,
            'matrikkelContext' => $matrikkelContext
        ]);
    }
}
```

### Fase 2: Lag NedlastningImportService

```php
<?php
namespace Iaasen\Matrikkel\Service;

class NedlastningImportService
{
    public function __construct(
        private NedlastningClient $client,
        private MatrikkelenhetTable $matrikkelenhetTable,
        private BygningTable $bygningTable,
        // ...
    ) {}
    
    public function importMatrikkelenheterForKommune(string $kommunenummer): int
    {
        $lastId = 0;
        $batchSize = 1000;
        $totalCount = 0;
        
        do {
            $batch = $this->client->findObjekterEtterId(
                $lastId,
                'Matrikkelenhet',
                "kommunenummer=$kommunenummer", // Test syntaks
                $batchSize,
                $this->getMatrikkelContext()
            );
            
            foreach ($batch as $matrikkelenhet) {
                $this->matrikkelenhetTable->insertRow($matrikkelenhet);
                $lastId = $matrikkelenhet->getId()->getValue();
                $totalCount++;
            }
            
            $this->matrikkelenhetTable->flush();
            
            echo "Hentet $totalCount matrikkelenheter...\n";
            
        } while (count($batch) === $batchSize);
        
        return $totalCount;
    }
    
    public function importBygningerForKommune(string $kommunenummer): int
    {
        // Samme pattern for Bygning
    }
}
```

### Fase 3: Console Command

```php
<?php
namespace Iaasen\Matrikkel\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class NedlastningImportCommand extends Command
{
    protected static $defaultName = 'matrikkel:nedlastning-import';
    
    public function __construct(
        private NedlastningImportService $importService
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Bulk-import av Matrikkel-data via NedlastningServiceWS')
            ->addArgument('kommune', InputArgument::REQUIRED, 'Kommunenummer (f.eks. 0301)')
            ->addArgument('type', InputArgument::REQUIRED, 'Objekttype (Matrikkelenhet, Bygning, etc.)');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kommune = $input->getArgument('kommune');
        $type = $input->getArgument('type');
        
        $io->title("Bulk-import av $type for kommune $kommune");
        
        $count = match($type) {
            'Matrikkelenhet' => $this->importService->importMatrikkelenheterForKommune($kommune),
            'Bygning' => $this->importService->importBygningerForKommune($kommune),
            default => throw new \InvalidArgumentException("Ukjent type: $type")
        };
        
        $io->success("Importert $count objekter av type $type");
        
        return Command::SUCCESS;
    }
}
```

### Fase 4: Bruk

```bash
# Import alle matrikkelenheter for Oslo
php bin/console matrikkel:nedlastning-import 0301 Matrikkelenhet

# Import alle bygninger for Oslo
php bin/console matrikkel:nedlastning-import 0301 Bygning

# Filtrer på eier lokalt i SQL
psql -d matrikkel -c "SELECT * FROM matrikkel_matrikkelenheter WHERE eier_id = 12345"
```

---

## ✅ Fordeler med NedlastningServiceWS

| Fordel | Beskrivelse |
|--------|-------------|
| 🚀 **Effektiv paginering** | Cursor-basert med ID, ikke offset |
| 📦 **Bulk-nedlasting** | Designet for store datamengder |
| 🔄 **Kan gjenoppta** | Lagre siste ID og fortsett ved feil |
| 🎯 **Én tjeneste** | Alle objekttyper via én SOAP service |
| 🔍 **Filter-støtte** | Kan filtrere på kommune (syntaks må testes) |
| ⚡ **Rask** | Færre SOAP-kall, større batches |
| 🛡️ **Robust** | Håndterer nye objekter under nedlasting |

---

## ⚠️ Utfordringer og løsninger

| Utfordring | Løsning |
|------------|---------|
| **Filter-syntaks ukjent** | Test ulike syntakser, bruk lokal filtrering som fallback |
| **Ingen eier-filter** | Last ned per kommune, filtrer lokalt på eier i PostgreSQL |
| **Stor datamengde** | Bruk batchSize=1000, lagre underveis, bruk progressbar |
| **SOAP timeout** | Øk timeout i SOAP-client konfigurasjon |
| **Feil under import** | Lagre siste ID, implementer retry-logikk |

---

## 📈 Sammenligning med andre metoder

### NedlastningServiceWS vs. Objektspesifikke Services

| Aspekt | NedlastningServiceWS | BygningServiceWS, MatrikkelenhetServiceWS, etc. |
|--------|----------------------|------------------------------------------------|
| **Antall services** | 1 service for alt | Mange ulike services |
| **Paginering** | Cursor-basert (ID) | Offset-basert (treg) eller ingen |
| **Batch-størrelse** | Konfigurerbar (1000+) | Ofte begrenset (100-200) |
| **API-kall** | Færre kall | Mange små kall |
| **Kompleksitet** | Enkel - én metode | Høy - mange metoder å lære |
| **Ytelse** | ⭐⭐⭐⭐⭐ Utmerket | ⭐⭐⭐ God |
| **Feil-håndtering** | Enkel - fortsett fra ID | Kompleks - håndter offset |

**Anbefaling**: Bruk NedlastningServiceWS for all bulk-import! 🎯

---

## 🎓 Konklusjon

**NedlastningServiceWS er den optimale løsningen** for ditt brukstilfelle:

✅ Last ned komplette datasett per kommune  
✅ Effektiv cursor-basert paginering  
✅ Støtter alle 7 tabeller dine via ulike Domainklasse-verdier  
✅ Kan potensielt filtrere på kommune (må testes)  
✅ Enklere implementasjon enn mange objektspesifikke services  

**Strategi:**
1. Implementer `NedlastningClient.php`
2. Lag `NedlastningImportService` for bulk-import per kommune
3. Last ned alle objekter for en kommune
4. **Filtrer på eier lokalt** i PostgreSQL etter import
5. Bruk indexes på `matrikkel_matrikkelenheter.eier_id` for rask filtrering

**Resultat:**
- Rask bulk-import av hele kommuner
- Fleksibel lokal filtrering på eier, tinglyst status, etc.
- Minimal API-belastning
- Skalerbar løsning for flere kommuner

---

**Neste steg**: Oppdater `IMPLEMENTATION_PLAN.md` Trinn 3 med NedlastningClient som primær løsning! ✅
