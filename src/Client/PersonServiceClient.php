<?php
/**
 * PersonServiceClient - SOAP client for PersonService
 * 
 * @author Sigurd Nes
 * Date: 08.10.2025
 */

namespace Iaasen\Matrikkel\Client;

/**
 * SOAP Client for PersonService
 * 
 * Methods available:
 * @method findPersoner(array $request) Find persons by search criteria (PersonsokModel)
 * @method findPerson(array $request) Find person by personnummer (returns PersonId)
 * @method findPersonIdForIdent(array $request) Find PersonId for a given identifier
 * @method findPersonIdsForIdents(array $request) Find PersonIds for multiple identifiers
 * @method findFysiskePersonIds(array $request) Find FysiskPersonIds by personnummer list
 * @method finnesPersonMedSammeIdent(array $request) Check if person exists with same identifier
 */
class PersonServiceClient extends AbstractSoapClient
{
    const WSDL = [
        'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/PersonServiceWS?WSDL',
        'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/PersonServiceWS?WSDL',
    ];
}
