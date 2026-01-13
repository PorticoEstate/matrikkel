<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\AddressLookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SupplementByAddressCommand - Import/supplement data by street address components
 * 
 * Allows lookup and import of address data by:
 * - kommune + gatenavn + husnummer + bokstav
 * 
 * Features:
 * - Street name disambiguation (prompts user when multiple streets with same name exist)
 * - Fetches full address object from SOAP API
 * - Optionally fetches linked matrikkelenheter and bruksenheter
 * - Displays results for user inspection/approval
 * 
 * Usage:
 *   # Interactive lookup with prompts
 *   php bin/console matrikkel:supplement-by-address \
 *     --kommune=4627 \
 *     --gatenavn="Storgata" \
 *     --husnummer=42 \
 *     --bokstav=A
 *   
 *   # Lookup multiple addresses from CSV file
 *   php bin/console matrikkel:supplement-by-address --file=addresses.csv
 *   
 *   # CSV format (addresses.csv):
 *   kommunenr,gatenavn,husnummer,bokstav
 *   4627,Storgata,42,A
 *   4627,Osloveien,100,
 *   4601,Åsane Alle,15,B
 * 
 * @author GitHub Copilot
 * @date 2026-01-12
 */
#[AsCommand(
    name: 'matrikkel:supplement-by-address',
    description: 'Lookup and supplement address data by street components (gatenavn + husnummer + bokstav)'
)]
class SupplementByAddressCommand extends AbstractCommand
{
    public function __construct(
        private AddressLookupService $addressLookupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'kommune',
                null,
                InputOption::VALUE_REQUIRED,
                'Municipality number (e.g., 4627)'
            )
            ->addOption(
                'gatenavn',
                'g',
                InputOption::VALUE_REQUIRED,
                'Street name (e.g., "Storgata")'
            )
            ->addOption(
                'husnummer',
                null,
                InputOption::VALUE_REQUIRED,
                'House number (e.g., 42)'
            )
            ->addOption(
                'bokstav',
                null,
                InputOption::VALUE_OPTIONAL,
                'House letter suffix (e.g., A, B, C)',
                null
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'CSV file with addresses to lookup (format: kommunenr,gatenavn,husnummer,bokstav)',
                null
            )
            ->addOption(
                'no-related',
                null,
                InputOption::VALUE_NONE,
                "Don't fetch linked matrikkelenheter and bruksenheter"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('Supplement Address Data by Street Components');

        // Determine input source: single address vs CSV file
        $file = $input->getOption('file');

        if ($file) {
            return $this->executeFromFile($file, $input);
        } else {
            return $this->executeSingleAddress($input);
        }
    }

    /**
     * Lookup single address from command options
     */
    private function executeSingleAddress(InputInterface $input): int
    {
        $kommune = $input->getOption('kommune');
        $gatenavn = $input->getOption('gatenavn');
        $husnummer = $input->getOption('husnummer');
        $bokstav = $input->getOption('bokstav');

        // Validate required options
        if (!$kommune || !$gatenavn || !$husnummer) {
            $this->io->error(
                'Mangler påkrevde alternativer: --kommune, --gatenavn, --husnummer'
            );
            return Command::FAILURE;
        }

        $komunenummer = (int) $kommune;
        $husnummer = (int) $husnummer;
        $fetchRelated = !$input->getOption('no-related');

        $this->io->section(sprintf(
            'Søker etter: %s %d%s i kommune %d',
            $gatenavn,
            $husnummer,
            $bokstav ? " $bokstav" : "",
            $komunenummer
        ));

        // Lookup address with user prompts for disambiguation
        $found = $this->addressLookupService->findByStreetAddress(
            gatenavn: $gatenavn,
            husnummer: $husnummer,
            bokstav: $bokstav,
            kommunenummer: $komunenummer,
            io: $this->io,
            fetchRelated: $fetchRelated
        );

        if (!$found) {
            $this->io->error('Adressen ble ikke funnet i Matrikkel API');
            return Command::FAILURE;
        }

        // Display found address
        $this->displayFoundAddress($found);

        return Command::SUCCESS;
    }

