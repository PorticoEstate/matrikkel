<?php

namespace Iaasen\Matrikkel\Client;

class BygningClient extends AbstractSoapClient {
	const WSDL = [
		'prod' => 'https://matrikkel.no/matrikkelapi/wsapi/v1/BygningServiceWS?WSDL',
		'test' => 'https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/BygningServiceWS?WSDL',
	];
}
