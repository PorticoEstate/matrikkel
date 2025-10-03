<?php
/**
 * User: ingvar.aasen
 * Date: 14.09.2023
 */

namespace Iaasen\Matrikkel\Client;

class SoapClientFactory {

	/** Symfony factory */
	public static function create(string $className) : AbstractSoapClient {
		$options = [
			'login' => $_ENV['MATRIKKELAPI_LOGIN'], 
			'password' => $_ENV['MATRIKKELAPI_PASSWORD']
		];
		
		// Add proxy configuration if available
		if (!empty($_ENV['HTTP_PROXY'])) {
			$options['proxy_host'] = parse_url($_ENV['HTTP_PROXY'], PHP_URL_HOST);
			$options['proxy_port'] = parse_url($_ENV['HTTP_PROXY'], PHP_URL_PORT);
			
			// Add proxy authentication if needed
			if (!empty($_ENV['PROXY_USER'])) {
				$options['proxy_login'] = $_ENV['PROXY_USER'];
				$options['proxy_password'] = $_ENV['PROXY_PASSWORD'] ?? '';
			}
		}
		
		return new $className(
			$className::WSDL[$_ENV['MATRIKKELAPI_ENVIRONMENT'] ?? 'prod'],
			$options
		);
	}
}