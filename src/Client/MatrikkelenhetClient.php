<?php
/**
 * MatrikkelenhetClient - SOAP Client for MatrikkelenhetService
 * 
 * MatrikkelenhetService brukes til server-side filtrering av matrikkelenheter.
 * Dette er KRITISK for Phase 2: I stedet for å laste ned ALLE matrikkelenheter
 * for en kommune og filtrere lokalt, kan vi be API-et om kun de vi trenger.
 * 
 * Operasjoner:
 * - findMatrikkelenheter: Server-side filtrering på person/organisasjon
 * - Returnerer kun ID-er (ikke komplette objekter)
 * - Bruk StoreService.getObjects() for å hente komplette objekter etterpå
 * 
 * Two-Step Pattern (Phase 2):
 * 1. MatrikkelenhetClient.findMatrikkelenheterForPerson(personnummer) -> [IDs]
 * 2. StoreClient.getObjects([IDs]) -> [komplette matrikkelenheter]
 * 
 * Eksempel bruk:
 * ```php
 * $client = new MatrikkelenhetClient($wsdl, $options);
 * 
 * // Finn matrikkelenheter eid av person
 * $personId = new PersonId(12345678901);
 * $matrikkelenhetIds = $client->findMatrikkelenheterForPerson($personId);
 * 
 * // Hent komplette objekter
 * $storeClient = new StoreClient(...);
 * $matrikkelenheter = $storeClient->getObjects($matrikkelenhetIds);
 * ```
 * 
 * @author Matrikkel Integration System
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Client;

class MatrikkelenhetClient extends AbstractSoapClient
{
    /**
     * WSDL URLs for MatrikkelenhetServiceWS
     */
    const WSDL = [
        'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/MatrikkelenhetServiceWS?WSDL',
        'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/MatrikkelenhetServiceWS?WSDL',
    ];
    
    /**
     * Finn matrikkelenheter eid av en person (SERVER-SIDE FILTRERING)
     * 
     * Dette er Phase 2 nøkkelmetode! I stedet for:
     * - Last ned alle 50,000 matrikkelenheter for Bergen
     * - Filtrer lokalt til de 3 som eies av person X
     * 
     * Gjør vi:
     * - Spør API: "Hvilke matrikkelenheter eier person X?"
     * - Få tilbake kun 3 ID-er
     * - Hent kun disse 3 komplette objektene
     * 
     * @param PersonId $personId ID for fysisk person (fødselsnummer)
     * @return array Array av MatrikkelenhetId objekter
     * @throws \SoapFault Hvis API-feil
     */
    public function findMatrikkelenheterForPerson($personId): array
    {
        $params = [
            'personId' => $personId,
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('findEideMatrikkelenheterForPerson', [$params]);
            
            // Parse response - returns MatrikkelenhetIdList
            $items = [];
            if (isset($response->return)) {
                if (isset($response->return->items)) {
                    $ids = $response->return->items;
                    
                    // Normalize to array
                    if (!is_array($ids)) {
                        $ids = [$ids];
                    }
                    
                    $items = $ids;
                }
            }
            
            error_log("[MatrikkelenhetClient] Found " . count($items) . " matrikkelenheter for person");
            return $items;
            
        } catch (\SoapFault $e) {
            error_log("[MatrikkelenhetClient::findMatrikkelenheterForPerson] SOAP Fault: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Finn matrikkelenheter eid av en organisasjon (SERVER-SIDE FILTRERING)
     * 
     * Som findMatrikkelenheterForPerson(), men for juridiske personer.
     * 
     * @param PersonId $organisasjonId ID for juridisk person (organisasjonsnummer)
     * @return array Array av MatrikkelenhetId objekter
     * @throws \SoapFault Hvis API-feil
     */
    public function findMatrikkelenheterForOrganisasjon($organisasjonId): array
    {
        // Same logic as person, but potentially different query structure
        // For now, using same method as API treats both as PersonId
        return $this->findMatrikkelenheterForPerson($organisasjonId);
    }
    
    /**
     * Finn matrikkelenhet basert på kommunenummer, gårdsnummer, bruksnummer, festenummer, seksjonsnummer
     * 
     * Legacy metode for å finne spesifikk matrikkelenhet.
     * 
     * @param int $kommunenummer
     * @param int $gardsnummer
     * @param int $bruksnummer
     * @param int|null $festenummer
     * @param int|null $seksjonsnummer
     * @return mixed MatrikkelenhetId eller null
     */
    public function findMatrikkelenhet(
        int $kommunenummer,
        int $gardsnummer,
        int $bruksnummer,
        ?int $festenummer = null,
        ?int $seksjonsnummer = null
    ) {
        $query = [
            'kommunenummer' => $kommunenummer,
            'gardsnummer' => $gardsnummer,
            'bruksnummer' => $bruksnummer,
        ];
        
        if ($festenummer !== null) {
            $query['festenummer'] = $festenummer;
        }
        
        if ($seksjonsnummer !== null) {
            $query['seksjonsnummer'] = $seksjonsnummer;
        }
        
        $params = [
            'matrikkelenhetQuery' => $query,
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('findMatrikkelenhet', [$params]);
            return $response->return ?? null;
            
        } catch (\SoapFault $e) {
            error_log("[MatrikkelenhetClient::findMatrikkelenhet] SOAP Fault: " . $e->getMessage());
            throw $e;
        }
    }
}