    /**
     * Lookup multiple addresses from CSV file
     */
    private function executeFromFile(string $filePath, InputInterface $input): int
    {
        if (!file_exists($filePath)) {
            $this->io->error("File not found: $filePath");
            return Command::FAILURE;
        }

        $fetchRelated = !$input->getOption('no-related');
        $results = [];
        $lineNum = 0;

        // Parse CSV
        if (($handle = fopen($filePath, 'r')) === false) {
            $this->io->error("Could not open file: $filePath");
            return Command::FAILURE;
        }

        // Skip header
        $header = fgetcsv($handle);
        if (!$header || count($header) < 3) {
            $this->io->error(
                'Invalid CSV format. Expected: kommunenr,gatenavn,husnummer,bokstav'
            );
            fclose($handle);
            return Command::FAILURE;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;

            if (count($row) < 3) {
                $this->io->warning("Line $lineNum: Insufficient columns, skipping");
                continue;
            }

            $kommunenummer = (int) $row[0];
            $gatenavn = trim($row[1]);
            $husnummer = (int) $row[2];
            $bokstav = isset($row[3]) && trim($row[3]) !== '' ? trim($row[3]) : null;

            $this->io->text("Line $lineNum: Looking up $gatenavn $husnummer" . ($bokstav ? " $bokstav" : "") . " (kommune $kommunenummer)");

            $found = $this->addressLookupService->findByStreetAddress(
                gatenavn: $gatenavn,
                husnummer: $husnummer,
                bokstav: $bokstav,
                kommunenummer: $kommunenummer,
                io: null, // No prompts for batch mode; use first match
                fetchRelated: $fetchRelated
            );

            if ($found) {
                $results[] = $found;
                $this->io->writeln("  ✓ Found: Veg ID " . $found->vegId);
            } else {
                $this->io->writeln("  ✗ Not found");
            }
        }

        fclose($handle);

        // Summary
        $this->io->section(sprintf(
            'Results: %d of %d addresses found',
            count($results),
            $lineNum
        ));

        if (empty($results)) {
            return Command::FAILURE;
        }

        // Display found addresses
        foreach ($results as $found) {
            $this->displayFoundAddress($found);
        }

        return Command::SUCCESS;
    }

