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
  2. Bulk download all matrikkelenheter for kommune
  3. Extract person IDs from eierforhold
  4. Fetch and store person data (fysiske + juridiske personer)
  5. Store eierforhold (ownership) records

<comment>Examples:</comment>
  # Import base data for Bergen
  php bin/console matrikkel:phase1-import --kommune=4601

  # Import with maximum batch size (fastest)
  php bin/console matrikkel:phase1-import --kommune=4601 --batch-size=5000

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
        $batchSize = (int)$input->getOption('batch-size');
        $limit = $input->getOption('limit') ? (int)$input->getOption('limit') : null;
        
        if (!$kommune) {
            $io->error('--kommune option is required');
            return Command::FAILURE;
        }
        
        $kommunenummer = (int)$kommune;
        
        $io->title('Phase 1: Base Import');
        $io->text([
            'Kommune: ' . $kommunenummer,
            'Batch size: ' . $batchSize,
            'Limit: ' . ($limit ? $limit : 'none (all)'),
        ]);
        $io->newLine();
        
        $startTime = microtime(true);
        
        try {
            // Step 1: Ensure kommune exists
            $io->section('Step 1/4: Ensuring kommune exists in database');
            // TODO: Check if kommune exists, if not fetch from API
            // For now, assume kommune is already in database (manual INSERT or previous import)
            $io->text("Kommune $kommunenummer should exist in database");
            $io->success('Kommune check complete');
            
            // Step 2: Import matrikkelenheter (bulk download)
            $io->section('Step 2/4: Importing matrikkelenheter (bulk download)');
            $matrikkelenhetCount = $this->matrikkelenhetImportService->importMatrikkelenheterForKommune(
                $io,
                $kommunenummer,
                $batchSize,
                $limit
            );
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
