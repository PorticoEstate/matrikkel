<?php
/**
 * User: ingvar.aasen
 * Date: 14.09.2023
 */

namespace Iaasen\Matrikkel\Client;

use Iaasen\Exception\InvalidArgumentException;
use Iaasen\Exception\NotAuthenticatedException;
use Iaasen\Exception\NotFoundException;
use Laminas\Soap\Client;

// Import Matrikkel type classes for classmap
use Iaasen\Matrikkel\Client\MatrikkelBubbleId;
use Iaasen\Matrikkel\Client\MatrikkelenhetId;
use Iaasen\Matrikkel\Client\PersonId;
use Iaasen\Matrikkel\Client\BygningId;
use Iaasen\Matrikkel\Client\BruksenhetId;
use Iaasen\Matrikkel\Client\VegId;
use Iaasen\Matrikkel\Client\AdresseId;
use Iaasen\Matrikkel\Client\SnapshotVersion;
use Iaasen\Matrikkel\Client\MatrikkelContext;
use Iaasen\Matrikkel\Client\KoordinatsystemKodeId;

class AbstractSoapClient extends Client {

	public function __construct($wsdl = null, $options = null) {
		// First call parent constructor
		parent::__construct($wsdl, $options);
		
		// Then set our custom options
		$this->setSoapVersion(SOAP_1_1);
		$this->setClassmap($this->getMatrikkelClassMap());
	}
	
	/**
	 * Get classmap for automatic SOAP type serialization
	 * Maps XML types to PHP classes
	 */
	protected function getMatrikkelClassMap(): array {
		return [
			'MatrikkelBubbleId' => MatrikkelBubbleId::class,
			'MatrikkelenhetId' => MatrikkelenhetId::class,
			'PersonId' => PersonId::class,
			'BygningId' => BygningId::class,
			'BruksenhetId' => BruksenhetId::class,
			'VegId' => VegId::class,
			'AdresseId' => AdresseId::class,
			'SnapshotVersion' => SnapshotVersion::class,
			'MatrikkelContext' => MatrikkelContext::class,
			'KoordinatsystemKodeId' => KoordinatsystemKodeId::class,
		];
	}


	public function __call($name, $arguments) : mixed {
		try {
			return parent::__call($name, $arguments);
		}
		catch (\SoapFault $e) {
			throw new \SoapFault($e->getCode(), $e->getMessage());
//			if($e->faultcode == 'S:Client') throw new InvalidArgumentException($e->getMessage());
//			if($e->faultcode == 'HTTP') throw new NotAuthenticatedException('Unable to login. Check that login or password is incorrect');
//			if($e->faultcode == 'S:Server') {
//				if(isset($e->detail?->ServiceException?->enc_stype) && $e->detail?->ServiceException?->enc_stype == 'ObjectsNotFoundFaultInfo') {
//					 throw new NotFoundException($e->detail->ServiceException->enc_value->exceptionDetail->message);
//				}
//			}
//			throw new \Exception($e->getMessage(), $e->getCode());
		}
	}


	public function _preProcessArguments($arguments) : mixed {
		$arguments[0]['matrikkelContext'] = $this->getMatrikkelContext();
		return $arguments;
	}


	public function getMatrikkelContext() : array {
		return [
			'locale' => 'no_NO',
			'brukOriginaleKoordinater' => false,
			'koordinatsystemKodeId' => ['value' => 10], // See Representasjonspunkt::KOORDINATSYSTEM_KODE_ID_OPTIONS
			'systemVersion' => '4.4',
			'klientIdentifikasjon' => $this->getOptions()['login'] ?? null,
			'snapshotVersion' => $this->getSnapshotVersionPayload(),
		];
	}

	protected function getSnapshotVersionTimestamp(): string {
		$timezone = new \DateTimeZone('Europe/Oslo');
		// Use future date (9999-01-01) to get "latest" snapshot
		// This avoids "historiske data" permission errors
		return (new \DateTimeImmutable('9999-01-01 00:00:00', $timezone))->format('Y-m-d\TH:i:sP');
	}

	protected function getSnapshotVersionPayload(): array {
		return [
			'timestamp' => $this->getSnapshotVersionTimestamp(),
		];
	}

}
