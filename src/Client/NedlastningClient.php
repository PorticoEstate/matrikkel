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
	 * Get snapshot version payload for current session
	 * Public wrapper for protected method
	 */
	public function getSessionSnapshotVersion(): array
	{
		return $this->getSnapshotVersionPayload();
	}
	
	/**
	 * Hent komplette objekter med manuell XML-serialisering for MatrikkelBubbleId
	 * 
	 * @deprecated Use findObjekterEtterIdWithClassMap() instead!
	 *             This method uses manual XML serialization which is complex
	 *             and error-prone. The classmap approach is much simpler.
	 * 
	 * Denne metoden omgår PHP SOAP-klientens begrensninger med serialisering av 
	 * komplekse nested objekter ved å bygge SOAP-envelope manuelt.
	 * 
	 * @param mixed $matrikkelBubbleId Start-ID for cursor-basert paginering 
	 * @param string $domainklasse Objekttype: Matrikkelenhet, Bygning, etc.
	 * @param string|null $filter Filter-uttrykk
	 * @param int $maksAntall Batch-størrelse
	 * @return array Liste med komplette objekter
	 * 
	 * @throws \SoapFault Hvis SOAP-kall feiler
	 */
	public function findObjekterEtterIdWithManualXml(
		mixed $matrikkelBubbleId,
		string $domainklasse,
		?string $filter,
		int $maksAntall
	): array {
		$envelopeBuilder = new SoapEnvelopeBuilder();
		
		// Prepare MatrikkelBubbleId with proper snapshotVersion
		$bubblePayload = null;
		if ($matrikkelBubbleId !== null) {
			$bubbleValue = null;
			$bubbleSnapshotVersion = null;
			
			if (is_object($matrikkelBubbleId)) {
				$bubbleValue = $matrikkelBubbleId->value ?? null;
				$bubbleSnapshotVersion = $matrikkelBubbleId->snapshotVersion ?? null;
			} elseif (is_array($matrikkelBubbleId)) {
				$bubbleValue = $matrikkelBubbleId['value'] ?? null;
				$bubbleSnapshotVersion = $matrikkelBubbleId['snapshotVersion'] ?? null;
			} else {
				$bubbleValue = (int) $matrikkelBubbleId;
			}
			
			// Ensure bubbleValue is numeric for comparison
			if ($bubbleValue !== null && (int)$bubbleValue > 0) {
				// ONLY send value in cursor, not snapshotVersion
				// The snapshotVersion is handled by matrikkelContext
				$bubblePayload = [
					'value' => $bubbleValue
					// NOTE: Do not include snapshotVersion here!
				];
				
				// Debug: Log what we're sending
				error_log("[DEBUG] MatrikkelBubbleId cursor: value=$bubbleValue (NO snapshotVersion in cursor)");
			}
		}
		
		// Build complete SOAP envelope
		$soapEnvelope = $envelopeBuilder->buildFindObjekterEtterIdEnvelope(
			$bubblePayload,
			$domainklasse,
			$filter,
			$maksAntall,
			$this->getMatrikkelContext()
		);
		
		// Send raw HTTP request
		$options = $this->getOptions();
		$wsdl = $this->getWsdl();
		
		// Extract endpoint from WSDL URL (remove ?WSDL part)
		$endpoint = preg_replace('/\?WSDL.*$/', '', $wsdl);
		
		$headers = [
			'Content-Type: text/xml; charset=utf-8',
			'SOAPAction: ""',
			'Content-Length: ' . strlen($soapEnvelope)
		];
		
		if (isset($options['login']) && isset($options['password'])) {
			$headers[] = 'Authorization: Basic ' . base64_encode($options['login'] . ':' . $options['password']);
		}
		
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => implode("\r\n", $headers),
				'content' => $soapEnvelope,
				'ignore_errors' => true,  // Don't fail on HTTP errors, let us handle them
				'timeout' => 30  // 30 second timeout
			]
		]);
		
		$this->lastRequestParams = [
			'matrikkelBubbleId' => $bubblePayload,
			'domainklasse' => $domainklasse,
			'filter' => $filter,
			'maksAntall' => $maksAntall,
		];
		
		$response = file_get_contents($endpoint, false, $context);
		if ($response === false) {
			// Get HTTP response header for debugging
			$httpResponseHeader = $http_response_header ?? [];
			$statusLine = $httpResponseHeader[0] ?? 'Unknown HTTP error';
			
			throw new \SoapFault('HTTP', 'Failed to send SOAP request: ' . $statusLine . "\nEndpoint: " . $endpoint);
		}
		
		// Check if we got a valid XML response (can start with <?xml or <soap:)
		if (strpos($response, '<?xml') === false && strpos($response, '<soap:') !== 0) {
			$httpResponseHeader = $http_response_header ?? [];
			$statusLine = $httpResponseHeader[0] ?? 'Unknown HTTP error';
			throw new \SoapFault('HTTP', 'Invalid SOAP response: ' . $statusLine . "\nResponse: " . substr($response, 0, 500));
		}
		
		// Check if we got a successful response (starts with <?xml or <soap:)
		if (strpos($response, '<?xml') === 0 || strpos($response, '<soap:') === 0) {
			// Debug: Log first part of response to see structure
			error_log("[DEBUG] SOAP Response (first 3000 chars): " . substr($response, 0, 3000));
			
			// Parse the response to extract objects for pagination
			$dom = new \DOMDocument();
			$dom->loadXML($response);
			
			// Extract return elements using XPath
			$xpath = new \DOMXPath($dom);
			$xpath->registerNamespace('ns2', 'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/service/nedlastning');
			
			$returnNodes = $xpath->query('//ns2:return');
			if ($returnNodes->length === 0) {
				return [];  // Empty response
			}
			
			$returnNode = $returnNodes->item(0);
			if (!$returnNode) {
				return [];  // Empty response
			}
			
			// Get all direct child item elements of <return> (these are the matrikkelenheter)
			// NOTE: Don't use getElementsByTagName as it gets ALL items including nested metadata items
			$items = [];
			
			// Try XPath with namespace, fallback to simple child iteration
			$itemElements = $xpath->query('./ns2:item', $returnNode);
			
			if ($itemElements->length === 0) {
				// Fallback: iterate through direct children manually
				foreach ($returnNode->childNodes as $child) {
					if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'item') {
						$itemElements = new \ArrayObject();
						foreach ($returnNode->childNodes as $childCheck) {
							if ($childCheck->nodeType === XML_ELEMENT_NODE && $childCheck->localName === 'item') {
								$itemElements->append($childCheck);
							}
						}
						break;
					}
				}
			}
			
			foreach ($itemElements as $itemElement) {
				// Convert DOMElement to SimpleXMLElement to preserve namespaces
				$soapObject = simplexml_import_dom($itemElement);
				
				// Extract MatrikkelBubbleId for pagination cursor
				$idValue = null;
				$snapshotVersion = null;
				
				// Access the id element with namespace handling
				if (isset($soapObject->id)) {
					$idElement = $soapObject->id;
					$idValue = (string) ($idElement->value ?? null);
					
					// Debug: Log what snapshotVersion looks like in response
					if (isset($idElement->snapshotVersion)) {
						error_log("[DEBUG] Raw snapshotVersion from API: " . print_r($idElement->snapshotVersion, true));
					}
					
					$snapshotVersion = isset($idElement->snapshotVersion) ? (int) $idElement->snapshotVersion : null;
				}
				
				// Create result object with ID for pagination and full SOAP object
				$item = new \stdClass();
				$item->id = new \stdClass();
				$item->id->value = $idValue;
				if ($snapshotVersion !== null) {
					$item->id->snapshotVersion = $snapshotVersion;
				}
				$item->soapObject = $this->convertSimpleXMLToStdClass($soapObject);  // Convert to stdClass for compatibility
				
				$items[] = $item;
			}
			
			return $items;
		} else {
			throw new \SoapFault('ParseError', 'Invalid SOAP response format');
		}
	}
	
	/**
	 * Convert SimpleXMLElement to stdClass using SOAP client parsing
	 * This ensures the object structure matches what MatrikkelenhetTable expects
	 */
	private function convertSimpleXMLToStdClass(\SimpleXMLElement $xml): \stdClass
	{
		// Get XML string with all namespaces
		$xmlString = $xml->asXML();
		
		// Remove ALL namespace prefixes from element names but keep xmlns declarations
		// This matches how SOAP client parses responses
		$xmlString = preg_replace('/<(\/?)ns\d+:/', '<$1', $xmlString);
		
		// Also handle xsi:type attributes by removing xsi: prefix
		$xmlString = preg_replace('/xsi:type="ns\d+:/', 'xsi:type="', $xmlString);
		
		// Parse back to SimpleXMLElement
		$cleanXml = @simplexml_load_string($xmlString);
		
		if ($cleanXml === false) {
			// If parsing failed, return original as stdClass via JSON
			$json = json_encode($xml);
			return json_decode($json);
		}
		
		// Convert to JSON and back to get stdClass
		$json = json_encode($cleanXml);
		return json_decode($json);
	}
	
	/**
	 * Pre-process arguments før SOAP-kall
	 * 
	 * Overskriver AbstractSoapClient::_preProcessArguments
	 * for å legge til matrikkelContext i parametere.
	 */
	public function _preProcessArguments($arguments): mixed {
		// Call parent first to get matrikkelContext
		return parent::_preProcessArguments($arguments);
	}

	public function getLastRequestParams(): ?array {
		return $this->lastRequestParams;
	}

	private function prepareMatrikkelBubbleIdPayload(mixed $matrikkelBubbleId): mixed {
		if ($matrikkelBubbleId === null) {
			return null;
		}

		if (is_array($matrikkelBubbleId)) {
			// Always ensure snapshotVersion is present, even if missing from API response
			$matrikkelBubbleId['snapshotVersion'] = $this->getSnapshotVersionPayload();
			return $matrikkelBubbleId;
		}

		if (is_object($matrikkelBubbleId)) {
			$normalized = json_decode(json_encode($matrikkelBubbleId), true);
			// Always ensure snapshotVersion is present, even if missing from API response
			$normalized['snapshotVersion'] = $this->getSnapshotVersionPayload();
			return $normalized;
		}

		$numericValue = (int) $matrikkelBubbleId;
		if ($numericValue <= 0) {
			return null;
		}

		// Use SoapVar for proper serialization as we did in manual tests
		$namespace = "http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain";
		$timestamp = $this->getSnapshotVersionTimestamp();
		
		$snapshotVersion = new \SoapVar([
			'timestamp' => $timestamp
		], SOAP_ENC_OBJECT, "Timestamp", $namespace);
		
		return new \SoapVar([
			'value' => $numericValue,
			'snapshotVersion' => $snapshotVersion
		], SOAP_ENC_OBJECT, "MatrikkelBubbleId", $namespace);
	}
	
	/**
	 * Hent komplette objekter med CLASSMAP (RECOMMENDED!)
	 * 
	 * This method uses PHP SoapClient's classmap for automatic serialization.
	 * Much simpler and more reliable than manual XML building!
	 * 
	 * Usage:
	 * ```php
	 * $cursor = null;  // Start
	 * $batchSize = 5000;  // API max
	 * do {
	 *     $batch = $client->findObjekterEtterIdWithClassMap(
	 *         $cursor,
	 *         'Matrikkelenhet',
	 *         '{"kommunefilter": ["4601"]}',
	 *         $batchSize
	 *     );
	 *     
	 *     // Process $batch...
	 *     
	 *     // Get cursor for next batch
	 *     if (!empty($batch)) {
	 *         $lastItem = end($batch);
	 *         $cursor = $lastItem->id;  // MatrikkelBubbleId object
	 *     }
	 * } while (count($batch) === $batchSize);
	 * ```
	 * 
	 * @param MatrikkelBubbleId|null $cursor Cursor from previous batch (null for first)
	 * @param string $domainklasse Object type: Matrikkelenhet, Bygning, Person, etc.
	 * @param string|null $filter JSON filter: {"kommunefilter": ["4601"]}
	 * @param int $maksAntall Batch size (max 5000 for findObjekterEtterId)
	 * @return array List of objects with ->id and ->soapObject properties
	 */
	public function findObjekterEtterIdWithClassMap(
		?MatrikkelBubbleId $cursor,
		string $domainklasse,
		?string $filter,
		int $maksAntall
	): array {
		// Build params - classmap handles MatrikkelBubbleId serialization automatically!
		$params = [
			'matrikkelBubbleId' => $cursor,  // Automatically serialized!
			'domainklasse' => $domainklasse,
			'filter' => $filter,
			'maksAntall' => $maksAntall,
		];
		
		try {
			// Make SOAP call - let classmap do the work!
			$result = $this->__call('findObjekterEtterId', [$params]);
			
			// Parse response
			if (!isset($result->return) || !isset($result->return->item)) {
				return [];  // Empty response
			}
			
			// Ensure array (SOAP returns single object if only one item)
			$items = $result->return->item;
			if (!is_array($items)) {
				$items = [$items];
			}
			
			// Parse each item to standard format
			$parsedItems = [];
			foreach ($items as $item) {
				$parsed = new \stdClass();
				
				// Extract ID for pagination (MatrikkelBubbleId object)
				if (isset($item->id)) {
					$parsed->id = $item->id;
				}
				
				// The actual object data
				$parsed->soapObject = $item;
				
				$parsedItems[] = $parsed;
			}
			
			return $parsedItems;
			
		} catch (\SoapFault $e) {
			error_log("[NedlastningClient::findObjekterEtterIdWithClassMap] SOAP Fault: " . $e->getMessage());
			throw $e;
		}
	}
	
}