    /**
     * Display found address details
     */
    private function displayFoundAddress($found): void
    {
        $this->io->success(sprintf(
            'Funnet: %s %d%s (Veg ID: %d, Adressekode: %d)',
            $found->gatenavn,
            $found->husnummer,
            $found->bokstav ? " " . $found->bokstav : "",
            $found->vegId,
            $found->adressekode
        ));

        // Display address object details
        $vegadresseObj = $found->vegadresseObject;
        $this->io->definitionList(
            'Vegadresse details:',
            [
                'Vegadresse ID' => $found->vegadresseId,
                'Type' => $vegadresseObj->adressetype ?? 'Unknown',
                'Kortnavn' => $vegadresseObj->kortnavn ?? '—',
                'Tilleggsnavn' => $vegadresseObj->adressetilleggsnavn ?? '—',
            ]
        );

        // Display related data if available
        if ($found->matrikkelObject) {
            $matrikkel = $found->matrikkelObject;
            
            // Extract ID value if it's an object
            $matrikkelId = $matrikkel->id;
            if (is_object($matrikkelId) && isset($matrikkelId->value)) {
                $matrikkelId = $matrikkelId->value;
            }
            
            // Extract matrikkelnummer from nested object
            $matrikkelnummer = null;
            if ($matrikkel->matrikkelnummer && is_object($matrikkel->matrikkelnummer)) {
                $m = $matrikkel->matrikkelnummer;
                // Access properties - kommuneId may be an ID object or scalar
                $knr = $m->kommuneId;
                if (is_object($knr)) {
                    $knr = $knr->value ?? $knr->kommunenummer ?? null;
                }
                $gnr = $m->gardsnummer;
                $bnr = $m->bruksnummer;
                if ($knr && $gnr && $bnr) {
                    $matrikkelnummer = sprintf('%d/%d/%d', $knr, $gnr, $bnr);
                }
            }
            
            // Extract areal - prefer bruksareal, fall back to historiskOppgittAreal
            $areal = null;
            if (isset($matrikkel->bruksareal) && $matrikkel->bruksareal > 0) {
                $areal = $matrikkel->bruksareal;
            } elseif (isset($matrikkel->historiskOppgittAreal) && $matrikkel->historiskOppgittAreal > 0) {
                $areal = $matrikkel->historiskOppgittAreal;
            }
            
            $matrikkelInfo = [
                'Matrikkelenhet ID' => $matrikkelId ?? 'Unknown',
            ];
            
            if ($matrikkelnummer) {
                $matrikkelInfo['Matrikkelnummer'] = $matrikkelnummer;
            }
            
            if ($areal !== null && $areal !== false) {
                $matrikkelInfo['Areal'] = $areal . ' m²';
            }
            
            // Display matrikkelenhet info
            $this->io->writeln('');
            $this->io->writeln('Linked Matrikkelenhet:');
            foreach ($matrikkelInfo as $key => $value) {
                $this->io->writeln(sprintf('  <info>%-20s</> %s', $key, $value));
            }
            $this->io->writeln('');
        }


        // Display registered owners (tinglyst eier)
        if (!empty($found->owners)) {
            $this->io->text(sprintf(
                'Registered Owners (Tinglyst Eier): %d owner(s)',
                count($found->owners)
            ));

            foreach ($found->owners as $owner) {
                $ownerInfo = [
                    'Name' => $owner['owner_name'] ?? 'Unknown',
                ];
                
                if (!empty($owner['owner_type'])) {
                    $ownerInfo['Type'] = $owner['owner_type'];
                }
                
                if (!empty($owner['identifikator'])) {
                    $ownerInfo['Identifikator'] = $owner['identifikator'];
                }

                if ($owner['share_percent'] !== null && $owner['share_percent'] !== '') {
                    $ownerInfo['Share'] = $owner['share_percent'] . '%';
                } elseif ($owner['andel_teller'] && $owner['andel_nevner']) {
                    $ownerInfo['Share'] = $owner['andel_teller'] . '/' . $owner['andel_nevner'];
                }

                // dato_fra can be null, a string, or an object with date property
                if ($owner['dato_fra'] ?? null) {
                    $datoFra = $owner['dato_fra'];
                    if (is_object($datoFra)) {
                        $datoFra = $datoFra->date ?? ($datoFra->toString ? $datoFra->toString() : json_encode(get_object_vars($datoFra)));
                    }
                    if (!empty($datoFra)) {
                        $ownerInfo['From'] = $datoFra;
                    }
                }

                // Display owner info
                $this->io->writeln(sprintf('  <info>Owner #%d:</>', $owner['eierforhold_id']));
                foreach ($ownerInfo as $key => $value) {
                    $this->io->writeln(sprintf('    <info>%-18s</> %s', $key, $value));
                }
            }
        }

        if (!empty($found->bruksenhetObjects)) {
            $this->io->text(sprintf(
                'Linked Bruksenheter: %d unit(s)',
                count($found->bruksenhetObjects)
            ));

            foreach ($found->bruksenhetObjects as $unit) {
                // Extract ID value if it's an object
                $unitId = $unit->id;
                if (is_object($unitId) && isset($unitId->value)) {
                    $unitId = $unitId->value;
                }
                
                // Try different property names for type - may be ID object
                $type = $unit->bruksenhettype ?? $unit->bruksenhetstypeId ?? $unit->bruksenhetstypeKodeId ?? null;
                if (is_object($type) && isset($type->value)) {
                    $type = $type->value;
                }
                
                $etasje = $unit->etasjenummer ?? $unit->etasjeplan ?? null;
                
                $this->io->text(sprintf(
                    '  - ID: %s, Type: %s, Etasje: %s',
                    $unitId ?? '?',
                    $type ?? '?',
                    $etasje ?? '?'
                ));
            }
        }
        $this->io->newLine();
    }
}
