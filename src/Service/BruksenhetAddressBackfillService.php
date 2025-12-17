<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\LocalDb\AdresseRepository;
use Iaasen\Matrikkel\LocalDb\BruksenhetRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill service to assign adresse_id to bruksenheter that are missing it,
 * by looking up addresses attached to the same matrikkelenhet.
 *
 * Strategy:
 * 1. PRIMARY: Propagate from other units in same matrikkelenhet (property)
 *    - If ANY unit on the property has an address, assign it to ALL other units on that property
 *    - This fixes cases like: property has 3 units, 1 has address, 2 don't
 *
 * 2. FALLBACK: Infer from other units in same building
 *    - If units on same building have addresses, use them
 *    - Deterministic pick: by gatenavn ASC, husnummer ASC, bokstav ASC
 *
 * 3. SKIP: If no addresses found via both strategies, mark as skipped
 *
 * Note: After backfill, run organize-hierarchy to generate innganger.
 */
class BruksenhetAddressBackfillService
{
    public function __construct(
        private BruksenhetRepository $bruksenhetRepository,
        private AdresseRepository $adresseRepository,
    ) {}

    /**
     * Backfill missing addresses for bruksenheter within a kommune
     *
     * @return array{processed:int,updated:int,skipped:int,errors:int}
     */
    public function backfillByKommunenummer(int $kommunenummer, ?SymfonyStyle $io = null): array
    {
        $missing = $this->bruksenhetRepository->findMissingAddressByKommune($kommunenummer, 100000);
        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        if ($io) {
            $io->info(sprintf('Fant %d bruksenheter uten adresse i kommune %d', count($missing), $kommunenummer));
        }

        foreach ($missing as $br) {
            $processed++;
            $bruksenhetId = (int) $br['bruksenhet_id'];
            $matrikkelenhetId = (int) $br['matrikkelenhet_id'];

            try {
                $addresses = $this->adresseRepository->findByMatrikkelenhetId($matrikkelenhetId);

                // Strategy 1: Propagate from other units in same matrikkelenhet
                // If any unit in the property has an address, use it for all others in that property
                if (empty($addresses)) {
                    $others = $this->bruksenhetRepository->findByMatrikkelenhetId($matrikkelenhetId);
                    $addrMap = [];
                    foreach ($others as $o) {
                        $aid = $o['adresse_id'] ?? null;
                        if ($aid) {
                            $key = (int)$aid;
                            if (!isset($addrMap[$key])) {
                                $addrMap[$key] = [
                                    'adresse_id' => (int)$aid,
                                    'adressetype' => null, // Will be looked up if needed
                                    'adressenavn' => null,
                                    'nummer' => null,
                                    'bokstav' => null,
                                ];
                            }
                        }
                    }
                    $addresses = array_values($addrMap);
                }

                // Strategy 2: Fallback to other units in same building
                if (empty($addresses)) {
                    $bygningId = isset($br['bygning_id']) ? (int)$br['bygning_id'] : null;
                    if ($bygningId) {
                        $others = $this->bruksenhetRepository->findByBygningIdWithAdresse($bygningId);
                        $addrMap = [];
                        foreach ($others as $o) {
                            $aid = $o['adresse_id'] ?? null;
                            if ($aid) {
                                $key = (int)$aid;
                                if (!isset($addrMap[$key])) {
                                    $addrMap[$key] = [
                                        'adresse_id' => (int)$aid,
                                        'adressenavn' => $o['adressenavn'] ?? null,
                                        'nummer' => $o['husnummer'] ?? null,
                                        'bokstav' => $o['bokstav'] ?? null,
                                    ];
                                }
                            }
                        }
                        $addresses = array_values($addrMap);
                    }
                }

                if (empty($addresses)) {
                    $skipped++;
                    if ($io) {
                        $io->comment(sprintf('Ingen adresser for matrikkelenhet %d (bruksenhet %d)', $matrikkelenhetId, $bruksenhetId));
                    }
                    continue;
                }

                // Prefer VEGADRESSE
                $veg = array_filter($addresses, fn(array $a) => ($a['adressetype'] ?? null) === 'VEGADRESSE');
                $candidates = !empty($veg) ? array_values($veg) : $addresses;

                // Deterministic sort: gatenavn, husnummer, bokstav
                usort($candidates, function(array $a, array $b) {
                    $an = $a['adressenavn'] ?? '';
                    $bn = $b['adressenavn'] ?? '';
                    if ($an !== $bn) {
                        return strcmp($an, $bn);
                    }
                    $ah = $a['nummer'] ?? PHP_INT_MAX; // NULL last
                    $bh = $b['nummer'] ?? PHP_INT_MAX;
                    if ($ah !== $bh) {
                        return $ah <=> $bh;
                    }
                    $ab = $a['bokstav'] ?? '';
                    $bb = $b['bokstav'] ?? '';
                    return strcmp($ab, $bb);
                });

                $selected = $candidates[0];
                $adresseId = (int) $selected['adresse_id'];
                $this->bruksenhetRepository->updateAdresseReference($bruksenhetId, $adresseId);
                $updated++;
            } catch (\Throwable $e) {
                $errors++;
                if ($io) {
                    $io->warning(sprintf('Feil for bruksenhet %d: %s', $bruksenhetId, $e->getMessage()));
                }
            }
        }

        return [
            'processed' => $processed,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
