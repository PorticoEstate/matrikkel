<?php
/**
 * Test command for NedlastningClient
 * 
 * Dette er en enkel test-command for å verifisere at NedlastningClient
 * fungerer korrekt med Matrikkel API.
 * 
 * Bruk:
 * php bin/console matrikkel:test-nedlastning [domainklasse] [--kommune=X] [--max=N]
 * 
 * Eksempler:
 * php bin/console matrikkel:test-nedlastning Kommune
 * php bin/console matrikkel:test-nedlastning Matrikkelenhet --kommune=0301 --max=10
 * php bin/console matrikkel:test-nedlastning Bygning --kommune=0301 --max=5
 * 
 * @author Sigurd Nes
 * @date 2025-10-07
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\NedlastningClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'matrikkel:test-nedlastning', description: 'Test NedlastningClient med bulk-nedlasting')]
class TestNedlastningCommand extends Command
{
    
    public function __construct(
        private NedlastningClient $nedlastningClient
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addArgument('domainklasse', InputArgument::OPTIONAL, 'Objekttype (Kommune, Matrikkelenhet, Bygning, etc.)', 'Kommune')
            ->addOption('kommune', 'k', InputOption::VALUE_OPTIONAL, 'Kommunenummer for filtrering (f.eks. 0301)')
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Maksimalt antall objekter å hente', 10)
            ->addOption('test-filter', 't', InputOption::VALUE_NONE, 'Test ulike filter-syntakser')
            ->setHelp(<<<'HELP'
Denne kommandoen tester NedlastningClient og viser hvordan bulk-nedlasting fungerer.

Støttede domainklasser:
  - Kommune, Fylke
  - Matrikkelenhet, Grunneiendom, Festegrunn, Seksjon
  - Bygg, Bygning, Bygningsendring
  - Bruksenhet
  - Adresse, Vegadresse, Matrikkeladresse, Veg
  - Teig, Teiggrense
  - Kulturminne

Eksempler:
  # Hent 10 første kommuner (uten filter)
  php bin/console matrikkel:test-nedlastning Kommune --max=10

  # Hent matrikkelenheter for Oslo (test filter)
  php bin/console matrikkel:test-nedlastning Matrikkelenhet --kommune=0301 --max=5

  # Test ulike filter-syntakser
  php bin/console matrikkel:test-nedlastning Matrikkelenhet --test-filter
HELP
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $domainklasse = $input->getArgument('domainklasse');
        $kommune = $input->getOption('kommune');
        $maxAntall = (int)$input->getOption('max');
        $testFilter = $input->getOption('test-filter');
        
        $io->title("Test av NedlastningClient");
        $io->section("Konfigurasjon");
        $io->text([
            "Domainklasse: <info>$domainklasse</info>",
            "Kommune: " . ($kommune ? "<info>$kommune</info>" : '<comment>Ingen (henter alle)</comment>'),
            "Maksimalt antall: <info>$maxAntall</info>",
        ]);
        
        // Test filter-syntakser hvis --test-filter er satt
        if ($testFilter) {
            return $this->testFilterSyntax($io, $domainklasse, $kommune);
        }
        
        // Bygg filter-string
        $filter = null;
        if ($kommune) {
            // Test ulike syntakser - vi starter med enkleste
            $filter = "kommunenummer=$kommune";
            $io->note("Tester filter-syntaks: $filter");
        }
        
        try {
            $io->section("Henter data fra Matrikkel API...");
            
            $lastId = 0;
            $totalCount = 0;
            $batchCount = 0;
            
            $io->progressStart($maxAntall);
            
            do {
                $batch = $this->nedlastningClient->findObjekterEtterId(
                    $lastId,
                    $domainklasse,
                    $filter,
                    min($maxAntall - $totalCount, 1000) // Batch maks 1000
                );
                
                $batchCount++;
                
                foreach ($batch as $object) {
                    $totalCount++;
                    $lastId = $object->id->value;
                    
                    // Vis første og siste objekt i hver batch
                    if ($totalCount === 1 || count($batch) === count($batch)) {
                        $this->displayObject($io, $object, $domainklasse, $totalCount);
                    }
                    
                    $io->progressAdvance();
                    
                    if ($totalCount >= $maxAntall) {
                        break 2;
                    }
                }
                
                $io->text("  Batch $batchCount: Hentet " . count($batch) . " objekter (siste ID: $lastId)");
                
            } while (count($batch) > 0 && $totalCount < $maxAntall);
            
            $io->progressFinish();
            
            $io->success([
                "Test fullført!",
                "Hentet totalt $totalCount objekter av type $domainklasse",
                "Antall batches: $batchCount",
                "Siste ID: $lastId"
            ]);
            
            if ($totalCount > 0) {
                $io->note([
                    "For å hente neste batch, start med matrikkelBubbleId = $lastId",
                    "Cursor-basert paginering fungerer!"
                ]);
            }
            
            return Command::SUCCESS;
            
        } catch (\SoapFault $e) {
            $io->error([
                "SOAP-feil oppstod:",
                "Kode: " . $e->getCode(),
                "Melding: " . $e->getMessage(),
            ]);
            
            if ($kommune && strpos($e->getMessage(), 'filter') !== false) {
                $io->warning([
                    "Det ser ut til at filter-syntaksen ikke er korrekt.",
                    "Prøv å kjøre med --test-filter for å teste ulike syntakser."
                ]);
            }
            
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error([
                "Feil oppstod:",
                "Type: " . get_class($e),
                "Melding: " . $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }
    
    private function displayObject(SymfonyStyle $io, object $object, string $domainklasse, int $index): void
    {
        $io->section("Objekt #$index ($domainklasse)");
        
        $info = ["ID: " . ($object->id->value ?? 'N/A')];
        
        // Vis relevante felter basert på domainklasse
        match($domainklasse) {
            'Kommune' => $info = array_merge($info, [
                "Kommunenummer: " . ($object->kommunenummer ?? 'N/A'),
                "Kommunenavn: " . ($object->kommunenavn ?? 'N/A'),
            ]),
            'Matrikkelenhet' => $info = array_merge($info, [
                "Kommunenummer: " . ($object->matrikkelnummer->kommunenummer ?? 'N/A'),
                "Gårdsnummer: " . ($object->matrikkelnummer->gardsnummer ?? 'N/A'),
                "Bruksnummer: " . ($object->matrikkelnummer->bruksnummer ?? 'N/A'),
            ]),
            'Bygning', 'Bygg' => $info = array_merge($info, [
                "Bygningsnummer: " . ($object->bygningsnummer ?? 'N/A'),
                "Kommunenummer: " . ($object->kommuneId ?? 'N/A'),
            ]),
            default => $info[] = "Type: $domainklasse"
        };
        
        $io->listing($info);
    }
    
    private function testFilterSyntax(SymfonyStyle $io, string $domainklasse, ?string $kommune): int
    {
        if (!$kommune) {
            $io->error("--kommune må være satt for å teste filter-syntaks");
            return Command::FAILURE;
        }
        
        $io->section("Testing av ulike filter-syntakser");
        
        $syntaxVariants = [
            "kommunenummer=$kommune",
            "kommunenummer = '$kommune'",
            "kommunenummer = \"$kommune\"",
            "kommunenummer='$kommune'",
        ];
        
        foreach ($syntaxVariants as $i => $filter) {
            $io->text(sprintf("Test %d: <info>%s</info>", $i + 1, $filter));
            
            try {
                $result = $this->nedlastningClient->findObjekterEtterId(
                    0,
                    $domainklasse,
                    $filter,
                    5
                );
                
                $count = count($result);
                $io->success("✓ Filter fungerer! Hentet $count objekter");
                
                if ($count > 0) {
                    $io->note("Anbefalt filter-syntaks: $filter");
                    return Command::SUCCESS;
                }
                
            } catch (\SoapFault $e) {
                $io->error("✗ Filter feilet: " . $e->getMessage());
            }
        }
        
        $io->warning("Ingen filter-syntaks fungerte. Prøv uten filter eller sjekk Kartverket sin dokumentasjon.");
        
        return Command::FAILURE;
    }
}
