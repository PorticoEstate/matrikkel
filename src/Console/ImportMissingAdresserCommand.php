<?php

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\AdresseImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import missing adresser that are referenced by bruksenheter but don't exist in database
 * 
 * This command solves the "orphaned address reference" problem that occurs when:
 * 1. Phase 2 import was run with --organisasjonsnummer filter
 * 2. Bruksenheter were imported with their adresse_id references
 * 3. But the addresses themselves were filtered out (not owned by the org)
 * 4. Result: bruksenheter.adresse_id points to non-existent addresses
 * 
 * This command:
 * - Finds all adresse_id values referenced by bruksenheter that don't exist
 * - Fetches those addresses from the Matrikkel API via StoreClient
 * - Imports them into the database
 * 
 * @author Sigurd Nes
 * @date 2025-12-18
 */
#[AsCommand(
    name: 'matrikkel:import-missing-adresser',
    description: 'Import missing adresser referenced by bruksenheter',
)]
class ImportMissingAdresserCommand extends Command
{
    private AdresseImportService $adresseImportService;

    public function __construct(
        AdresseImportService $adresseImportService
    ) {
        parent::__construct();
        $this->adresseImportService = $adresseImportService;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'kommune',
                null,
                InputOption::VALUE_REQUIRED,
                'Kommune number (e.g., 4601 for Bergen)'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_OPTIONAL,
                'Batch size for StoreClient API calls',
                1000
            )
            ->setHelp(<<<'HELP'
<info>Import Missing Adresser (Orphan Resolution)</info>

This command solves the problem where bruksenheter reference addresses that
don't exist in the database.

<comment>Problem:</comment>
When Phase 2 import is run with --organisasjonsnummer filter:
  1. Bruksenheter are imported with their adresse_id references
  2. But addresses are filtered out if not owned by the organization
  3. Result: bruksenheter.adresse_id → non-existent addresses
  4. Hierarchy organization cannot create real entrances

<comment>Solution:</comment>
This command:
  1. Finds all orphaned adresse_id references from bruksenheter
  2. Fetches those addresses from Matrikkel API (via StoreClient)
  3. Imports them into database with proper relations
  
<comment>Usage:</comment>
  # Import missing addresses for kommune 4601
  php bin/console matrikkel:import-missing-adresser --kommune=4601
  
<comment>Workflow:</comment>
  1. Run Phase 1 + Phase 2 with owner filter
  2. Run this command to import missing addresses
  3. Run backfill command (if needed)
  4. Run organize-hierarchy command
  5. Export spreadsheet

<comment>Benefits:</comment>
  ✓ No need to re-run Phase 2 without filter (faster!)
  ✓ Only imports addresses that are actually needed
  ✓ Solves orphaned reference problem
  ✓ Enables real entrances instead of synthetic ones

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $kommune = $input->getOption('kommune');
        $batchSize = (int)$input->getOption('batch-size');
        
        if (!$kommune) {
            $io->error('--kommune option is required');
            return Command::FAILURE;
        }
        
        $kommunenummer = (int)$kommune;
        
        $io->title('Import Missing Adresser (Orphan Resolution)');
        $io->text([
            'Kommune: ' . $kommunenummer,
            'Batch size: ' . $batchSize,
        ]);
        $io->newLine();
        
        $startTime = microtime(true);
        
        try {
            $result = $this->adresseImportService->importMissingAdresserFromBruksenheter(
                $io,
                $kommunenummer,
                $batchSize
            );
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $io->newLine();
            $io->success([
                'Missing adresser import complete!',
                "Duration: {$duration}s",
                "Imported adresser: {$result['adresser']}",
                "Created M:N relations: {$result['relations']}",
            ]);
            
            if ($result['adresser'] > 0) {
                $io->note([
                    'Next steps:',
                    '1. php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=' . $kommunenummer,
                    '2. php bin/console matrikkel:organize-hierarchy --kommune=' . $kommunenummer . ' --force',
                    '3. php bin/console matrikkel:export-spreadsheet --kommune=' . $kommunenummer,
                ]);
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error([
                'Import failed!',
                $e->getMessage(),
            ]);
            
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }
}
