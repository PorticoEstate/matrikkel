<?php
/**
 * Phase 2: Filtered Import Command
 * 
 * Imports filtered data based on ownership:
 * 1. Veger (bulk download - entire kommune)
 * 2. Bruksenheter (API-filtered by matrikkelenheter)
 * 3. Bygninger (bulk download with client-side filter)
 * 4. Adresser (API-filtered by matrikkelenheter)
 * 
 * MUST run Phase 1 first to have matrikkelenheter and personer!
 * 
 * Usage:
 *   # Import all for kommune
 *   php bin/console matrikkel:phase2-import --kommune=4601
 *   
 *   # Filter by organisasjonsnummer
 *   php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=922530890
 *   
 *   # Filter by personnummer  
 *   php bin/console matrikkel:phase2-import --kommune=4601 --personnummer=12345678901
 * 
 * @author Matrikkel Integration System
 * @date 2025-10-22
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\MatrikkelenhetFilterService;
use Iaasen\Matrikkel\Service\BruksenhetImportService;
use Iaasen\Matrikkel\Service\BygningImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:phase2-import',
    description: 'Phase 2: Filtered import (veger + bygninger + bruksenheter + adresser)'
)]
class Phase2ImportCommand extends Command
{
    public function __construct(
        private MatrikkelenhetFilterService $matrikkelenhetFilterService,
        private BruksenhetImportService $bruksenhetImportService,
        private BygningImportService $bygningImportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'kommune',
                'k',
                InputOption::VALUE_REQUIRED,
                'Kommune number (4 digits, e.g. 4601 for Bergen)'
            )
            ->addOption(
                'personnummer',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Filter by personnummer (11 digits)'
            )
            ->addOption(
                'organisasjonsnummer',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Filter by organisasjonsnummer (9 digits)'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Batch size for bulk downloads (max 5000)',
                5000
            )
            ->setHelp(<<<'HELP'
<info>Phase 2: Filtered Import</info>

This command imports detailed data (bygninger, bruksenheter, adresser) for 
matrikkelenheter owned by specific persons or organizations.

<comment>Prerequisites:</comment>
  Run Phase 1 first to import base data:
  php bin/console matrikkel:phase1-import --kommune=4601

<comment>Import Strategy:</comment>
  1. <fg=cyan>Veger</fg=cyan>: Bulk download (entire kommune) - needed for adresser
  2. <fg=cyan>Bruksenheter</fg=cyan>: API-filtered (two-step pattern)
  3. <fg=cyan>Bygninger</fg=cyan>: Bulk download + client-side filter
  4. <fg=cyan>Adresser</fg=cyan>: API-filtered (two-step pattern)

<comment>Examples:</comment>
  # Import all data for kommune (no owner filter)
  php bin/console matrikkel:phase2-import --kommune=4601

  # Filter by organization (e.g. Bergen Kommune)
  php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=922530890

  # Filter by person
  php bin/console matrikkel:phase2-import --kommune=4601 --personnummer=12345678901

<comment>Two-Step Pattern (API-filtered):</comment>
  Step 1: Find IDs for matrikkelenheter (server-side filter)
  Step 2: Fetch full objects via StoreService (batch fetch)
  
  This is MUCH faster than downloading entire kommune!

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $kommune = $input->getOption('kommune');
        $personnummer = $input->getOption('personnummer');
        $organisasjonsnummer = $input->getOption('organisasjonsnummer');
        $batchSize = (int)$input->getOption('batch-size');
        
        if (!$kommune) {
            $io->error('--kommune option is required');
            return Command::FAILURE;
        }
        
        $kommunenummer = (int)$kommune;
        
        $io->title('Phase 2: Filtered Import');
        $io->text([
            'Kommune: ' . $kommunenummer,
            'Batch size: ' . $batchSize,
            'Filter: ' . ($personnummer ? "personnummer=$personnummer" : 
                         ($organisasjonsnummer ? "organisasjonsnummer=$organisasjonsnummer" : 'none (all)')),
        ]);
        $io->newLine();
        
        $startTime = microtime(true);
        
        try {
            // Step 1: Filter matrikkelenheter by owner (SERVER-SIDE via Matrikkel API)
            $io->section('Step 1/5: Filtering matrikkelenheter by owner');
            
            $filteredMatrikkelenheter = $this->matrikkelenhetFilterService->filterMatrikkelenheterByOwner(
                $io,
                $kommunenummer,
                $personnummer,
                $organisasjonsnummer
            );
            
            if (empty($filteredMatrikkelenheter)) {
                $io->error('Ingen matrikkelenheter funnet for spesifisert filter!');
                return Command::FAILURE;
            }
            
            $io->success('Filtrert til ' . count($filteredMatrikkelenheter) . ' matrikkelenheter');
            $io->newLine();
            
            // Step 2: Import veger (bulk download - entire kommune)
            $io->section('Step 2/5: Importing veger (bulk download)');
            $io->warning('Veg import not yet implemented');
            // TODO: $vegCount = $this->vegImportService->importVegerForKommune($io, $kommunenummer, $batchSize);
            
            // Step 3: Import bruksenheter (API-filtered)
            $io->section('Step 3/5: Importing bruksenheter (API-filtered)');
            $bruksenhetCount = $this->bruksenhetImportService->importBruksenheterForMatrikkelenheter(
                $io,
                $kommunenummer,
                $filteredMatrikkelenheter,
                $batchSize
            );
            
            // Step 4: Import bygninger (API-filtered)
            $io->section('Step 4/5: Importing bygninger (API-filtered)');
            $result = $this->bygningImportService->importBygningerForMatrikkelenheter(
                $filteredMatrikkelenheter
            );
            $io->success(sprintf(
                'Imported bygninger: %d (with %d relations to matrikkelenheter)',
                $result['bygninger'],
                $result['relations']
            ));
            
            // Step 5: Import adresser (API-filtered)
            $io->section('Step 5/5: Importing adresser (API-filtered)');
            $io->warning('Adresse import not yet implemented');
            // TODO: $adresseCount = $this->adresseImportService->importAdresserForMatrikkelenheter(...);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $io->newLine();
            $io->success([
                'Phase 2 import complete!',
                "Duration: {$duration}s",
                "Filtered matrikkelenheter: " . count($filteredMatrikkelenheter),
                "Imported bruksenheter: $bruksenhetCount",
            ]);
            
            $io->note([
                'Next steps:',
                '- Implement VegImportService, BygningImportService, etc.',
                '- These will use the filtered matrikkelenhet list',
                '',
                'Filtered matrikkelenheter: ' . count($filteredMatrikkelenheter),
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error([
                'Phase 2 import failed!',
                $e->getMessage(),
            ]);
            
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }
}
