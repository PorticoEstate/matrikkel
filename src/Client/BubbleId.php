<?php
/**
 * User: ingvar.aasen
 * Date: 15.09.2023
 */

namespace Iaasen\Matrikkel\Client;

/**
 * Generates a SOAP_VAR used for "id" used by StoreService
 */
class BubbleId {
	public string $type;
	public mixed $value;

	const NAMESPACE_BASE = 'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain/';
	const NAMESPACE_DOMAIN = 'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain';
	const NAMESPACES = [
		'AdresseId' => self::NAMESPACE_BASE . 'adresse',
		'BruksenhetId' => self::NAMESPACE_BASE . 'bygning',
		'JuridiskPersonId' => self::NAMESPACE_BASE . 'person',
		'KodelisteId' => self::NAMESPACE_BASE . 'kodeliste',
		'KommuneId' => self::NAMESPACE_BASE . 'kommune',
		'KommunevapenId' => self::NAMESPACE_BASE . 'kommune',
		'KretsId' => self::NAMESPACE_BASE . 'adresse',
		'MatrikkelenhetId' => self::NAMESPACE_BASE . 'matrikkelenhet',
		'PersonId' => self::NAMESPACE_BASE . 'person',
		'PostnummeromradeId' => self::NAMESPACE_BASE . 'adresse',
		'VegId' => self::NAMESPACE_BASE . 'adresse',
		'MatrikkelBubbleId' => self::NAMESPACE_DOMAIN,
	];

	public static function getId(string $id, string $objectType, ?string $objectNamespace = null) : \SoapVar {
		if(!$objectNamespace) {
			$objectNamespace = self::NAMESPACES[$objectType];
		}
		$object = (new self())->setValue($id);
		$object->setType($objectType);
		return new \SoapVar($object, SOAP_ENC_OBJECT, $objectType, $objectNamespace);
	}

	public static function getIds(array $ids, string $objectType, ?string $objectNamespace = null) : array {
		if(!$objectNamespace) {
			$objectNamespace = self::NAMESPACES[$objectType];
		}
		$idObjects = [];
		foreach($ids AS $id) {
			$idObjects[] = self::getId($id, $objectType, $objectNamespace);
		}
		return $idObjects;
	}

	public static function getMatrikkelBubbleId(int $value) : ?\SoapVar {
		if ($value <= 0) {
			return null;
		}
		$object = new \stdClass();
		$object->value = $value;
		return new \SoapVar($object, SOAP_ENC_OBJECT, 'MatrikkelBubbleId', self::NAMESPACE_DOMAIN);
	}

	public function setValue(string $value) : self {
		$this->value = $value;
		return $this;
	}

	public function setType(string $type) : self {
		$this->type = $type;
		return $this;
	}

}
