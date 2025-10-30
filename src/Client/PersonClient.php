<?php
/**
 * PersonClient - SOAP Client for PersonService
 * 
 * PersonService brukes til å finne PersonId basert på fødselsnummer eller
 * organisasjonsnummer. Dette er nødvendig for Phase 2 filtrering.
 * 
 * VIKTIG: Ikke alle personer i Norge er registrert i Matrikkel!
 * En person må ha eierandel i minst én matrikkelenhet for å finnes i Matrikkel.
 * Derfor må vi håndtere 404 gracefully.
 * 
 * Flow:
 * 1. Bruker oppgir fødselsnummer: "12345678901"
 * 2. PersonClient.findPersonIdByNummer("12345678901") -> PersonId(789012345) eller null
 * 3. Hvis PersonId funnet: MatrikkelenhetClient.findMatrikkelenheterForPerson(PersonId)
 * 4. Hvis null: Person har ingen eiendommer i Matrikkel
 * 
 * Eksempel bruk:
 * ```php
 * $client = new PersonClient($wsdl, $options);
 * 
 * // Sjekk om person finnes i Matrikkel
 * $personId = $client->findPersonIdByNummer("12345678901");
 * 
 * if ($personId === null) {
 *     echo "Person har ingen registrerte eiendommer i Matrikkel\n";
 *     return;
 * }
 * 
 * // Person finnes - hent matrikkelenheter
 * $matrikkelenhetClient = new MatrikkelenhetClient(...);
 * $matrikkelenhetIds = $matrikkelenhetClient->findMatrikkelenheterForPerson($personId);
 * ```
 * 
 * @author Sigurd Nes
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Client;

class PersonClient extends AbstractSoapClient
{
    /**
     * WSDL URLs for PersonServiceWS
     */
    const WSDL = [
        'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/PersonServiceWS?WSDL',
        'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/PersonServiceWS?WSDL',
    ];
    
    /**
     * Finn PersonId basert på fødselsnummer eller organisasjonsnummer
     * 
     * VIKTIG: Returnerer null hvis person ikke finnes i Matrikkel.
     * Dette er IKKE en feil - det betyr bare at personen ikke har
     * noen registrerte eiendommer.
     * 
     * @param string $nummer Fødselsnummer (11 siffer) eller organisasjonsnummer (9 siffer)
     * @return PersonId|null PersonId hvis funnet, null hvis ikke i Matrikkel
     * @throws \SoapFault Kun ved faktiske API-feil (ikke 404)
     */
    public function findPersonIdByNummer(string $nummer): ?object
    {
        // Determine if fødselsnummer (11 digits) or organisasjonsnummer (9 digits)
        $personIdent = strlen($nummer) === 11
            ? new FysiskPersonIdent($nummer)
            : new JuridiskPersonIdent($nummer);
        
        $params = [
            'personIdent' => $personIdent,
            'matrikkelContext' => $this->getMatrikkelContext()
        ];
        
        try {
            $response = $this->__call('findPersonIdForIdent', [$params]);
            
            // Check if PersonId was returned
            if (isset($response->return)) {
                error_log("[PersonClient] Found PersonId for nummer: $nummer");
                return $response->return;
            }
            
            // No PersonId found (but no error either)
            error_log("[PersonClient] No PersonId found for nummer: $nummer");
            return null;
            
        } catch (\SoapFault $e) {
            $faultCode = $e->faultcode ?? '';
            $faultString = $e->getMessage();
            
            // 404 = Person not found in Matrikkel (THIS IS NORMAL!)
            if (
                stripos($faultString, 'not found') !== false ||
                stripos($faultString, 'kunne ikke finnes') !== false ||
                $faultCode === 'Server.PersonNotFound'
            ) {
                error_log("[PersonClient] Person not found in Matrikkel: $nummer (this is normal)");
                return null;
            }
            
            // Other SOAP faults are real errors
            error_log("[PersonClient::findPersonIdByNummer] SOAP Fault: " . $faultString);
            throw $e;
        }
    }
    
    /**
     * Finn PersonId basert på fødselsnummer (alias for findPersonIdByNummer)
     * 
     * @param string $fodselsnummer 11-sifret fødselsnummer
     * @return PersonId|null
     */
    public function findPersonIdByFodselsnummer(string $fodselsnummer): ?object
    {
        return $this->findPersonIdByNummer($fodselsnummer);
    }
    
    /**
     * Finn PersonId basert på organisasjonsnummer (alias for findPersonIdByNummer)
     * 
     * @param string $organisasjonsnummer 9-sifret organisasjonsnummer
     * @return PersonId|null
     */
    public function findPersonIdByOrganisasjonsnummer(string $organisasjonsnummer): ?object
    {
        return $this->findPersonIdByNummer($organisasjonsnummer);
    }
}
