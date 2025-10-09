<?php
/**
 * PersonTable - Physical persons (eiere) table operations
 * 
 * Handles insertion of Person/FysiskPerson objects from SOAP API
 * Fetched on-demand via StoreService.getObjects()
 */

namespace Iaasen\Matrikkel\LocalDb;

class PersonTable extends AbstractTable
{
    protected string $tableName = 'matrikkel_personer';
    
    /**
     * Insert a Person object from SOAP API into the buffer
     * 
     * @param object $person Person/FysiskPerson SOAP object from StoreService
     */
    public function insertRow(object $person): void
    {
        // Extract person_id from MatrikkelBubbleId
        $personId = isset($person->id->value) ? (int)$person->id->value : null;
        if (!$personId) {
            return; // Skip if no valid ID
        }
        
        // Extract name components
        // FysiskPerson has: fornavn, etternavn, mellomnavn
        $fornavn = $person->fornavn ?? null;
        $etternavn = $person->etternavn ?? null;
        $mellomnavn = $person->mellomnavn ?? null;
        
        // Build full name
        $fulltNavn = trim(implode(' ', array_filter([
            $fornavn,
            $mellomnavn,
            $etternavn
        ])));
        
        // Extract fødselsnummer (if available and allowed)
        // Note: May be restricted due to privacy laws
        $fodselsnummer = $person->fodselsnummer ?? null;
        
        // Extract address ID if available
        $adresseId = isset($person->adresseId->value) ? (int)$person->adresseId->value : null;
        
        // Build row data
        $this->adresseRows[] = [
            'person_id' => $personId,
            'fornavn' => $fornavn,
            'etternavn' => $etternavn,
            'fullt_navn' => $fulltNavn ?: null,
            'fodselsnummer' => $fodselsnummer,
            'adresse_id' => $adresseId,
        ];
        
        $this->cachedRows++;
        
        // Flush every 100 rows for efficiency
        if ($this->cachedRows >= 100) {
            $this->flush();
        }
    }
    
    /**
     * Get table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }
}
