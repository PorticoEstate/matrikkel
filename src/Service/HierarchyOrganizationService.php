<?php

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\LocalDb\AdresseRepository;
use Iaasen\Matrikkel\LocalDb\BruksenhetRepository;
use Iaasen\Matrikkel\LocalDb\BygningRepository;
use Iaasen\Matrikkel\LocalDb\InngangRepository;
use Iaasen\Matrikkel\LocalDb\MatrikkelenhetRepository;
use Iaasen\Matrikkel\LocalDb\VegRepository;

/**
 * Builds and stores Portico location codes for Eiendom → Bygg → Inngang → Bruksenhet
 */
class HierarchyOrganizationService
{
    public function __construct(
        private MatrikkelenhetRepository $matrikkelenhetRepository,
        private BygningRepository $bygningRepository,
        private InngangRepository $inngangRepository,
        private BruksenhetRepository $bruksenhetRepository,
        private AdresseRepository $adresseRepository,
        private VegRepository $vegRepository
    ) {
    }

    /**
     * Organize a single matrikkelenhet (eiendom) with building numbering
     */
    public function organizeEiendom(int $matrikkelenhetId): void
    {
        $eiendom = $this->matrikkelenhetRepository->findById($matrikkelenhetId);
        if (!$eiendom) {
            throw new \InvalidArgumentException("Fant ikke matrikkelenhet {$matrikkelenhetId}");
        }

        $eiendomKode = $eiendom['lokasjonskode_eiendom'] ?? $this->generateEiendomKode($eiendom);
        $this->matrikkelenhetRepository->updateLokasjonskode($matrikkelenhetId, $eiendomKode);

        $bygninger = $this->bygningRepository->getBygningerForEiendom($matrikkelenhetId);
        
        // Group buildings by matrikkel_bygning_nummer to ensure duplicates get same sequence
        $bygningGroups = [];
        foreach ($bygninger as $bygning) {
            $mbn = $bygning['matrikkel_bygning_nummer'] ?? null;
            // If no building number, treat as unique by bygning_id
            $key = $mbn ?: ('id_' . $bygning['bygning_id']);
            
            if (!isset($bygningGroups[$key])) {
                $bygningGroups[$key] = [];
            }
            $bygningGroups[$key][] = $bygning;
        }
        
        // Assign same sequence to all buildings with same matrikkel_bygning_nummer
        $seq = 1;
        foreach ($bygningGroups as $group) {
            $byggKode = $this->formatByggKode($eiendomKode, $seq);
            
            // Collect all bygning_ids in this group for entrance organization
            $bygningIds = [];
            foreach ($group as $bygning) {
                $bygningId = (int) $bygning['bygning_id'];
                $bygningIds[] = $bygningId;
                
                $this->bygningRepository->updateLopenummerIEiendom($bygningId, $seq);
                $this->bygningRepository->updateLokasjonskode($bygningId, $byggKode);
            }
            
            // Organize entrances for all buildings in group together
            $matrikkelBygningNummer = $group[0]['matrikkel_bygning_nummer'] ?? null;
            $kommunenummer = $group[0]['kommunenummer'] ?? ($eiendom['kommunenummer'] ?? null);

            $this->organizeBygningGroup($bygningIds, $byggKode, $matrikkelBygningNummer, $kommunenummer);
            $seq++;
        }
    }

