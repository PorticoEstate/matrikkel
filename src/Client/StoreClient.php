<?php
/**
 * StoreClient - SOAP Client for StoreService
 * 
 * StoreService brukes til å hente komplette objekter basert på ID-er.
 * Dette er nøkkeltjenesten i "two-step pattern":
 * 
 * 1. Finn ID-er via søketjeneste (MatrikkelenhetService, BruksenhetService, etc.)
 * 2. Hent komplette objekter via StoreService.getObjects()
 * 
 * Fordeler:
 * - Batch-støtte: Hent mange objekter i ett API-kall
 * - Effektiv: Kun objekter du trenger
 * - Type-safe: Returnerer komplette, typede objekter
 * 
 * Operasjoner:
 * - getObject: Hent enkelt objekt
 * - getObjects: Hent batch av objekter (anbefalt - max 1000 per batch)
 * - getObjectsIgnoreMissing: Som getObjects, men ignorerer manglende objekter
 * 
 * Eksempel bruk:
 * ```php
 * $client = new StoreClient($wsdl, $options);
 * 
 * // Hent enkelt objekt
 * $matrikkelenhetId = new MatrikkelenhetId(123456789);
 * $matrikkelenhet = $client->getObject($matrikkelenhetId);
 * 
 * // Hent batch av objekter (anbefalt)
 * $personIds = [
 *     new PersonId(111111111),
 *     new PersonId(222222222),
 *     new PersonId(333333333),
 * ];
 * $personer = $client->getObjects($personIds);
 * 
 * foreach ($personer as $person) {
 *     if (isset($person->fodselsnummer)) {
 *         // Fysisk person
 *         echo "Fysisk: {$person->navn}\n";
 *     } elseif (isset($person->organisasjonsnummer)) {
 *         // Juridisk person
 *         echo "Juridisk: {$person->navn}\n";
 *     }
 * }
 * ```
 * 
 * @author Sigurd Nes
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Client;

class StoreClient extends AbstractSoapClient
{
    /**
     * WSDL URLs for StoreServiceWS
     */
    const WSDL = [
        'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS?WSDL',
        'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS?WSDL',
    ];
    
    /**
     * Hent enkelt objekt basert på ID
     * 
     * Bruk getObjects() i stedet hvis du henter flere objekter - 
     * det er mye mer effektivt!
     * 
     * @param mixed $bubbleId MatrikkelBubbleId object (PersonId, MatrikkelenhetId, etc.)
     * @return mixed Komplett objekt (Person, Matrikkelenhet, Bygning, etc.)
     * @throws \SoapFault Hvis objektet ikke finnes eller API-feil
     */
    public function getObject($bubbleId)
    {
        $params = [
            'id' => $bubbleId,  // Note: parameter name is 'id' per WSDL
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('getObject', [$params]);
            return $response->return ?? null;
            
        } catch (\SoapFault $e) {
            error_log("[StoreClient::getObject] SOAP Fault: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Hent batch av komplette objekter basert på ID-er
     * 
     * ANBEFALT for alle batch-operasjoner. Batch-støtte reduserer
     * antall API-kall drastisk (500 objekter = 1 API-kall i stedet for 500).
     * 
     * @param array $bubbleIds Array av MatrikkelBubbleId objekter
     * @param int $batchSize Maximum batch size (default 1000, API max)
     * @return array Array av komplette objekter
     * @throws \SoapFault Hvis API-feil
     * 
     * @example
     * ```php
     * // Hent 2500 personer i 3 API-kall (3x1000) i stedet for 2500 kall
     * $allPersoner = [];
     * foreach (array_chunk($personIds, 1000) as $batch) {
     *     $personer = $client->getObjects($batch);
     *     $allPersoner = array_merge($allPersoner, $personer);
     * }
     * ```
     */
    public function getObjects(array $bubbleIds, int $batchSize = 1000): array
    {
        // Validate input
        if (empty($bubbleIds)) {
            return [];
        }
        
        // Split into batches if needed
        if (count($bubbleIds) > $batchSize) {
            error_log("[StoreClient::getObjects] WARNING: Input has " . count($bubbleIds) . " items, splitting into batches of $batchSize");
            
            $allObjects = [];
            foreach (array_chunk($bubbleIds, $batchSize) as $batch) {
                $objects = $this->getObjects($batch, $batchSize);
                $allObjects = array_merge($allObjects, $objects);
            }
            return $allObjects;
        }
        
        // Build SOAP request
        // Note: SOAP expects ids to be wrapped in MatrikkelBubbleIdList structure with 'item' property
        $params = [
            'ids' => ['item' => $bubbleIds],
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('getObjects', [$params]);
            
            // Parse response - SOAP wrapper structure
            // Response: return->item (can be single object or array of MatrikkelBubbleObject)
            $items = [];
            if (isset($response->return) && isset($response->return->item)) {
                $objects = $response->return->item;
                
                // Normalize to array
                if (!is_array($objects)) {
                    $objects = [$objects];
                }
                
                $items = $objects;
            }
            
            return $items;
            
        } catch (\SoapFault $e) {
            error_log("[StoreClient::getObjects] SOAP Fault: " . $e->getMessage());
            error_log("[StoreClient::getObjects] Requested " . count($bubbleIds) . " objects");
            throw $e;
        }
    }
    
    /**
     * Hent batch av objekter, men ignorer manglende objekter
     * 
     * Bruk denne hvis noen objekter kan mangle (f.eks. slettet fra Matrikkel),
     * og du ikke vil at hele batch-kallet skal feile.
     * 
     * @param array $bubbleIds Array av MatrikkelBubbleId objekter
     * @param int $batchSize Maximum batch size (default 1000)
     * @return array Array av komplette objekter (manglende objekter utelates)
     * @throws \SoapFault Hvis API-feil (ikke for manglende objekter)
     */
    public function getObjectsIgnoreMissing(array $bubbleIds, int $batchSize = 1000): array
    {
        // Validate input
        if (empty($bubbleIds)) {
            return [];
        }
        
        // Split into batches if needed
        if (count($bubbleIds) > $batchSize) {
            $allObjects = [];
            foreach (array_chunk($bubbleIds, $batchSize) as $batch) {
                $objects = $this->getObjectsIgnoreMissing($batch, $batchSize);
                $allObjects = array_merge($allObjects, $objects);
            }
            return $allObjects;
        }
        
        // Build SOAP request
        $params = [
            'matrikkelBubbleIdList' => [
                'matrikkelBubbleId' => $bubbleIds
            ],
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('getObjectsIgnoreMissing', [$params]);
            
            // Parse response
            $items = [];
            if (isset($response->return)) {
                if (isset($response->return->matrikkelBubbleObject)) {
                    $objects = $response->return->matrikkelBubbleObject;
                    
                    // Normalize to array
                    if (!is_array($objects)) {
                        $objects = [$objects];
                    }
                    
                    $items = $objects;
                }
            }
            
            return $items;
            
        } catch (\SoapFault $e) {
            error_log("[StoreClient::getObjectsIgnoreMissing] SOAP Fault: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Helper: Hent metadata om objekter uten full data
     * 
     * Returnerer versjonsinformasjon for objekter uten å laste full data.
     * Nyttig for å sjekke om objekter har blitt oppdatert.
     * 
     * @param array $bubbleIds Array av MatrikkelBubbleId objekter
     * @return array Array av versjonsobjekter
     */
    public function getVersionsForList(array $bubbleIds): array
    {
        if (empty($bubbleIds)) {
            return [];
        }
        
        $params = [
            'matrikkelBubbleIdList' => [
                'matrikkelBubbleId' => $bubbleIds
            ],
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('getVersionsForList', [$params]);
            
            $items = [];
            if (isset($response->return)) {
                if (isset($response->return->matrikkelVersion)) {
                    $versions = $response->return->matrikkelVersion;
                    
                    if (!is_array($versions)) {
                        $versions = [$versions];
                    }
                    
                    $items = $versions;
                }
            }
            
            return $items;
            
        } catch (\SoapFault $e) {
            error_log("[StoreClient::getVersionsForList] SOAP Fault: " . $e->getMessage());
            throw $e;
        }
    }
}