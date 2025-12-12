<?php
/**
 * OrganizeHierarchyCommand - Organize Portico 4-level location hierarchy
 * 
 * Processes all matrikkelenheter for a kommune and assigns deterministic
 * location codes at each level:
 * - Eiendom: 5000
 * - Bygg: 5000-01
 * - Inngang: 5000-01-01
 * - Bruksenhet: 5000-01-01-001
 * 
 * Uses stable sorting to ensure idempotent results across multiple runs.
 * 
 * Usage:
 * ```bash
 * # Organize all properties in a kommune
 * php bin/console matrikkel:organize-hierarchy --kommune=4627
 * 
 * # Organize specific property
 * php bin/console matrikkel:organize-hierarchy --kommune=4627 --matrikkelenhet=12345
 * 
 * # Force re-organization (overwrite existing codes)
 * php bin/console matrikkel:organize-hierarchy --kommune=4627 --force
 * ```
 * 
 * @author Sigurd Nes
 * @date 2025-10-28
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository;
use Iaasen\Matrikkel\Service\HierarchyOrganizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:organize-hierarchy',
    description: 'Organize Portico 4-level location hierarchy (Eiendom→Bygg→Inngang→Bruksenhet)',
)]
class OrganizeHierarchyCommand extends Command
{
    public function __construct(
        private MatrikkelenhetRepository $matrikkelenhetRepository,
        private HierarchyOrganizationService $hierarchyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kommune', null, InputOption::VALUE_REQUIRED, 'Kommunenummer (4 siffer)', null)
            ->addOption('matrikkelenhet', null, InputOption::VALUE_REQUIRED, 'Spesifikk matrikkelenhet_id (valgfritt)', null)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Tving omorganisering (overskriv eksisterende koder)')
            ->setHelp(<<<'HELP'
The <info>matrikkel:organize-hierarchy</info> command processes matrikkelenheter and assigns
deterministic location codes at each level of the Portico hierarchy.

<info>Location Code Levels:</info>
  1. Eiendom (Property): 5000
  2. Bygg (Building): 5000-01
  3. Inngang (Entrance): 5000-01-01
  4. Bruksenhet (Unit): 5000-01-01-001

<info>Sorting Rules (deterministic):</info>
  - Buildings: by bygning_id ASC
  - Entrances: by husnummer ASC, bokstav ASC, veg_id ASC
  - Units: by etasjenummer ASC, lopenummer ASC, bruksenhet_id ASC

<info>Examples:</info>

  Organize all properties in a kommune:
    <comment>php bin/console matrikkel:organize-hierarchy --kommune=4627</comment>

  Organize specific property:
    <comment>php bin/console matrikkel:organize-hierarchy --kommune=4627 --matrikkelenhet=12345</comment>

  Force re-organization:
    <comment>php bin/console matrikkel:organize-hierarchy --kommune=4627 --force</comment>

<info>Notes:</info>
  - Idempotent: running multiple times produces same results
  - Codes stored in database for consistency
  - Properties without buildings are still assigned eiendom-level code
  - Entrances group address-to-building relationships

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $kommune = $input->getOption('kommune');
        $matrikkelenhetId = $input->getOption('matrikkelenhet');
        $force = $input->getOption('force');

        // Validate input
        if (!$kommune) {
            $io->error('--kommune is required');
            return Command::FAILURE;
        }

        if (!ctype_digit($kommune) || strlen($kommune) !== 4) {
            $io->error('Kommune must be a 4-digit number');
            return Command::FAILURE;
        }

        // Fetch matrikkelenheter
        try {
            if ($matrikkelenhetId) {
                // Specific property
                $result = $this->matrikkelenhetRepository->findById((int)$matrikkelenhetId);
                
                if (!$result) {
                    $io->error(sprintf('Matrikkelenhet %d not found', $matrikkelenhetId));
                    return Command::FAILURE;
                }

                // Verify it's in the correct kommune
                if ((int)$result['kommunenummer'] !== (int)$kommune) {
                    $io->error(sprintf('Matrikkelenhet %d is not in kommune %s', $matrikkelenhetId, $kommune));
                    return Command::FAILURE;
                }
                
                $matrikkelenheter = [$result];
            } else {
                // All properties in kommune
                $matrikkelenheter = $this->matrikkelenhetRepository->findByKommunenummer((int)$kommune, 100000);
            }

            if (empty($matrikkelenheter)) {
                $io->warning('No matrikkelenheter found');
                return Command::SUCCESS;
            }

            $io->info(sprintf('Found %d matrikkelenheter', count($matrikkelenheter)));

            // Process each property
            $progressBar = new ProgressBar($output, count($matrikkelenheter));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $progressBar->start();

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($matrikkelenheter as $matr) {
                try {
                    $matrikkelId = (int)$matr['matrikkelenhet_id'];
                    
                    // Check if already organized (unless --force)
                    if (!$force && $matr['lokasjonskode_eiendom'] !== null) {
                        $progressBar->setMessage('Skipped (already organized)');
                        $progressBar->advance();
                        continue;
                    }

                    $this->hierarchyService->organizeEiendom($matrikkelId);
                    $successCount++;
                    $progressBar->setMessage(sprintf('Org. %d', $matrikkelId));
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = sprintf('Matr. %s: %s', $matr['matrikkelenhet_id'] ?? 'unknown', $e->getMessage());
                    $progressBar->setMessage(sprintf('Error: %s', $e->getMessage()));
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

            // Summary
            $io->section('Summary');
            $io->writeln(sprintf('✓ Successful: %d', $successCount));
            $io->writeln(sprintf('✗ Errors: %d', $errorCount));

            if (!empty($errors)) {
                $io->newLine();
                $io->section('Errors');
                foreach ($errors as $error) {
                    $io->writeln(sprintf('  • %s', $error));
                }
            }

            return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            $io->error(sprintf('Unexpected error: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