    /**
     * Organize entrances and units for a group of buildings (with same matrikkel_bygning_nummer)
     */
    public function organizeBygningGroup(array $bygningIds, string $byggKode, ?int $matrikkelBygningNummer, ?int $kommunenummer): void
    {
        if (empty($bygningIds)) {
            return;
        }
        
        // Collect all bruksenheter from all buildings in group
        $allBruksenheter = [];
        foreach ($bygningIds as $bygningId) {
            $bruksenheter = $this->bruksenhetRepository->findByBygningIdWithAdresse((int) $bygningId);
            $allBruksenheter = array_merge($allBruksenheter, $bruksenheter);
        }
        
        if (empty($allBruksenheter)) {
            return;
        }

        // Group by entrance key: (veg_id, husnummer, bokstav)
        $groups = [];
        $unassigned = [];
        foreach ($allBruksenheter as $br) {
            $husnummer = $br['husnummer'] ?? null;
            if ($husnummer === null) {
                // Try to resolve address from matrikkelenhet if bruksenhet has no address
                $matrikkelenhetId = $br['matrikkelenhet_id'] ?? null;
                if ($matrikkelenhetId) {
                    $matrikkelAdresse = $this->resolveMatrikkelenhetAddress((int) $matrikkelenhetId);
                    if ($matrikkelAdresse) {
                        $husnummer = $matrikkelAdresse['husnummer'];
                        $br['veg_id'] = $matrikkelAdresse['veg_id'];
                        $br['husnummer'] = $matrikkelAdresse['husnummer'];
                        $br['bokstav'] = $matrikkelAdresse['bokstav'];
                        $br['adressenavn'] = $matrikkelAdresse['adressenavn'];
                        $br['kort_adressenavn'] = $matrikkelAdresse['kort_adressenavn'];
                    }
                }
            }
            
            if ($husnummer === null) {
                $unassigned[] = $br;
                continue;
            }
            
            $key = sprintf('%s|%s|%s', $br['veg_id'] ?? 'null', $husnummer, $br['bokstav'] ?? '');
            $groups[$key]['meta'] = [
                'veg_id' => $br['veg_id'] ?? null,
                'husnummer' => $husnummer,
                'bokstav' => $br['bokstav'] ?? null,
            ];
            $groups[$key]['items'][] = $br;
        }

        // Sort entrances by husnummer, bokstav, veg_id
        uasort($groups, function ($a, $b) {
            $ha = $a['meta']['husnummer'];
            $hb = $b['meta']['husnummer'];
            if ($ha !== $hb) {
                return $ha <=> $hb;
            }
            $ba = $a['meta']['bokstav'] ?? '';
            $bb = $b['meta']['bokstav'] ?? '';
            if ($ba !== $bb) {
                return strcmp($ba, $bb);
            }
            $va = $a['meta']['veg_id'] ?? 0;
            $vb = $b['meta']['veg_id'] ?? 0;
            return $va <=> $vb;
        });

        $entranceSeq = 1;
        foreach ($groups as $group) {
            $vegId = $group['meta']['veg_id'];
            $husnummer = $group['meta']['husnummer'];
            $bokstav = $group['meta']['bokstav'];

            // Lookup adressekode from veger if veg_id exists
            $adressekode = null;
            if ($vegId !== null) {
                $veg = $this->vegRepository->findById((int) $vegId);
                $adressekode = $veg['adressekode'] ?? null;
            }

            // Create or find entrance for first building in group
            // (All units from all buildings in group will reference this entrance)
            $inngang = $this->inngangRepository->findOrCreate(
                (int) $bygningIds[0],
                $matrikkelBygningNummer,
                $kommunenummer,
                $vegId !== null ? (int) $vegId : null,
                (int) $husnummer,
                $bokstav,
                $adressekode
            );

            $this->inngangRepository->updateLopenummer((int) $inngang['inngang_id'], $entranceSeq);
            $inngangKode = $this->formatInngangKode($byggKode, $entranceSeq);
            $this->inngangRepository->updateLokasjonskode((int) $inngang['inngang_id'], $inngangKode);

            // Sort units inside entrance
            $items = $group['items'];
            usort($items, function ($a, $b) {
                $ea = $a['etasjenummer'] ?? null;
                $eb = $b['etasjenummer'] ?? null;
                if ($ea !== $eb) {
                    if ($ea === null) return -1;
                    if ($eb === null) return 1;
                    return $ea <=> $eb;
                }
                $la = $a['lopenummer'] ?? 0;
                $lb = $b['lopenummer'] ?? 0;
                if ($la !== $lb) {
                    return $la <=> $lb;
                }
                return ($a['bruksenhet_id'] ?? 0) <=> ($b['bruksenhet_id'] ?? 0);
            });

            $unitSeq = 1;
            foreach ($items as $br) {
                $bruksenhetId = (int) $br['bruksenhet_id'];
                $this->bruksenhetRepository->updateInngangReference($bruksenhetId, (int) $inngang['inngang_id']);
                $this->bruksenhetRepository->updateLopenummerIInngang($bruksenhetId, $unitSeq);
                $brKode = $this->formatBruksenhetKode($inngangKode, $unitSeq);
                $this->bruksenhetRepository->updateLokasjonskode($bruksenhetId, $brKode);
                $unitSeq++;
            }

            $entranceSeq++;
        }

        // Fallback: ensure units without address are still organized under a synthetic entrance
        if (!empty($unassigned)) {
            $syntheticInngang = $this->inngangRepository->findOrCreate(
                (int) $bygningIds[0],
                $matrikkelBygningNummer,
                $kommunenummer,
                null,
                0,
                null,
                null
            );
            $this->inngangRepository->updateLopenummer((int) $syntheticInngang['inngang_id'], $entranceSeq);
            $syntheticKode = $this->formatInngangKode($byggKode, $entranceSeq);
            $this->inngangRepository->updateLokasjonskode((int) $syntheticInngang['inngang_id'], $syntheticKode);

            usort($unassigned, function ($a, $b) {
                $ea = $a['etasjenummer'] ?? null;
                $eb = $b['etasjenummer'] ?? null;
                if ($ea !== $eb) {
                    if ($ea === null) return -1;
                    if ($eb === null) return 1;
                    return $ea <=> $eb;
                }
                $la = $a['lopenummer'] ?? 0;
                $lb = $b['lopenummer'] ?? 0;
                if ($la !== $lb) {
                    return $la <=> $lb;
                }
                return ($a['bruksenhet_id'] ?? 0) <=> ($b['bruksenhet_id'] ?? 0);
            });

            $unitSeq = 1;
            foreach ($unassigned as $br) {
                $bruksenhetId = (int) ($br['bruksenhet_id'] ?? 0);
                if ($bruksenhetId <= 0) { continue; }
                $this->bruksenhetRepository->updateInngangReference($bruksenhetId, (int) $syntheticInngang['inngang_id']);
                $this->bruksenhetRepository->updateLopenummerIInngang($bruksenhetId, $unitSeq);
                $brKode = $this->formatBruksenhetKode($syntheticKode, $unitSeq);
                $this->bruksenhetRepository->updateLokasjonskode($bruksenhetId, $brKode);
                $unitSeq++;
            }
        }
    }

