<?php
/**
 * BackfillBruksenhetAddressesCommand - Link bruksenheter without adresse_id via matrikkelenhet addresses
 *
 * Usage:
 *   php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=4601
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\BruksenhetAddressBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:backfill-bruksenhet-addresses',
    description: 'Assign adresse_id to bruksenheter missing it via matrikkelenhet addresses',
)]
class BackfillBruksenhetAddressesCommand extends Command
{
    public function __construct(
        private BruksenhetAddressBackfillService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kommune', null, InputOption::VALUE_REQUIRED, 'Kommunenummer (4 siffer)', null)
            ->setHelp(<<<'HELP'
The <info>matrikkel:backfill-bruksenhet-addresses</info> command assigns <comment>adresse_id</comment> to units
that are missing it by using addresses attached to the same <comment>matrikkelenhet</comment>.

Run this before organizing hierarchy to ensure entrances can be created.

Examples:
  <comment>php bin/console matrikkel:backfill-bruksenhet-addresses --kommune=4601</comment>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kommune = $input->getOption('kommune');

        if (!$kommune || !ctype_digit($kommune) || strlen($kommune) !== 4) {
            $io->error('--kommune er påkrevd og må være 4 siffer');
            return Command::FAILURE;
        }

        $stats = $this->service->backfillByKommunenummer((int) $kommune, $io);

        $io->section('Resultat');
        $io->writeln(sprintf('Prosessert: %d', $stats['processed']));
        $io->writeln(sprintf('Oppdatert:  %d', $stats['updated']));
        $io->writeln(sprintf('Skippet:    %d', $stats['skipped']));
        $io->writeln(sprintf('Feil:       %d', $stats['errors']));

        return Command::SUCCESS;
    }
}
