<?php
/**
 * Console command for importing matrikkelenheter from Matrikkel API
 * 
 * Kommando: php bin/console matrikkel:matrikkelenhet-import
 * 
 * Options:
 *   --kommune=X    Import only for specific kommune (e.g. --kommune=301)
 *   --batch-size=N Batch size for API requests (default: 1000)
 * 
 * @author Sigurd Nes
 * @date 2025-10-08
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\MatrikkelenhetImportService;
use Iaasen\Matrikkel\Service\EierImportSingleModeService;
use Iaasen\Matrikkel\Client\StoreClient;
use Laminas\Db\Adapter\Adapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:matrikkelenhet-import',
    description: 'Import matrikkelenheter med eierforhold fra Matrikkel API'
)]
class MatrikkelenhetImportCommand extends Command
{
    public function __construct(
        private MatrikkelenhetImportService $matrikkelenhetImportService,
        private StoreClient $storeClient,
        private Adapter $dbAdapter
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption(
                'kommune',
                'k',
                InputOption::VALUE_OPTIONAL,
                'Spesifikt kommunenummer å importere (f.eks. 301 for Oslo)',
                null
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Batch-størrelse for API-forespørsler',
                1000
            )
            ->addOption(
                'fetch-eiere',
                null,
                InputOption::VALUE_NONE,
                'Hent eiere (personer/juridiske personer) automatisk etter matrikkelenhet-import'
            )
            ->setHelp(<<<'HELP'
Denne kommandoen importerer matrikkelenheter fra Matrikkel API med eierforhold-informasjon.

Bruk:
  # Import matrikkelenheter for en spesifikk kommune (f.eks. Oslo)
  php bin/console matrikkel:matrikkelenhet-import --kommune=301

  # Import med automatisk henting av eiere
  php bin/console matrikkel:matrikkelenhet-import --kommune=301 --fetch-eiere

  # Import for alle kommuner (ADVARSEL: Tar veldig lang tid!)
  php bin/console matrikkel:matrikkelenhet-import

  # Spesifiser batch-størrelse
  php bin/console matrikkel:matrikkelenhet-import --kommune=301 --batch-size=500

Datastruktur:
  - matrikkelenhet_id: Unik ID fra Matrikkel
  - kommunenummer, gardsnummer, bruksnummer, festenummer, seksjonsnummer
  - eier_type, eier_person_id, eier_juridisk_person_id: Eierforhold (første tinglyst eier)
  - areal, tinglyst, skyld, bruksnavn
  - Status-flagg: er_seksjonert, har_aktive_festegrunner, utgatt, etc.

Normalisert eier-struktur:
  - matrikkel_matrikkelenheter lagrer kun foreign keys til eiere
  - matrikkel_personer: Fysiske personer (FysiskPerson)
  - matrikkel_juridiske_personer: Juridiske personer (JuridiskPerson)
  - Bruk --fetch-eiere for automatisk on-demand henting av eierdetaljer

Eier-import (single-object mode):
  - Bruker StoreClient.getObject() i loop (én og én eier)
  - Auto-klassifisering av Person vs JuridiskPerson basert på respons
  - Progress bar viser fremgang under import
  - Feilhåndtering: Fortsetter ved feil, teller feilede objekter
  - Langsommere enn batch mode, men mer stabilt

Merk: 
  - Eierforhold ekstraheres fra første tinglyst eier i eierforhold-listen
  - Full eierdata hentes kun ved behov (--fetch-eiere) via StoreService
  - Single-object mode brukes pga StoreService.getObjects() type-spesifikasjon issue
  - For detaljer se: doc/EIER_IMPORT_SINGLE_MODE.md
HELP
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $kommune = $input->getOption('kommune');
        $batchSize = (int)$input->getOption('batch-size');
        
        $io->title('Import av matrikkelenheter fra Matrikkel API');
        
        $startTime = microtime(true);
        
        // Import based on kommune option
        if ($kommune) {
            // Single kommune
            $kommunenummer = (int)$kommune;
            
            $io->section("Importerer matrikkelenheter for kommune $kommunenummer");
            
            $count = $this->matrikkelenhetImportService->importMatrikkelenheterForKommune(
                $io,
                $kommunenummer,
                $batchSize
            );
            
            $totalCount = $count;
            
        } else {
            // All kommuner
            $io->warning([
                'Ingen spesifikk kommune spesifisert.',
                'Dette vil importere matrikkelenheter for ALLE kommuner i Norge.',
                'Dette tar veldig lang tid og bør kjøres i bakgrunnen!',
            ]);
            
            if (!$io->confirm('Vil du fortsette?', false)) {
                $io->note('Import avbrutt av bruker');
                return Command::SUCCESS;
            }
            
            // Fetch all kommunenumre from database
            $kommuneNumre = $this->getAllKommuneNumre();
            
            $io->text("Fant " . count($kommuneNumre) . " kommuner i databasen");
            
            $stats = $this->matrikkelenhetImportService->importMatrikkelenheterForAlleKommuner(
                $io,
                $kommuneNumre,
                $batchSize
            );
            
            $totalCount = $stats['total'];
            
            // Display per-kommune statistics
            $io->section('Statistikk per kommune');
            $io->table(
                ['Kommune', 'Antall matrikkelenheter'],
                array_map(
                    fn($knr, $count) => [$knr, $count],
                    array_keys($stats['per_kommune']),
                    array_values($stats['per_kommune'])
                )
            );
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Verify import
        $dbCount = $this->matrikkelenhetImportService->verifyImport();
        
        // Display statistics
        $io->section('Statistikk');
        $io->table(
            ['Metric', 'Verdi'],
            [
                ['Totalt antall matrikkelenheter', $totalCount],
                ['Batch-størrelse', $batchSize],
                ['Tid brukt', number_format($duration, 2) . ' sekunder'],
                ['Gjennomsnitt', $duration > 0 ? number_format($totalCount / $duration, 2) . ' matrikkelenheter/sek' : 'N/A'],
            ]
        );
        
        $io->note("Verifisert: $dbCount matrikkelenheter finnes nå i databasen.");
        
        // Fetch eiere if requested
        $fetchEiere = $input->getOption('fetch-eiere');
        if ($fetchEiere) {
            $io->newLine();
            // EierImportSingleModeService: Single-object mode (StoreClient.getObject() loop)
            // Årsak: StoreService.getObjects() feiler pga manglende type-spesifikasjon
            $eierImportService = new EierImportSingleModeService($this->storeClient, $this->dbAdapter);
            
            // Build kommune list for eier fetch
            $kommuneList = null;
            if ($kommune) {
                $kommuneList = [(int)$kommune];
            } elseif (isset($kommuneNumre)) {
                $kommuneList = $kommuneNumre;
            }
            
            // Flush interval for database operations (default 100)
            $eierStats = $eierImportService->importEiereForKommuner($kommuneList, $io, 100);
            
            $io->table(
                ['Eier-type', 'Antall'],
                [
                    ['Fysiske personer', $eierStats['personer']],
                    ['Juridiske personer', $eierStats['juridiske_personer']],
                    ['Feilet', $eierStats['feilet']],
                    ['Totalt', $eierStats['totalt']],
                ]
            );
        } else {
            $io->note('Tips: Bruk --fetch-eiere for å hente eierinformasjon automatisk');
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Fetch all kommunenumre from database
     * 
     * @return array Array of kommunenummer integers
     */
    private function getAllKommuneNumre(): array
    {
        $sql = 'SELECT kommunenummer FROM matrikkel_kommuner ORDER BY kommunenummer';
        $result = $this->dbAdapter->query($sql)->execute();
        
        $kommuneNumre = [];
        foreach ($result as $row) {
            $kommuneNumre[] = (int)$row['kommunenummer'];
        }
        
        return $kommuneNumre;
    }
}
