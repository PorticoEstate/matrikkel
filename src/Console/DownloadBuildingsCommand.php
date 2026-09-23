<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\AdresseClient;
use Iaasen\Matrikkel\Client\AdresseId;
use Iaasen\Matrikkel\Client\BygningClient;
use Iaasen\Matrikkel\Client\BygningId;
use Iaasen\Matrikkel\Client\StoreClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:download-buildings',
    description: 'Last ned komplette byggobjekter og adresser for angitte bygningsnumre'
)]
class DownloadBuildingsCommand extends Command
{
    public function __construct(
        private AdresseClient $adresseClient,
        private BygningClient $bygningClient,
        private StoreClient $storeClient,
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
                'Forventet kommunenummer, for eksempel 4601'
            )
            ->addOption(
                'bygningsnummer',
                'b',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Bygningsnummer som skal lastes ned; kan gjentas',
                []
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'JSON-fil for komplette byggobjekter',
                'var/buildings.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kommune = (int) $input->getOption('kommune');
        $numbers = $this->parseBuildingNumbers($input->getOption('bygningsnummer'));
        $outputPath = (string) $input->getOption('output');

        if ($kommune < 1 || $kommune > 9999) {
            $io->error('--kommune må være et gyldig kommunenummer.');
            return Command::FAILURE;
        }

        if ($numbers === []) {
            $io->error('Minst ett --bygningsnummer må oppgis.');
            return Command::FAILURE;
        }

        try {
            $ids = [];
            foreach ($numbers as $number) {
                $response = $this->bygningClient->findBygning([
                    'bygningsnr' => $number,
                ]);
                $buildingId = $response->return ?? null;

                if (!$buildingId || !isset($buildingId->value)) {
                    $io->warning("Fant ikke bygningsnummer $number.");
                    continue;
                }

                $ids[$number] = (int) $buildingId->value;
            }

            if ($ids === []) {
                $io->error('Ingen av bygningsnumrene ble funnet.');
                return Command::FAILURE;
            }

            $objects = $this->storeClient->getObjects(
                array_map(static fn (int $id): BygningId => new BygningId($id), array_values($ids))
            );

            $buildings = [];
            foreach ($objects as $building) {
                $buildingNumber = isset($building->bygningsnummer)
                    ? (int) $building->bygningsnummer
                    : null;
                $buildingMunicipality = isset($building->kommuneId->value)
                    ? (int) $building->kommuneId->value
                    : null;

                if ($buildingMunicipality !== $kommune) {
                    $io->warning(sprintf(
                        'Bygningsnummer %s ble funnet i kommune %s, ikke %s, og ble utelatt.',
                        $buildingNumber ?? 'ukjent',
                        $buildingMunicipality ?? 'ukjent',
                        $kommune
                    ));
                    continue;
                }

                $buildings[] = $building;
            }

            $addressesByBuildingId = $this->loadAddresses($buildings);
            $addressIds = [];
            foreach ($addressesByBuildingId as $buildingId => $idsForBuilding) {
                foreach ($idsForBuilding as $addressId) {
                    $addressIds[$addressId] = true;
                }
            }

            $addresses = $this->storeClient->getObjects(
                array_map(static fn (int $id): AdresseId => new AdresseId($id), array_keys($addressIds))
            );
            $addressById = [];
            foreach ($addresses as $address) {
                if (isset($address->id->value)) {
                    $addressById[(int) $address->id->value] = $address;
                }
            }

            foreach ($buildings as $building) {
                $buildingId = isset($building->id->value) ? (int) $building->id->value : null;
                $building->adresser = [];
                foreach ($addressesByBuildingId[$buildingId] ?? [] as $addressId) {
                    if (isset($addressById[$addressId])) {
                        $building->adresser[] = $addressById[$addressId];
                    }
                }
            }

            $directory = dirname($outputPath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException("Kunne ikke opprette katalogen: $directory");
            }

            $json = json_encode($buildings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
                throw new \RuntimeException("Kunne ikke skrive filen: $outputPath");
            }

            $io->success(sprintf(
                'Lastet ned %d av %d bygg til %s.',
                count($buildings),
                count($numbers),
                $outputPath
            ));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
    }

    /** @param array<int, object> $buildings @return array<int, array<int, int>> */
    private function loadAddresses(array $buildings): array
    {
        if ($buildings === []) {
            return [];
        }

        $buildingIds = [];
        foreach ($buildings as $building) {
            if (isset($building->id->value)) {
                $buildingIds[] = new BygningId((int) $building->id->value);
            }
        }

        $response = $this->adresseClient->findAdresserForByggList([
            'byggIds' => ['item' => $buildingIds],
        ]);
        $entries = $response->return->entry ?? [];
        if (!is_array($entries)) {
            $entries = [$entries];
        }

        $addressesByBuildingId = [];
        foreach ($entries as $entry) {
            if (!isset($entry->key->value, $entry->value->item)) {
                continue;
            }

            $addressIds = is_array($entry->value->item)
                ? $entry->value->item
                : [$entry->value->item];
            foreach ($addressIds as $addressId) {
                if (isset($addressId->value)) {
                    $addressesByBuildingId[(int) $entry->key->value][] = (int) $addressId->value;
                }
            }
        }

        return $addressesByBuildingId;
    }

    /** @param array<int, string> $values @return array<int, int> */
    private function parseBuildingNumbers(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                    $numbers[] = (int) $part;
                }
            }
        }

        return array_values(array_unique($numbers));
    }
}