    /**
     * Organize entrances and units for a single building (legacy method for backward compatibility)
     */
    public function organizeBygning(int $bygningId, string $byggKode): void
    {
        $bygning = $this->bygningRepository->findById($bygningId);
        $this->organizeBygningGroup([
            $bygningId,
        ], $byggKode, $bygning['matrikkel_bygning_nummer'] ?? null, $bygning['kommunenummer'] ?? null);
    }

    private function generateEiendomKode(array $eiendom): string
    {
        if (!empty($eiendom['lokasjonskode_eiendom'])) {
            return (string) $eiendom['lokasjonskode_eiendom'];
        }

        // Fallback: use matrikkelenhet_id to ensure uniqueness if gnr/bnr mapping is not defined
        if (!empty($eiendom['matrikkelenhet_id'])) {
            return (string) $eiendom['matrikkelenhet_id'];
        }

        return 'eiendom';
    }

    private function formatByggKode(string $eiendomKode, int $seq): string
    {
        return $eiendomKode . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }

    private function formatInngangKode(string $byggKode, int $seq): string
    {
        return $byggKode . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }

    private function formatBruksenhetKode(string $inngangKode, int $seq): string
    {
        return $inngangKode . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve first address from matrikkelenhet when bruksenhet has no direct address
     */
    private function resolveMatrikkelenhetAddress(int $matrikkelenhetId): ?array
    {
        $pdo = $this->matrikkelenhetRepository->getPdo();
        
        $stmt = $pdo->prepare('
            SELECT va.nummer as husnummer, va.bokstav, va.veg_id, v.adressenavn, v.kort_adressenavn, v.adressekode
            FROM matrikkel_matrikkelenhet_adresse ma
            JOIN matrikkel_adresser a ON ma.adresse_id = a.adresse_id
            LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
            LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
            WHERE ma.matrikkelenhet_id = :matrikkelenhet_id
            ORDER BY va.nummer, va.bokstav
            LIMIT 1
        ');
        
        $stmt->execute(['matrikkelenhet_id' => $matrikkelenhetId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
}
