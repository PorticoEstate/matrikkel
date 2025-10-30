<?php
/**
 * MatrikkelenhetFilterService - Filter matrikkelenheter by owner
 * 
 * This is the KEY service for Phase 2! Phase 2 depends on Phase 1 having
 * already imported matrikkelenheter to the database.
 * 
 * Database-Based Filtering:
 * Phase 2 queries the local database to find matrikkelenheter owned by
 * specific persons or organizations. The eierforhold data is stored as JSON
 * in the matrikkel_matrikkelenheter.eierforhold column.
 * 
 * Workflow:
 * 1. Phase 1: Import matrikkelenheter (with --organisasjonsnummer filter if needed)
 * 2. Phase 2: Query database for matrikkelenheter owned by specific owner
 * 3. Phase 2: Import bruksenheter, bygninger, adresser for filtered matrikkelenheter
 * 
 * Result: Only download detail data for relevant matrikkelenheter!
 * 
 * @author Sigurd Nes
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use PDO;

class MatrikkelenhetFilterService
{
    private PDO $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Filter matrikkelenheter by owner (DATABASE query)
     * 
     * Phase 2 depends on Phase 1! This method queries the local database
     * for matrikkelenheter that were already imported by Phase 1.
     * 
     * If filters are provided, it searches the eierforhold JSON column.
     * If no filters, it returns ALL matrikkelenheter for the kommune.
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
     * Filter matrikkelenheter by organisasjon (DATABASE-based)
     * 
     * Phase 2 assumes Phase 1 has already imported matrikkelenheter to database.
     * This method queries the local database instead of calling the API.
     * 
     * Uses the matrikkel_eierforhold junction table to find ownership.
     * 
     * @param SymfonyStyle $io
     * @param int $kommunenummer
     * @param string $organisasjonsnummer
     * @return array Array of matrikkelenhet_id integers
     */
    private function filterByOrganisasjon(SymfonyStyle $io, int $kommunenummer, string $organisasjonsnummer): array
    {
        $io->text("Filtrerer matrikkelenheter fra DATABASE for organisasjonsnummer: $organisasjonsnummer");
        
        // Query database for matrikkelenheter owned by this organization
        // Uses matrikkel_eierforhold junction table to find ownership
        try {
            $sql = "
                SELECT DISTINCT m.matrikkelenhet_id 
                FROM matrikkel_matrikkelenheter m
                INNER JOIN matrikkel_eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
                INNER JOIN matrikkel_personer p ON e.person_id = p.matrikkel_person_id
                WHERE m.kommunenummer = :kommunenummer
                AND p.nummer = :nummer
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'kommunenummer' => $kommunenummer,
                'nummer' => $organisasjonsnummer
            ]);
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $matrikkelenhetIds = array_map(fn($row) => (int)$row['matrikkelenhet_id'], $rows);
            
            $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter for organisasjon i database");
            
            return $matrikkelenhetIds;
            
        } catch (\Exception $e) {
            $io->error("Feil ved søk i database: " . $e->getMessage());
            $io->warning("Har du kjørt Phase 1 først? Phase 2 krever at matrikkelenheter er importert.");
            return [];
        }
    }    /**
     * Filter matrikkelenheter by person (DATABASE-based)
     * 
     * Phase 2 assumes Phase 1 has already imported matrikkelenheter to database.
     * This method queries the local database instead of calling the API.
     * 
     * Uses the matrikkel_eierforhold junction table to find ownership.
     */
    private function filterByPerson(SymfonyStyle $io, int $kommunenummer, string $personnummer): array
    {
        $io->text("Filtrerer matrikkelenheter fra DATABASE for personnummer: $personnummer");
        
        // Query database for matrikkelenheter owned by this person
        // Uses matrikkel_eierforhold junction table to find ownership
        try {
            $sql = "
                SELECT DISTINCT m.matrikkelenhet_id 
                FROM matrikkel_matrikkelenheter m
                INNER JOIN matrikkel_eierforhold e ON m.matrikkelenhet_id = e.matrikkelenhet_id
                INNER JOIN matrikkel_personer p ON e.person_id = p.matrikkel_person_id
                WHERE m.kommunenummer = :kommunenummer
                AND p.nummer = :nummer
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'kommunenummer' => $kommunenummer,
                'nummer' => $personnummer
            ]);
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $matrikkelenhetIds = array_map(fn($row) => (int)$row['matrikkelenhet_id'], $rows);
            
            $io->success("Funnet " . count($matrikkelenhetIds) . " matrikkelenheter for person i database");
            
            return $matrikkelenhetIds;
            
        } catch (\Exception $e) {
            $io->error("Feil ved søk i database: " . $e->getMessage());
            $io->warning("Har du kjørt Phase 1 først? Phase 2 krever at matrikkelenheter er importert.");
            return [];
        }
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
