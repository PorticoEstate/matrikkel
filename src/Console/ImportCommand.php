<?php
/**
 * ImportCommand - Unified import command that runs Phase 1 and Phase 2
 * 
 * This command combines Phase1ImportCommand and Phase2ImportCommand into a single
 * workflow for easier use. It handles the complete import process:
 * 
 * Phase 1: Base data
 * - Kommune (auto-import if missing)
 * - Matrikkelenheter
 * - Personer
 * - Eierforhold
 * 
 * Phase 2: Building data
 * - Veger (bulk import for entire kommune)
 * - Bruksenheter (filtered by matrikkelenheter)
 * - Bygninger (filtered by matrikkelenheter)
 * - Adresser (filtered by matrikkelenheter)
 * 
 * Usage:
 * ```bash
 * # Import all data for a kommune
 * php bin/console matrikkel:import --kommune=4627
 * 
 * # Import filtered by organization
 * php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442
 * 
 * # Import with limit for testing
 * php bin/console matrikkel:import --kommune=4627 --limit=10
 * ```
 * 
 * @author Sigurd Nes
 * @date 2025-10-27
 */

namespace Iaasen\Matrikkel\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:import',
    description: 'Complete import for a kommune (Phase 1 + Phase 2)',
)]
class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('kommune', null, InputOption::VALUE_REQUIRED, 'Kommunenummer (4 siffer)')
            ->addOption('organisasjonsnummer', null, InputOption::VALUE_REQUIRED, 'Filter på organisasjonsnummer')
            ->addOption('fodselsnummer', null, InputOption::VALUE_REQUIRED, 'Filter på fødselsnummer')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maks antall matrikkelenheter (for testing)', null)
            ->addOption('skip-phase1', null, InputOption::VALUE_NONE, 'Hopp over Phase 1 (kun Phase 2)')
            ->addOption('skip-phase2', null, InputOption::VALUE_NONE, 'Hopp over Phase 2 (kun Phase 1)')
            ->setHelp(<<<'HELP'
The <info>matrikkel:import</info> command performs a complete import of Matrikkel data
for a specified kommune, combining Phase 1 (base data) and Phase 2 (building data).

<info>Examples:</info>

  Import all data for Askøy kommune:
    <comment>php bin/console matrikkel:import --kommune=4627</comment>

  Import filtered by organization:
    <comment>php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442</comment>

  Import with limit for testing:
    <comment>php bin/console matrikkel:import --kommune=4627 --limit=10</comment>

  Only run Phase 1:
    <comment>php bin/console matrikkel:import --kommune=4627 --skip-phase2</comment>

  Only run Phase 2:
    <comment>php bin/console matrikkel:import --kommune=4627 --skip-phase1</comment>

<info>Phase 1 (Base data):</info>
  - Kommune (auto-imported if missing)
  - Matrikkelenheter
  - Personer
  - Eierforhold

<info>Phase 2 (Building data):</info>
  - Veger (all in kommune)
  - Bruksenheter
  - Bygninger
  - Adresser

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        // Validate kommune
        $kommunenummer = $input->getOption('kommune');
        if (!$kommunenummer) {
            $io->error('--kommune er påkrevd');
            return Command::FAILURE;
        }

        if (!is_numeric($kommunenummer) || strlen($kommunenummer) !== 4) {
            $io->error('Kommunenummer må være 4 siffer (f.eks. 4627)');
            return Command::FAILURE;
        }

        $kommunenummer = (int) $kommunenummer;
        $organisasjonsnummer = $input->getOption('organisasjonsnummer');
        $fodselsnummer = $input->getOption('fodselsnummer');
        $limit = $input->getOption('limit');
        $skipPhase1 = $input->getOption('skip-phase1');
        $skipPhase2 = $input->getOption('skip-phase2');

        // Header
        $io->title('Matrikkel Complete Import');
        $io->section('Configuration');
        $io->definitionList(
            ['Kommune' => $kommunenummer],
            ['Organisasjonsnummer' => $organisasjonsnummer ?: 'Alle'],
            ['Fødselsnummer' => $fodselsnummer ?: 'Ikke brukt'],
            ['Limit' => $limit ?: 'Ingen (full import)'],
            ['Phase 1' => $skipPhase1 ? '❌ Skipped' : '✓ Enabled'],
            ['Phase 2' => $skipPhase2 ? '❌ Skipped' : '✓ Enabled'],
        );

        if ($skipPhase1 && $skipPhase2) {
            $io->error('Kan ikke hoppe over både Phase 1 og Phase 2!');
            return Command::FAILURE;
        }

        $io->newLine();

        // Phase 1: Base data
        if (!$skipPhase1) {
            $io->section('Phase 1: Base Data Import');
            $io->text('Importing: Kommune → Matrikkelenheter → Personer → Eierforhold');
            $io->newLine();

            $phase1Args = [
                'command' => 'matrikkel:phase1-import',
                '--kommune' => $kommunenummer,
            ];

            if ($organisasjonsnummer) {
                $phase1Args['--organisasjonsnummer'] = $organisasjonsnummer;
            }

            if ($limit) {
                $phase1Args['--limit'] = $limit;
            }

            $phase1Input = new ArrayInput($phase1Args);

            $phase1Command = $this->getApplication()->find('matrikkel:phase1-import');
            $phase1Result = $phase1Command->run($phase1Input, $output);

            if ($phase1Result !== Command::SUCCESS) {
                $io->error('Phase 1 import failed!');
                return Command::FAILURE;
            }

            $io->newLine(2);
        }

        // Phase 2: Building data
        if (!$skipPhase2) {
            $io->section('Phase 2: Building Data Import');
            $io->text('Importing: Veger → Bruksenheter → Bygninger → Adresser');
            $io->newLine();

            $phase2Args = [
                'command' => 'matrikkel:phase2-import',
                '--kommune' => $kommunenummer,
            ];

            if ($organisasjonsnummer) {
                $phase2Args['--organisasjonsnummer'] = $organisasjonsnummer;
            }

            $phase2Input = new ArrayInput($phase2Args);

            $phase2Command = $this->getApplication()->find('matrikkel:phase2-import');
            $phase2Result = $phase2Command->run($phase2Input, $output);

            if ($phase2Result !== Command::SUCCESS) {
                $io->error('Phase 2 import failed!');
                return Command::FAILURE;
            }

            $io->newLine(2);
        }

        // Summary
        $duration = microtime(true) - $startTime;
        $io->success([
            'Complete import finished successfully!',
            sprintf('Total duration: %.2fs', $duration),
            '',
            'Next steps:',
            '1. Verify data in database',
            '2. Test REST API endpoints at http://localhost:8083/api/v1/',
        ]);

        return Command::SUCCESS;
    }
}
