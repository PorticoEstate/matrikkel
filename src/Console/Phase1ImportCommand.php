<?php
/**
 * Phase 1: Base Import Command
 * 
 * Imports:
 * 1. Kommune data
 * 2. Matrikkelenheter (bulk download with kommune filter)
 * 3. Personer (from eierforhold)
 * 4. Eierforhold (ownership records)
 * 
 * This is the foundation import that must run before Phase 2.
 * 
 * Usage:
 *   php bin/console matrikkel:phase1-import --kommune=4601
 *   php bin/console matrikkel:phase1-import --kommune=4601 --batch-size=5000
 * 
 * @author Matrikkel Integration System
 * @date 2025-10-22
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\KommuneImportService;
use Iaasen\Matrikkel\Service\MatrikkelenhetImportService;
use Iaasen\Matrikkel\Service\PersonImportService;
use Iaasen\Matrikkel\Service\EierforholdImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:phase1-import',
    description: 'Phase 1: Base import (kommune + matrikkelenheter + personer + eierforhold)'
)]
class Phase1ImportCommand extends Command
{
    public function __construct(
        private KommuneImportService $kommuneImportService,
        private MatrikkelenhetImportService $matrikkelenhetImportService,
        private PersonImportService $personImportService,
        private EierforholdImportService $eierforholdImportService,
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
                'organisasjonsnummer',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Filter by organisation number (9 digits)',
                null
            )
            ->addOption(
                'personnummer',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Filter by person number (11 digits)',
                null
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Batch size for bulk downloads (max 5000)',
                5000
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit total number of matrikkelenheter to import (for testing)',
                null
            )
            ->setHelp(<<<'HELP'
<info>Phase 1: Base Import</info>

This command performs the foundational import that must run before Phase 2:

<comment>Steps:</comment>
  1. Import kommune metadata
  2. Download matrikkelenheter for kommune (optionally filtered by owner)
  3. Extract person IDs from eierforhold
  4. Fetch and store person data (fysiske + juridiske personer)
  5. Store eierforhold (ownership) records

<comment>Examples:</comment>
  # Import ALL base data for Bergen (full bulk download)
  php bin/console matrikkel:phase1-import --kommune=4601

  # Import ONLY matrikkelenheter owned by specific organization
  php bin/console matrikkel:phase1-import --kommune=4601 --organisasjonsnummer=964338531

  # Import ONLY matrikkelenheter owned by specific person
  php bin/console matrikkel:phase1-import --kommune=4601 --personnummer=12345678901

  # Import with maximum batch size (fastest)
  php bin/console matrikkel:phase1-import --kommune=4601 --batch-size=5000

<comment>Filtering:</comment>
  When --organisasjonsnummer or --personnummer is provided, Phase 1 will:
  1. Query the API to find matrikkelenheter owned by that person/org
  2. Fetch only those matrikkelenheter (targeted import)
  3. Import personer and eierforhold for those matrikkelenheter
  
  Without filters, Phase 1 does a full bulk download of all matrikkelenheter.

<comment>After Phase 1:</comment>
  Run Phase 2 to import filtered data (bygninger, bruksenheter, adresser):
  php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=922530890

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $kommune = $input->getOption('kommune');
        $organisasjonsnummer = $input->getOption('organisasjonsnummer');
        $personnummer = $input->getOption('personnummer');
        $batchSize = (int)$input->getOption('batch-size');
        $limit = $input->getOption('limit') ? (int)$input->getOption('limit') : null;
        
        if (!$kommune) {
            $io->error('--kommune option is required');
            return Command::FAILURE;
        }
        
        // Validate that only one filter is provided
        if ($organisasjonsnummer && $personnummer) {
            $io->error('Cannot specify both --organisasjonsnummer and --personnummer. Choose one.');
            return Command::FAILURE;
        }
        
        $kommunenummer = (int)$kommune;
        
        // Determine import mode
        $importMode = 'bulk';  // Default: bulk download all matrikkelenheter
        $filterValue = null;
        
        if ($organisasjonsnummer) {
            $importMode = 'filtered';
            $filterValue = $organisasjonsnummer;
            $filterType = 'organisasjonsnummer';
        } elseif ($personnummer) {
            $importMode = 'filtered';
            $filterValue = $personnummer;
            $filterType = 'personnummer';
        }
        
        $io->title('Phase 1: Base Import');
        $io->text([
            'Kommune: ' . $kommunenummer,
            'Import mode: ' . $importMode,
        ]);
        
        if ($importMode === 'filtered') {
            $io->text('Filter: ' . $filterType . ' = ' . $filterValue);
        }
        
        $io->text([
            'Batch size: ' . $batchSize,
            'Limit: ' . ($limit ? $limit : 'none (all)'),
        ]);
        $io->newLine();
        
        $startTime = microtime(true);
        
        try {
            // Step 1: Ensure kommune exists
            $io->section('Step 1/4: Ensuring kommune exists in database');
            
            $kommuneExists = $this->kommuneImportService->kommuneExists($kommunenummer);
            
            if (!$kommuneExists) {
                $io->text("Kommune $kommunenummer not found in database - fetching from Matrikkel API");
                $success = $this->kommuneImportService->importKommune($io, $kommunenummer);
                
                if (!$success) {
                    $io->error("Failed to fetch kommune $kommunenummer from Matrikkel API");
                    return Command::FAILURE;
                }
                
                $io->text("✓ Kommune $kommunenummer imported successfully");
            } else {
                $io->text("✓ Kommune $kommunenummer already exists in database");
            }
            
            $io->success('Kommune check complete');
            
            // Step 2: Import matrikkelenheter
            $io->section('Step 2/4: Importing matrikkelenheter');
            
            if ($importMode === 'filtered') {
                $io->text("Using FILTERED import (owner: $filterValue)");
                $matrikkelenhetCount = $this->matrikkelenhetImportService->importMatrikkelenheterFiltered(
                    $io,
                    $kommunenummer,
                    $filterValue,
                    $batchSize,
                    $limit
                );
            } else {
                $io->text("Using BULK import (all matrikkelenheter for kommune)");
                $matrikkelenhetCount = $this->matrikkelenhetImportService->importMatrikkelenheterForKommune(
                    $io,
                    $kommunenummer,
                    $batchSize,
                    $limit
                );
            }
            
            $io->success("Imported $matrikkelenhetCount matrikkelenheter");
            
            // Step 3: Import personer (from eierforhold)
            $io->section('Step 3/4: Importing personer');
            $personCount = $this->personImportService->importPersonerForKommune($io, $kommunenummer);
            $io->success("Imported $personCount personer");
            
            // Step 4: Import eierforhold
            $io->section('Step 4/4: Importing eierforhold');
            $eierforholdCount = $this->eierforholdImportService->importEierforholdForKommune($io, $kommunenummer);
            $io->success("Updated $eierforholdCount matrikkelenheter with eierforhold");
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $io->newLine();
            $io->success([
                'Phase 1 import complete!',
                "Duration: {$duration}s",
                "Matrikkelenheter: $matrikkelenhetCount",
                "Personer: $personCount",
                "Eierforhold: $eierforholdCount",
            ]);
            
            $io->note([
                'Next steps:',
                '1. Run Phase 2 to import bygninger, bruksenheter, adresser:',
                "   php bin/console matrikkel:phase2-import --kommune=$kommunenummer",
                '',
                '2. Or filter by owner:',
                "   php bin/console matrikkel:phase2-import --kommune=$kommunenummer --organisasjonsnummer=123456789",
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error([
                'Phase 1 import failed!',
                $e->getMessage(),
            ]);
            
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }
}
