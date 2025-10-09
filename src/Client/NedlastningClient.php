<?php
/**
 * NedlastningClient - SOAP Client for bulk-nedlasting av Matrikkel-data
 * 
 * Denne klienten bruker NedlastningServiceWS for effektiv bulk-nedlasting
 * av store datamengder med cursor-basert paginering.
 * 
 * Metoder:
 * - findIdsEtterId: Henter kun ID-er (rask)
 * - findObjekterEtterId: Henter komplette objekter (anbefalt for import)
 * 
 * Domainklasser støttet:
 * - Kommune, Fylke
 * - Matrikkelenhet, Grunneiendom, Festegrunn, Seksjon
 * - Bygg, Bygning, Bygningsendring
 * - Bruksenhet
 * - Adresse, Vegadresse, Matrikkeladresse, Veg
 * - Teig, Teiggrense
 * - Kulturminne
 * - og mange flere...
 * 
 * Eksempel bruk:
 * ```php
 * $client = new NedlastningClient($wsdl, $options);
 * 
 * // Hent alle matrikkelenheter for Oslo kommune
 * $lastId = 0;
 * $maxAntall = 1000;
 * do {
 *     $result = $client->findObjekterEtterId(
 *         $lastId,
 *         'Matrikkelenhet',
 *         'kommunenummer=0301', // Filter-syntaks må testes
 *         $maxAntall
 *     );
 *     
 *     foreach ($result as $object) {
 *         // Lagre til database
 *         $lastId = $object->id->value;
 *     }
 * } while (count($result) === $maxAntall);
 * ```
 * 
 * @author Sigurd Nes
 * @date 2025-10-07
 */

namespace Iaasen\Matrikkel\Client;

class NedlastningClient extends AbstractSoapClient {
	private ?array $lastRequestParams = null;
	
	/**
	 * WSDL URLs for NedlastningServiceWS
	 */
	const WSDL = [
		'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/NedlastningServiceWS?WSDL',
		'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/NedlastningServiceWS?WSDL',
	];
	
	/**
	 * Hent kun ID-er for objekter (rask, minimal data)
	 * 
	 * Denne metoden returnerer kun ID-er uten komplette objekter.
	 * Bruk dette for å sjekke hvilke objekter som finnes eller 
	 * sammenligne med lokal database.
	 * 
	 * @param int $matrikkelBubbleId Start-ID for cursor-basert paginering (bruk 0 for første batch)
	 * @param string $domainklasse Objekttype: Kommune, Matrikkelenhet, Bygning, etc.
	 * @param string|null $filter Filter-uttrykk (f.eks. "kommunenummer=0301")
	 * @param int $maksAntall Batch-størrelse (anbefalt: 1000)
	 * @return array Liste med MatrikkelBubbleId objekter
	 * 
	 * @throws \SoapFault Hvis SOAP-kall feiler
	 */
	public function findIdsEtterId(
		mixed $matrikkelBubbleId,
		string $domainklasse,
		?string $filter,
		int $maksAntall
	): array {
		$params = [
			'matrikkelBubbleId' => $this->prepareMatrikkelBubbleIdPayload($matrikkelBubbleId),
			'domainklasse' => $domainklasse,
			'filter' => $filter,
			'maksAntall' => $maksAntall,
		];

		$this->lastRequestParams = $params;
		$result = $this->__call('findIdsEtterId', [$params]);
		
		// Returner item-array eller tom array
		// SOAP kan returnere enkelt objekt hvis bare ett element, så sikre alltid array
		$items = $result->return->item ?? [];
		if (!is_array($items)) {
			$items = [$items];
		}
		return $items;
	}
	
	/**
	 * Hent komplette objekter med all data (anbefalt for bulk-import)
	 * 
	 * Denne metoden returnerer komplette objekter med all data.
	 * Bruk cursor-basert paginering ved å ta siste ID fra resultatet
	 * og bruke det som matrikkelBubbleId i neste kall.
	 * 
	 * Cursor-basert paginering:
	 * 1. Start med matrikkelBubbleId = 0
	 * 2. Hent batch med maksAntall objekter
	 * 3. Ta id->value fra siste objekt
	 * 4. Bruk dette som matrikkelBubbleId i neste kall
	 * 5. Gjenta til færre enn maksAntall returneres
	 * 
	 * @param int $matrikkelBubbleId Start-ID for cursor-basert paginering (bruk 0 for første batch)
	 * @param string $domainklasse Objekttype: Kommune, Matrikkelenhet, Bygning, Bruksenhet, etc.
	 * @param string|null $filter Filter-uttrykk (syntaks må testes - f.eks. "kommunenummer=0301")
	 * @param int $maksAntall Batch-størrelse (anbefalt: 1000, maks varierer per objekttype)
	 * @return array Liste med komplette objekter av angitt domainklasse
	 * 
	 * @throws \SoapFault Hvis SOAP-kall feiler
	 * 
	 * Eksempel:
	 * ```php
	 * $lastId = 0;
	 * do {
	 *     $batch = $client->findObjekterEtterId($lastId, 'Matrikkelenhet', 'kommunenummer=0301', 1000);
	 *     foreach ($batch as $obj) {
	 *         // Prosesser objekt
	 *         $lastId = $obj->id->value;
	 *     }
	 * } while (count($batch) === 1000);
	 * ```
	 */
	public function findObjekterEtterId(
		mixed $matrikkelBubbleId,
		string $domainklasse,
		?string $filter,
		int $maksAntall
	): array {
		$params = [
			'matrikkelBubbleId' => $this->prepareMatrikkelBubbleIdPayload($matrikkelBubbleId),
			'domainklasse' => $domainklasse,
			'filter' => $filter,
			'maksAntall' => $maksAntall,
		];

		$this->lastRequestParams = $params;
		$result = $this->__call('findObjekterEtterId', [$params]);
		
		// Returner item-array eller tom array
		// SOAP kan returnere enkelt objekt hvis bare ett element, så sikre alltid array
		$items = $result->return->item ?? [];
		if (!is_array($items)) {
			$items = [$items];
		}
		return $items;
	}
	
	/**
	 * Pre-process arguments før SOAP-kall
	 * 
	 * Overskriver AbstractSoapClient::_preProcessArguments
	 * for å legge til matrikkelContext i parametere.
	 */
	public function _preProcessArguments($arguments): mixed {
		// Legg til matrikkelContext i første argument-array
		if (isset($arguments[0]) && is_array($arguments[0])) {
			$arguments[0]['matrikkelContext'] = $this->getMatrikkelContext();
		}
		return $arguments;
	}

	public function getLastRequestParams(): ?array {
		return $this->lastRequestParams;
	}

	private function prepareMatrikkelBubbleIdPayload(mixed $matrikkelBubbleId): ?array {
		if ($matrikkelBubbleId === null) {
			return null;
		}

		if (is_array($matrikkelBubbleId)) {
			if (!isset($matrikkelBubbleId['snapshotVersion'])) {
				$matrikkelBubbleId['snapshotVersion'] = $this->getSnapshotVersionPayload();
			}
			return $matrikkelBubbleId;
		}

		if (is_object($matrikkelBubbleId)) {
			$normalized = json_decode(json_encode($matrikkelBubbleId), true);
			if (!isset($normalized['snapshotVersion'])) {
				$normalized['snapshotVersion'] = $this->getSnapshotVersionPayload();
			}
			return $normalized;
		}

		$numericValue = (int) $matrikkelBubbleId;
		if ($numericValue <= 0) {
			return null;
		}

		return [
			'value' => $numericValue,
			'snapshotVersion' => $this->getSnapshotVersionPayload(),
		];
	}
	
}
