<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\AdresseClient;
use Iaasen\Matrikkel\Client\StoreClient;
use Iaasen\Matrikkel\Client\KommuneId;
use Iaasen\Matrikkel\Client\AdresseId;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\BruksenhetId;
use Iaasen\Matrikkel\Client\PersonId;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

/**
 * AddressLookupService - Find and fetch address data by street components
 * 
 * Implements address-based lookup workflow:
 * 1. Find Veg by street name via findVegerMedNavn() (handles disambiguation)
 * 2. Get specific vegadresse via findVegadresse() with husnummer + bokstav
 * 3. Fetch full address object via StoreClient.getObjects()
 * 4. Optionally fetch linked matrikkelenheter and bruksenheter
 * 
 * Supports interactive user prompts for street name disambiguation when
 * multiple streets with the same name exist in a kommune.
 * 
 * @author GitHub Copilot
 * @date 2026-01-12
 */
class AddressLookupService
{
    public function __construct(
        private AdresseClient $adresseClient,
        private StoreClient $storeClient,
        private PDO $db
    ) {}



    /**
     * Find and fetch address data by street components with disambiguation support
     * 
     * @param string $gatenavn Street name (e.g., "Osloveien")
     * @param int $husnummer House number (e.g., 42)
     * @param ?string $bokstav House letter suffix (e.g., "A"), or null
     * @param int $kommunenummer Municipality number (e.g., 4627)
     * @param ?SymfonyStyle $io Console I/O for user prompts; if null, uses first match
     * @param bool $fetchRelated Fetch matrikkelenhet and bruksenheter if found
     * 
     * @return ?FoundAddress Full address data, or null if not found
     */
    public function findByStreetAddress(
        string $gatenavn,
        int $husnummer,
        ?string $bokstav,
        int $kommunenummer,
        ?SymfonyStyle $io = null,
        bool $fetchRelated = true
    ): ?FoundAddress {
        // Step 1: Find veg(er) by street name
        $vegs = $this->findVegsWithDisambiguation($gatenavn, $kommunenummer, $io);
        
        if (empty($vegs)) {
            if ($io) {
                $io->warning("Gate '$gatenavn' ikke funnet i kommune $kommunenummer");
            }
            return null;
        }

        // Step 2: Try each veg to find the specific vegadresse
        foreach ($vegs as $veg) {
            $vegadresseId = $this->findVegadresseId(
                $kommunenummer,
                (int) $veg->adressekode,
                $husnummer,
                $bokstav
            );

            if ($vegadresseId) {
                // Step 3: Fetch full vegadresse object
                $vegadresseObj = $this->storeClient->getObjects([
                    new AdresseId((int) $vegadresseId)
                ]);

                if (empty($vegadresseObj)) {
                    continue;
                }

                $vegadresseObj = reset($vegadresseObj); // Get first result

                // Step 4: Optionally fetch related objects
                $matrikkelObj = null;
                $bruksenhetObjs = [];
                $owners = [];

                if ($fetchRelated && isset($vegadresseObj->matrikkelenhetId)) {
                    $matrikkelenhetIdValue = is_object($vegadresseObj->matrikkelenhetId)
                        ? (int) $vegadresseObj->matrikkelenhetId->value
                        : (int) $vegadresseObj->matrikkelenhetId;
                    $matrikkelObj = $this->fetchMatrikkelenhet($matrikkelenhetIdValue);
                    $owners = $this->extractOwnersFromMatrikkelenhet($matrikkelObj);
                    $bruksenhetObjs = $this->fetchBruksenheterForAdresse((int) $vegadresseId);
                }

                return new FoundAddress(
                    vegadresseId: (int) $vegadresseId,
                    gatenavn: $gatenavn,
                    husnummer: $husnummer,
                    bokstav: $bokstav,
                    adressekode: (int) $veg->adressekode,
                    vegId: (int) $veg->id->value,
                    vegadresseObject: $vegadresseObj,
                    matrikkelObject: $matrikkelObj,
                    bruksenhetObjects: $bruksenhetObjs,
                    owners: $owners
                );
            }
        }

        if ($io) {
            $io->warning(
                sprintf(
                    "Adresse '%s %d%s' ikke funnet i kommune %d",
                    $gatenavn,
                    $husnummer,
                    $bokstav ? " $bokstav" : "",
                    $kommunenummer
                )
            );
        }

        return null;
    }

