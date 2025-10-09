<?php
/**
 * KommuneImportCommand - Console command for import av kommuner
 * 
 * Bruk:
 * php bin/console matrikkel:kommune-import
 * 
 * @author Sigurd Nes
 * @date 2025-10-07
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\KommuneImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'matrikkel:kommune-import', description: 'Importer alle norske kommuner fra Matrikkel API')]
class KommuneImportCommand extends Command
{
    public function __construct(
        private KommuneImportService $kommuneImportService
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch-størrelse per SOAP-kall', 1000)
            ->setHelp(<<<'HELP'
Denne kommandoen importerer alle norske kommuner fra Matrikkel API
til lokal PostgreSQL-database via NedlastningServiceWS.

Bruker cursor-basert paginering for effektiv henting av store datamengder.

Eksempel:
  php bin/console matrikkel:kommune-import
  php bin/console matrikkel:kommune-import --batch-size=500
HELP
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $batchSize = (int)$input->getOption('batch-size');
        
        $io->title('Import av norske kommuner fra Matrikkel API');
        
        // Sjekk eksisterende kommuner
        $existingCount = $this->kommuneImportService->countKommuner();
        if ($existingCount > 0) {
            $io->warning("Det finnes allerede $existingCount kommuner i databasen.");
            
            if (!$io->confirm('Vil du fortsette? Eksisterende kommuner vil bli oppdatert.', true)) {
                $io->note('Import avbrutt av bruker.');
                return Command::SUCCESS;
            }
        }
        
        try {
            $startTime = microtime(true);
            
            // Kjør import
            $totalCount = $this->kommuneImportService->importAlleKommuner($io, $batchSize);
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            // Vis statistikk
            $io->section('Statistikk');
            $io->table(
                ['Metric', 'Verdi'],
                [
                    ['Totalt antall kommuner', $totalCount],
                    ['Batch-størrelse', $batchSize],
                    ['Tid brukt', $duration . ' sekunder'],
                    ['Gjennomsnitt', $totalCount > 0 ? round($totalCount / $duration, 2) . ' kommuner/sek' : 'N/A'],
                ]
            );
            
            // Bekreft i database
            $dbCount = $this->kommuneImportService->countKommuner();
            $io->note("Verifisert: $dbCount kommuner finnes nå i databasen.");
            
            return Command::SUCCESS;
            
        } catch (\SoapFault $e) {
            $io->error([
                'SOAP-feil oppstod:',
                'Kode: ' . $e->getCode(),
                'Melding: ' . $e->getMessage(),
            ]);
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error([
                'Feil oppstod:',
                'Type: ' . get_class($e),
                'Melding: ' . $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }
}
