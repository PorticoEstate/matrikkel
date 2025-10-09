<?php
/**
 * JuridiskPersonTable - Legal entities (organizations) table operations
 * 
 * Handles insertion of JuridiskPerson objects from SOAP API
 * Fetched on-demand via StoreService.getObjects()
 */

namespace Iaasen\Matrikkel\LocalDb;

class JuridiskPersonTable extends AbstractTable
{
    protected string $tableName = 'matrikkel_juridiske_personer';
    
    /**
     * Insert a JuridiskPerson object from SOAP API into the buffer
     * 
     * @param object $juridiskPerson JuridiskPerson SOAP object from StoreService
     */
    public function insertRow(object $juridiskPerson): void
    {
        // Extract juridisk_person_id from MatrikkelBubbleId
        $juridiskPersonId = isset($juridiskPerson->id->value) ? (int)$juridiskPerson->id->value : null;
        if (!$juridiskPersonId) {
            return; // Skip if no valid ID
        }
        
        // Extract organization details
        $organisasjonsnavn = $juridiskPerson->organisasjonsnavn
            ?? $juridiskPerson->navn
            ?? null;
        $organisasjonsnummer = $juridiskPerson->organisasjonsnummer
            ?? $juridiskPerson->nummer
            ?? null;
        
        // Extract organization form (e.g., AS, ASA, Kommune, etc.)
        $organisasjonsform = null;
        if (isset($juridiskPerson->organisasjonsformKode->verdi)) {
            $organisasjonsform = $juridiskPerson->organisasjonsformKode->verdi;
        } elseif (isset($juridiskPerson->organisasjonsformKode->orgformKode)) {
            $organisasjonsform = $juridiskPerson->organisasjonsformKode->orgformKode;
        } elseif (isset($juridiskPerson->organisasjonsform)) {
            $organisasjonsform = $juridiskPerson->organisasjonsform;
        }
        
        // Extract address ID if available
        $adresseId = isset($juridiskPerson->adresseId->value) ? (int)$juridiskPerson->adresseId->value : null;
        
        // Build row data
        $this->adresseRows[] = [
            'juridisk_person_id' => $juridiskPersonId,
            'organisasjonsnavn' => $organisasjonsnavn,
            'organisasjonsnummer' => $organisasjonsnummer,
            'organisasjonsform' => $organisasjonsform,
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