    /**
     * Find veg(er) by street name with user disambiguation
     * 
     * When multiple streets with the same name exist, prompts user to choose.
     * If $io is null, silently returns first match.
     * 
     * @param string $gatenavn Street name
     * @param int $kommunenummer Municipality number
     * @param ?SymfonyStyle $io Console I/O for prompts
     * 
     * @return array List of matching Veg objects (may be single item)
     */
    private function findVegsWithDisambiguation(
        string $gatenavn,
        int $kommunenummer,
        ?SymfonyStyle $io
    ): array {
        try {
            // Step 1: Find veg IDs by street name
            $result = $this->adresseClient->findVegerMedNavn([
                'kommuneId' => new KommuneId($kommunenummer),
                'adressekode' => 0, // 0 = all streets
                'adressenavn' => $gatenavn,
                'adressenavnFonetisk' => false
            ]);
            
            // Extract VegId objects from response
            $vegIds = [];
            if (isset($result->return)) {
                if (isset($result->return->item)) {
                    $vegIds = is_array($result->return->item) 
                        ? $result->return->item 
                        : [$result->return->item];
                } elseif (is_array($result->return)) {
                    $vegIds = $result->return;
                }
            }

            if (empty($vegIds)) {
                return [];
            }

            // Step 2: Fetch full Veg objects from StoreService
            $vegObjects = $this->storeClient->getObjects($vegIds);
            
            if (empty($vegObjects)) {
                return [];
            }

        } catch (\Exception $e) {
            if ($io) {
                $io->error("Feil ved søk i SOAP API: " . $e->getMessage());
            }
            return [];
        }

        // If only one match, return it
        if (count($vegObjects) === 1) {
            return $vegObjects;
        }

        // If multiple matches and no I/O, return first match
        if (!$io) {
            return [reset($vegObjects)];
        }

        // Multiple matches: prompt user to choose
        return $this->promptForVegSelection($vegObjects, $gatenavn, $io);
    }

    /**
     * Prompt user to select from multiple streets with same name
     * 
     * Displays a numbered list and asks user to choose.
     * 
     * @param array $vegs List of Veg objects
     * @param string $gatenavn Street name being searched
     * @param SymfonyStyle $io Console I/O
     * 
     * @return array Single-element array with selected Veg, or empty array if cancelled
     */
    private function promptForVegSelection(array $vegs, string $gatenavn, SymfonyStyle $io): array
    {
        $io->warning(
            sprintf("Funnet %d gater med navn '%s'", count($vegs), $gatenavn)
        );

        // Build display table
        $tableRows = [];
        foreach ($vegs as $index => $veg) {
            $tableRows[] = [
                $index + 1,
                (int) $veg->id->value,
                (int) $veg->adressekode,
                $veg->kortAdressenavn ?? "—",
                $veg->stedsnummer ?? "—"
            ];
        }

        $io->table(
            ["#", "Veg ID", "Adressekode", "Kortnavn", "Stedsnummer"],
            $tableRows
        );

        // Prompt for selection
        $choice = $io->ask(
            "Velg gate (nummer 1-" . count($vegs) . ", eller 'q' for å avbryte)",
            null,
            function ($input) use ($vegs) {
                if ($input === 'q' || $input === 'Q') {
                    throw new \Exception("Brukeren avbrøt");
                }
                $num = (int) $input;
                if ($num < 1 || $num > count($vegs)) {
                    throw new \RuntimeException("Ugyldig valg");
                }
                return $input;
            }
        );

        $selectedIndex = (int) $choice - 1;
        return [array_values($vegs)[$selectedIndex]];
    }

    /**
     * Find vegadresse ID by address code, house number, and letter
     * 
     * @param int $kommunenummer Municipality number
     * @param int $adressekode Address code (from Veg object)
     * @param int $husnummer House number
     * @param ?string $bokstav House letter, or null
     * 
     * @return ?int Vegadresse ID, or null if not found
     */
    private function findVegadresseId(
        int $kommunenummer,
        int $adressekode,
        int $husnummer,
        ?string $bokstav
    ): ?int {
        try {
            // Call SOAP API with array parameter
            $result = $this->adresseClient->findVegadresse([
                'kommuneId' => new KommuneId($kommunenummer),
                'adressekode' => $adressekode,
                'nummer' => $husnummer,
                'bokstav' => $bokstav
            ]);

            // Response is typically: result->return (single VegadresseId or null)
            if (isset($result->return) && isset($result->return->value)) {
                return (int) $result->return->value;
            }

            return null;
        } catch (\Exception $e) {
            // Address not found or API error
            return null;
        }
    }

