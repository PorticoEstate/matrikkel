<?php
/**
 * Debug command: Check specific bruksenhet details from API
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\BruksenhetId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:debug-bruksenhet-details',
    description: 'Debug: Show full bruksenhet object from API'
)]
class DebugBruksenhetDetailsCommand extends Command
{
    public function __construct(
        private StoreClient $storeClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'bruksenhet-id',
            InputArgument::REQUIRED,
            'Bruksenhet ID to fetch'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bruksenhetId = (int) $input->getArgument('bruksenhet-id');
        
        $io->title("Debug: Bruksenhet $bruksenhetId from API");
        
        try {
            $bruksenhetIdObject = new BruksenhetId($bruksenhetId);
            $objects = $this->storeClient->getObjects([$bruksenhetIdObject]);
            
            if (empty($objects)) {
                $io->warning('No bruksenhet returned from API');
                return Command::FAILURE;
            }
            
            $bruksenhet = $objects[0];
            
            $io->section('Bruksenhet Object Structure');
            
            $io->listing([
                'ID: ' . ($bruksenhet->id->value ?? 'null'),
                'UUID: ' . ($bruksenhet->uuid->uuid ?? 'null'),
                'Løpenummer: ' . ($bruksenhet->lopenummer ?? 'null'),
                'MatrikkelenhetId: ' . ($bruksenhet->matrikkelenhetId->value ?? 'NULL'),
                'BygningId: ' . ($bruksenhet->byggId->value ?? 'null'),
                'AdresseId: ' . ($bruksenhet->adresseId->value ?? 'null'),
                'Bruksenhettype: ' . ($bruksenhet->bruksenhetstypeKodeId->value ?? 'null'),
                'Etasjeplan: ' . ($bruksenhet->etasjeplanKodeId->value ?? 'null'),
                'Etasjenummer: ' . ($bruksenhet->etasjenummer ?? 'null'),
                'Antall rom: ' . ($bruksenhet->antallRom ?? 'null'),
                'Bruksareal: ' . ($bruksenhet->bruksareal ?? 'null'),
            ]);
            
            $io->section('Full Object Dump');
            $io->text(print_r($bruksenhet, true));
            
            if (!isset($bruksenhet->matrikkelenhetId) || !isset($bruksenhet->matrikkelenhetId->value)) {
                $io->error('⚠ This bruksenhet has NO matrikkelenhetId - it\'s an orphan!');
                $io->text('This bruksenhet cannot be imported without a matrikkelenhet reference.');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
