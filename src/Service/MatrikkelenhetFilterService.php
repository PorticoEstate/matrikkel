<?php
/**
 * MatrikkelenhetFilterService - Filter matrikkelenheter by owner
 * 
 * This is the KEY service for Phase 2! Instead of downloading ALL data for
 * a kommune, we filter to only matrikkelenheter owned by specific persons
 * or organizations.
 * 
 * Two-Step Server-Side Filtering:
 * 1. PersonClient.findPersonIdByNummer(organisasjonsnummer) -> PersonId
 * 2. MatrikkelenhetClient.findMatrikkelenheterForOrganisasjon(PersonId) -> [MatrikkelenhetIds]
 * 
 * Result: Only download data for ~100 matrikkelenheter instead of 116,000!
 * 
 * Database fallback:
 * If Phase 1 has already populated eierforhold in database, we can also
 * query locally for even faster filtering.
 * 
 * @author Matrikkel Integration System
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\PersonClient;
use Iaasen\Matrikkel\Client\MatrikkelenhetClient;
use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class MatrikkelenhetFilterService
{
    private PersonClient $personClient;
    private MatrikkelenhetClient $matrikkelenhetClient;
    private PDO $db;
    
    public function __construct(
        PersonClient $personClient,
        MatrikkelenhetClient $matrikkelenhetClient,
        PDO $db
    ) {
        $this->personClient = $personClient;
        $this->matrikkelenhetClient = $matrikkelenhetClient;
        $this->db = $db;
    }
    
    /**
     * Filter matrikkelenheter by owner (SERVER-SIDE via Matrikkel API)
     * 
     * This is the Phase 2 magic! Instead of downloading 116,000 matrikkelenheter
     * and filtering locally, we ask Matrikkel API: "Which matrikkelenheter does
     * organisation X own?" and get back only ~100 IDs.
     * 
     * @param SymfonyStyle $io Console output
     * @param int $kommunenummer Kommune number
     * @param string|null $personnummer Fødselsnummer (11 digits)
     * @param string|null $organisasjonsnummer Organisasjonsnummer (9 digits)
     * @return array Array of matrikkelenhet_id integers
     */
    public function filterMatrikkelenheterByOwner(
        SymfonyStyle $io,
        int $kommunenummer,
        ?string $personnummer = null,
        ?string $organisasjonsnummer = null
    ): array {
        
        // Option 1: Filter by organisasjonsnummer (SERVER-SIDE)
        if ($organisasjonsnummer) {
            $io->text("Filtrerer på organisasjonsnummer: $organisasjonsnummer");
            return $this->filterByOrganisasjon($io, $kommunenummer, $organisasjonsnummer);
        }
        
        // Option 2: Filter by personnummer (SERVER-SIDE)
        if ($personnummer) {
            $io->text("Filtrerer på personnummer: $personnummer");
            return $this->filterByPerson($io, $kommunenummer, $personnummer);
        }
        
        // Option 3: No filter - return ALL matrikkelenheter for kommune
        $io->text("Ingen eier-filter - henter alle matrikkelenheter for kommune");
        return $this->getAllMatrikkelenheterForKommune($kommunenummer);
    }
    
    /**
     * Filter matrikkelenheter by organisation (SERVER-SIDE)
     * 
     * Two-step API pattern:
     * 1. PersonClient.findPersonIdByOrganisasjonsnummer() -> PersonId or null
     * 2. MatrikkelenhetClient.findMatrikkelenheterForOrganisasjon() -> [MatrikkelenhetIds]
     * 
     * @param SymfonyStyle $io
     * @param int $kommunenummer
     * @param string $organisasjonsnummer
     * @return array Array of matrikkelenhet_id integers
     */
    private function filterByOrganisasjon(SymfonyStyle $io, int $kommunenummer, string $organisasjonsnummer): array
    {
        $io->text("Step 1/2: Finner PersonId for organisasjonsnummer...");
        
        // Step 1: Find PersonId in Matrikkel
        $personId = $this->personClient->findPersonIdByOrganisasjonsnummer($organisasjonsnummer);
        
        if ($personId === null) {
            $io->warning("Organisasjon $organisasjonsnummer er ikke registrert som eier i Matrikkel.");
            $io->note("Dette kan bety at organisasjonen ikke eier noen matrikkelenheter i Norge.");
            return [];
        }
        
        $io->success("Funnet PersonId: " . ($personId->value ?? 'unknown'));
        
        $io->text("Step 2/2: Henter matrikkelenheter for organisasjon (SERVER-SIDE filter)...");
        
        // Step 2: Get matrikkelenheter for this PersonId
        $matrikkelenhetIdObjects = $this->matrikkelenhetClient->findMatrikkelenheterForOrganisasjon($personId);
        
        // Extract numeric IDs
        $matrikkelenhetIds = [];
        foreach ($matrikkelenhetIdObjects as $idObj) {
            if (isset($idObj->value)) {
                $matrikkelenhetIds[] = (int)$idObj->value;
            }
        }
        
        // Filter to only those in the specified kommune
        // (API may return matrikkelenheter from other kommuner)
        if (!empty($matrikkelenhetIds)) {
            $matrikkelenhetIds = $this->filterToKommune($matrikkelenhetIds, $kommunenummer);
        }
        
        $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter for organisasjon i kommune $kommunenummer");
        
        return $matrikkelenhetIds;
    }
    
    /**
     * Filter matrikkelenheter by person (SERVER-SIDE)
     */
    private function filterByPerson(SymfonyStyle $io, int $kommunenummer, string $personnummer): array
    {
        $io->text("Step 1/2: Finner PersonId for personnummer...");
        
        // Step 1: Find PersonId in Matrikkel
        $personId = $this->personClient->findPersonIdByFodselsnummer($personnummer);
        
        if ($personId === null) {
            $io->warning("Person $personnummer er ikke registrert som eier i Matrikkel.");
            $io->note("Dette kan bety at personen ikke eier noen matrikkelenheter i Norge.");
            return [];
        }
        
        $io->success("Funnet PersonId: " . ($personId->value ?? 'unknown'));
        
        $io->text("Step 2/2: Henter matrikkelenheter for person (SERVER-SIDE filter)...");
        
        // Step 2: Get matrikkelenheter for this PersonId
        $matrikkelenhetIdObjects = $this->matrikkelenhetClient->findMatrikkelenheterForPerson($personId);
        
        // Extract numeric IDs
        $matrikkelenhetIds = [];
        foreach ($matrikkelenhetIdObjects as $idObj) {
            if (isset($idObj->value)) {
                $matrikkelenhetIds[] = (int)$idObj->value;
            }
        }
        
        // Filter to only those in the specified kommune
        if (!empty($matrikkelenhetIds)) {
            $matrikkelenhetIds = $this->filterToKommune($matrikkelenhetIds, $kommunenummer);
        }
        
        $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter for person i kommune $kommunenummer");
        
        return $matrikkelenhetIds;
    }
    
    /**
     * Get ALL matrikkelenheter for kommune (no filter)
     */
    private function getAllMatrikkelenheterForKommune(int $kommunenummer): array
    {
        $stmt = $this->db->prepare(
            "SELECT matrikkelenhet_id FROM matrikkel_matrikkelenheter WHERE kommunenummer = ?"
        );
        $stmt->execute([$kommunenummer]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => (int)$row['matrikkelenhet_id'], $rows);
    }
    
    /**
     * Filter matrikkelenhet IDs to only those in specified kommune
     * 
     * Uses database to check which IDs exist in the kommune
     */
    private function filterToKommune(array $matrikkelenhetIds, int $kommunenummer): array
    {
        if (empty($matrikkelenhetIds)) {
            return [];
        }
        
        // Build IN clause
        $placeholders = str_repeat('?,', count($matrikkelenhetIds) - 1) . '?';
        $sql = "
            SELECT matrikkelenhet_id 
            FROM matrikkel_matrikkelenheter 
            WHERE matrikkelenhet_id IN ($placeholders)
            AND kommunenummer = ?
        ";
        
        $params = array_merge($matrikkelenhetIds, [$kommunenummer]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => (int)$row['matrikkelenhet_id'], $rows);
    }
}