    /**
     * Fetch matrikkelenhet object if vegadresse references one
     * 
     * @param int $matrikkelenhetId Matrikkelenhet ID
     * 
     * @return ?object Matrikkelenhet object, or null if fetch fails
     */
    private function fetchMatrikkelenhet(int $matrikkelenhetId): ?object
    {
        try {
            $results = $this->storeClient->getObjects([
                new MatrikkelenhetId($matrikkelenhetId)
            ]);
            return !empty($results) ? reset($results) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Fetch bruksenheter linked to an address
     * 
     * @param int $adresseId Address ID
     * 
     * @return array List of bruksenhet objects
     */
    private function fetchBruksenheterForAdresse(int $adresseId): array
    {
        try {
            // Query local DB for bruksenheter with this address_id
            $stmt = $this->db->prepare(
                "SELECT bruksenhet_id FROM matrikkel_bruksenheter WHERE adresse_id = ?"
            );
            $stmt->execute([$adresseId]);
            $bruksenhetIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($bruksenhetIds)) {
                return [];
            }

            // Fetch full objects from SOAP API
            $idObjects = array_map(
                fn($id) => new BruksenhetId((int) $id),
                $bruksenhetIds
            );

            return $this->storeClient->getObjects($idObjects);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch owners (tinglyst eier) for a matrikkelenhet via SOAP (StoreService)
     */
    private function extractOwnersFromMatrikkelenhet(?object $matrikkelObj): array
    {
        if (!$matrikkelObj || !isset($matrikkelObj->eierforhold)) {
            return [];
        }

        $wrapper = $matrikkelObj->eierforhold;
        if (!isset($wrapper->item)) {
            return [];
        }

        $items = is_array($wrapper->item) ? $wrapper->item : [$wrapper->item];

        $ownerIdObjects = [];
        $ownerMeta = [];

        foreach ($items as $item) {
            // Filter to tinglyste eierforhold when flag exists; include otherwise
            $isTinglyst = $item->tinglyst ?? $item->erTinglyst ?? null;
            if ($isTinglyst === false) {
                continue;
            }

            $eierIdObj = $item->eierId ?? null;
            $idValue = (is_object($eierIdObj) && isset($eierIdObj->value)) ? (int) $eierIdObj->value : null;
            if (!$idValue) {
                continue;
            }

            $ownerIdObjects[$idValue] = new PersonId($idValue);
            $ownerMeta[$idValue] = [
                'eierforhold_id' => $item->id ?? null,
                'andel_teller' => $item->andelTeller ?? $item->andel_teller ?? null,
                'andel_nevner' => $item->andelNevner ?? $item->andel_nevner ?? null,
                'eierforhold_type' => $item->eierforholdType ?? $item->eierforhold_type ?? null,
                'dato_fra' => $item->datoFra ?? $item->dato_fra ?? null,
            ];
        }

        if (empty($ownerIdObjects)) {
            return [];
        }

        // Fetch full owner objects from StoreService
        $persons = $this->storeClient->getObjects(array_values($ownerIdObjects));
        $personById = [];
        foreach ($persons as $person) {
            $pid = $person->id->value ?? null;
            if ($pid) {
                $personById[(int) $pid] = $person;
            }
        }

        $owners = [];
        foreach ($ownerIdObjects as $idValue => $idObj) {
            $person = $personById[$idValue] ?? null;
            $meta = $ownerMeta[$idValue] ?? [];

            $isJuridisk = $person && (isset($person->organisasjonsformKode) || isset($person->organisasjonsnummer));
            if ($isJuridisk) {
                $ownerName = $person->navn ?? ($person->organisasjonsnavn ?? 'Ukjent juridisk person');
                $identifier = $person->nummer ?? $person->organisasjonsnummer ?? null;
                $ownerType = 'Juridisk person';
            } else {
                $fornavn = $person->fornavn ?? null;
                $etternavn = $person->etternavn ?? null;
                $ownerName = $person->navn ?? trim(($fornavn ?? '') . ' ' . ($etternavn ?? '')) ?: 'Ukjent person';
                $identifier = $person->nummer ?? null;
                $ownerType = 'Fysisk person';
            }

            $andelTeller = $meta['andel_teller'] ?? null;
            $andelNevner = $meta['andel_nevner'] ?? null;
            $share = null;
            if ($andelTeller && $andelNevner) {
                $share = round(($andelTeller / $andelNevner) * 100, 2);
            }

            $owners[] = [
                'eierforhold_id' => $meta['eierforhold_id'] ?? $idValue,
                'owner_name' => $ownerName,
                'owner_type' => $ownerType,
                'eierforhold_type' => $meta['eierforhold_type'] ?? 'Eier',
                'andel_teller' => $andelTeller,
                'andel_nevner' => $andelNevner,
                'share_percent' => $share,
                'dato_fra' => $meta['dato_fra'] ?? null,
                'tinglyst' => true,
                'identifikator' => $identifier,
            ];
        }

        return $owners;
    }
}
