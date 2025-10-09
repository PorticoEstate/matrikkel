<?php
/**
 * EierImportSingleModeService - On-demand import av personer og juridiske personer
 *
 * Strategi: Henter eiere én og én via StoreClient::getObject() i stedet for
 * getObjects(), fordi batch-endepunktet krever eksplisitt typehint for hver ID
 * (PersonId vs JuridiskPersonId). Ved å hente ett objekt av gangen kan vi først
 * anta PersonId og deretter auto-klassifisere responsen.
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\BubbleId;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\LocalDb\JuridiskPersonTable;
use Iaasen\Matrikkel\LocalDb\PersonTable;
use Laminas\Db\Adapter\Adapter;
use Symfony\Component\Console\Style\SymfonyStyle;

class EierImportSingleModeService
{
    private PersonTable $personTable;
    private JuridiskPersonTable $juridiskPersonTable;

    public function __construct(
        private readonly StoreClient $storeClient,
        private readonly Adapter $dbAdapter
    ) {
        $this->personTable = new PersonTable($dbAdapter);
        $this->juridiskPersonTable = new JuridiskPersonTable($dbAdapter);
    }

    /**
     * Importer eiere (personer og juridiske personer) for gitte kommuner.
     *
     * @param array<int>|null $kommunenummer Liste med kommunenummer, eller null for alle
     * @param SymfonyStyle     $io            Console IO helper
     * @param int              $flushInterval Hvor ofte vi flusher innlagte rader (default 100)
     *
     * @return array{personer:int,juridiske_personer:int,feilet:int,totalt:int}
     */
    public function importEiereForKommuner(?array $kommunenummer, SymfonyStyle $io, int $flushInterval = 100): array
    {
        $io->section('Henter eiere fra StoreService (single-object mode)');

        $io->text('Finner unike eier-IDer (inkludert ukjent type)...');
        $personIds = $this->findUniqueEierIds($kommunenummer, 'eier_person_id');

        $io->text('Finner eksplisitt juridiske eier-IDer...');
        $juridiskPersonIds = $this->findUniqueEierIds($kommunenummer, 'eier_juridisk_person_id');

        $io->text(sprintf('  Fant %d person-IDer og %d juridiske-person-IDer å hente',
            count($personIds),
            count($juridiskPersonIds)
        ));

        $stats = ['personer' => 0, 'juridiske_personer' => 0, 'feilet' => 0];

        if ($personIds !== []) {
            $io->text(sprintf('Henter og klassifiserer %d eiere (single-object mode)...', count($personIds)));
            $fetchStats = $this->fetchAndImportEiereSingleMode($personIds, $kommunenummer, $io, $flushInterval);
            $stats['personer'] += $fetchStats['personer'];
            $stats['juridiske_personer'] += $fetchStats['juridiske_personer'];
            $stats['feilet'] += $fetchStats['feilet'];
        }

        if ($juridiskPersonIds !== []) {
            $io->text(sprintf('Henter %d eksplisitt juridiske personer...', count($juridiskPersonIds)));
            $fetchStats = $this->fetchAndImportJuridiskePersonerSingleMode($juridiskPersonIds, $kommunenummer, $io, $flushInterval);
            $stats['juridiske_personer'] += $fetchStats['juridiske_personer'];
            $stats['feilet'] += $fetchStats['feilet'];
        }

        $this->personTable->flush();
        $this->juridiskPersonTable->flush();

        $io->success(sprintf(
            'Eier-import fullført: %d personer, %d juridiske personer (%d feilet)',
            $stats['personer'],
            $stats['juridiske_personer'],
            $stats['feilet']
        ));

        return [
            'personer' => $stats['personer'],
            'juridiske_personer' => $stats['juridiske_personer'],
            'feilet' => $stats['feilet'],
            'totalt' => $stats['personer'] + $stats['juridiske_personer'],
        ];
    }

    /**
     * @param array<int>|null $kommunenummer
     */
    private function findUniqueEierIds(?array $kommunenummer, string $columnName): array
    {
        $sql = "SELECT DISTINCT $columnName
                FROM matrikkel_matrikkelenheter
                WHERE $columnName IS NOT NULL";

        if ($kommunenummer) {
            $liste = implode(',', array_map('intval', $kommunenummer));
            $sql .= " AND kommunenummer IN ($liste)";
        }

        $sql .= " ORDER BY $columnName";

        $result = $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) $row[$columnName];
        }

        return $ids;
    }

    /**
     * @param int[]           $eierIds
     * @param array<int>|null $kommunenummer
     *
     * @return array{personer:int,juridiske_personer:int,feilet:int}
     */
    private function fetchAndImportEiereSingleMode(array $eierIds, ?array $kommunenummer, SymfonyStyle $io, int $flushInterval): array
    {
        $stats = ['personer' => 0, 'juridiske_personer' => 0, 'feilet' => 0];
        $context = $this->buildMatrikkelContext();
        $totalIds = count($eierIds);
        $processed = 0;

        $io->progressStart($totalIds);

        foreach ($eierIds as $eierId) {
            $processed++;

            try {
                $response = $this->storeClient->getObject([
                    'id' => BubbleId::getId((string) $eierId, 'PersonId'),
                    'matrikkelContext' => $context,
                ]);

                $object = $this->normalizeEierPayload($response->return ?? null);
                if (!$object) {
                    $stats['feilet']++;
                    $io->progressAdvance();
                    continue;
                }

                $type = $this->determineEierType($object);
                $className = get_class($object);

                if ($stats['personer'] + $stats['juridiske_personer'] < 3) {
                    $io->note(sprintf(
                        'Hentet objekt med klasse: %s (ID: %d) - klassifisert som %s',
                        $className,
                        $eierId,
                        $type
                    ));
                }

                if ($type === 'juridisk_person') {
                    $this->juridiskPersonTable->insertRow($object);
                    $stats['juridiske_personer']++;
                    $this->updateMatrikkelenhetEierType($eierId, 'juridisk_person', $kommunenummer);
                } elseif ($type === 'person') {
                    $this->personTable->insertRow($object);
                    $stats['personer']++;
                    $this->updateMatrikkelenhetEierType($eierId, 'person', $kommunenummer);
                } else {
                    $stats['feilet']++;
                    if ($stats['feilet'] <= 3) {
                        $io->warning(sprintf('Kunne ikke klassifisere eier %d (klasse %s)', $eierId, $className));
                        $io->writeln($this->formatDebugPayload($object));
                    }
                }

                if ($processed % $flushInterval === 0) {
                    $this->personTable->flush();
                    $this->juridiskPersonTable->flush();
                }
            } catch (\SoapFault $e) {
                $stats['feilet']++;
                if ($stats['feilet'] <= 3) {
                    $io->warning("Feil ved henting av eier $eierId: " . $e->getMessage());
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        return $stats;
    }

    /**
     * @param int[]           $juridiskPersonIds
     * @param array<int>|null $kommunenummer
     *
     * @return array{juridiske_personer:int,feilet:int}
     */
    private function fetchAndImportJuridiskePersonerSingleMode(array $juridiskPersonIds, ?array $kommunenummer, SymfonyStyle $io, int $flushInterval): array
    {
        $stats = ['juridiske_personer' => 0, 'feilet' => 0];
        $context = $this->buildMatrikkelContext();
        $totalIds = count($juridiskPersonIds);
        $processed = 0;

        $io->progressStart($totalIds);

        foreach ($juridiskPersonIds as $juridiskPersonId) {
            $processed++;

            try {
                $response = $this->storeClient->getObject([
                    'id' => BubbleId::getId((string) $juridiskPersonId, 'JuridiskPersonId'),
                    'matrikkelContext' => $context,
                ]);

                $object = $this->normalizeEierPayload($response->return ?? null);
                if (!$object) {
                    $stats['feilet']++;
                    $io->progressAdvance();
                    continue;
                }

                $type = $this->determineEierType($object);

                if ($type === 'juridisk_person') {
                    $this->juridiskPersonTable->insertRow($object);
                    $stats['juridiske_personer']++;
                } else {
                    $stats['feilet']++;
                    if ($stats['feilet'] <= 3) {
                        $io->warning(sprintf('Kunne ikke klassifisere eksplisitt juridisk eier %d', $juridiskPersonId));
                    }
                    $io->progressAdvance();
                    continue;
                }

                $this->updateMatrikkelenhetEierType($juridiskPersonId, 'juridisk_person', $kommunenummer);

                if ($processed % $flushInterval === 0) {
                    $this->juridiskPersonTable->flush();
                }
            } catch (\SoapFault $e) {
                $stats['feilet']++;
                if ($stats['feilet'] <= 3) {
                    $io->warning("Feil ved henting av juridisk person $juridiskPersonId: " . $e->getMessage());
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        return $stats;
    }

    private function updateMatrikkelenhetEierType(int $eierId, string $type, ?array $kommunenummer): void
    {
        $kommuneSql = '';
        if ($kommunenummer) {
            $liste = implode(',', array_map('intval', $kommunenummer));
            $kommuneSql = " AND kommunenummer IN ($liste)";
        }

        if ($type === 'juridisk_person') {
            $sql = "UPDATE matrikkel_matrikkelenheter
                    SET eier_type = 'juridisk_person',
                        eier_juridisk_person_id = $eierId,
                        eier_person_id = NULL
                    WHERE eier_person_id = $eierId$kommuneSql";

            $sql2 = "UPDATE matrikkel_matrikkelenheter
                     SET eier_type = 'juridisk_person'
                     WHERE eier_juridisk_person_id = $eierId$kommuneSql";

            $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
            $this->dbAdapter->query($sql2, Adapter::QUERY_MODE_EXECUTE);
        } elseif ($type === 'person') {
            $sql = "UPDATE matrikkel_matrikkelenheter
                    SET eier_type = 'person'
                    WHERE eier_person_id = $eierId
                    AND eier_type = 'ukjent'$kommuneSql";

            $this->dbAdapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }
    }

    private function buildMatrikkelContext(): array
    {
        return [
            'locale' => [
                'language' => 'no',
                'country' => 'NO',
            ],
            'koordinatsystemKodeId' => 84,
        ];
    }


    private function normalizeEierPayload(mixed $payload): ?object
    {
        if ($payload === null) {
            return null;
        }

        if (is_object($payload)) {
            if ($this->looksLikeEierObject($payload)) {
                return $payload;
            }

            foreach (['value', 'item', 'entry', 'return', 'person', 'juridiskPerson', 'content'] as $property) {
                if (isset($payload->{$property})) {
                    $candidate = $this->normalizeEierPayload($payload->{$property});
                    if ($candidate) {
                        return $candidate;
                    }
                }
            }

            return $payload;
        }

        if (is_array($payload)) {
            foreach ($payload as $item) {
                $candidate = $this->normalizeEierPayload($item);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }


    private function looksLikeEierObject(object $object): bool
    {
    $properties = array_map('strtolower', array_keys(get_object_vars($object)));

        if (isset($object->id) && is_object($object->id) && property_exists($object->id, 'value')) {
            return true;
        }

        $personIndicators = ['fornavn', 'etternavn', 'mellomnavn', 'fodselsnummer'];
        if (array_intersect($personIndicators, $properties) !== []) {
            return true;
        }

        $juridiskIndicators = ['organisasjonsnavn', 'organisasjonsnummer', 'organisasjonsform', 'juridiskpersontype'];
        if (array_intersect($juridiskIndicators, $properties) !== []) {
            return true;
        }

        if (isset($object->{'@type'})) {
            $type = strtolower((string) $object->{'@type'});
            return str_contains($type, 'person');
        }

        return false;
    }


    private function determineEierType(object $object): string
    {
        $className = get_class($object);
        if ($className !== 'stdClass') {
            if (stripos($className, 'JuridiskPerson') !== false) {
                return 'juridisk_person';
            }
            if (stripos($className, 'Person') !== false) {
                return 'person';
            }
        }

    $props = array_map('strtolower', array_keys(get_object_vars($object)));

        if (isset($object->{'@type'})) {
            $type = strtolower((string) $object->{'@type'});
            if (str_contains($type, 'juridiskperson')) {
                return 'juridisk_person';
            }
            if (str_contains($type, 'person')) {
                return 'person';
            }
        }

        if (isset($object->juridiskPerson) && is_object($object->juridiskPerson)) {
            return $this->determineEierType($object->juridiskPerson);
        }

        if (isset($object->person) && is_object($object->person)) {
            return $this->determineEierType($object->person);
        }

        if (isset($object->organisasjonsformKode)) {
            return 'juridisk_person';
        }

        $juridiskIndicators = [
            'organisasjonsnavn',
            'organisasjonsnummer',
            'organisasjonsform',
            'juridiskpersontype',
            'navn',
            'nummer',
        ];
        if (array_intersect($juridiskIndicators, $props) !== []) {
            return 'juridisk_person';
        }

        $personIndicators = ['fornavn', 'etternavn', 'mellomnavn', 'fodselsnummer', 'kjoenn'];
        if (array_intersect($personIndicators, $props) !== []) {
            return 'person';
        }

        return 'ukjent';
    }


    private function formatDebugPayload(object $object): string
    {
        $encoded = json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '  Payload kunne ikke serialiseres for debug.';
        }

        $lines = explode("\n", $encoded);
    $preview = array_slice($lines, 0, 80);
    $truncated = count($lines) > 80 ? "\n  ... (trunkert)" : '';

        return '  Payload: ' . implode("\n  ", $preview) . $truncated;
    }
}
