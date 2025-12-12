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
        $seq = 1;
        foreach ($bygninger as $bygning) {
            $byggKode = $this->formatByggKode($eiendomKode, $seq);
            $this->bygningRepository->updateLopenummerIEiendom((int) $bygning['bygning_id'], $seq);
            $this->bygningRepository->updateLokasjonskode((int) $bygning['bygning_id'], $byggKode);

            $this->organizeBygning((int) $bygning['bygning_id'], $byggKode);
            $seq++;
        }
    }

    /**
     * Organize entrances and units for a building
     */
    public function organizeBygning(int $bygningId, string $byggKode): void
    {
        $bruksenheter = $this->bruksenhetRepository->findByBygningIdWithAdresse($bygningId);
        if (empty($bruksenheter)) {
            return;
        }

        // Group by entrance key: (veg_id, husnummer, bokstav)
        $groups = [];
        foreach ($bruksenheter as $br) {
            $husnummer = $br['husnummer'] ?? null;
            if ($husnummer === null) {
                // Skip units without address; could be handled separately if needed
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

            $inngang = $this->inngangRepository->findOrCreate($bygningId, $vegId !== null ? (int) $vegId : null, (int) $husnummer, $bokstav, $adressekode);

            $this->inngangRepository->updateLopenummer((int) $inngang['inngang_id'], $entranceSeq);
            $inngangKode = $this->formatInngangKode($byggKode, $entranceSeq);
            $this->inngangRepository->updateLokasjonskode((int) $inngang['inngang_id'], $inngangKode);

            // Sort units inside entrance
            $items = $group['items'];
            usort($items, function ($a, $b) {
                $ea = $a['etasjenummer'] ?? null;
                $eb = $b['etasjenummer'] ?? null;
                if ($ea !== $eb) {
                    // NULL first
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
}
