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
			'password' => $_ENV['MATRIKKELAPI_PASSWORD'],
		];
		
		// Add proxy configuration if available
		if (!empty($_ENV['HTTP_PROXY'])) {
			$proxyUrl = $_ENV['HTTP_PROXY'];
			$options['proxy_host'] = parse_url($proxyUrl, PHP_URL_HOST);
			$options['proxy_port'] = parse_url($proxyUrl, PHP_URL_PORT);
			
			// Add proxy authentication if needed
			if (!empty($_ENV['PROXY_USER'])) {
				$options['proxy_login'] = $_ENV['PROXY_USER'];
				$options['proxy_password'] = $_ENV['PROXY_PASSWORD'] ?? '';
			}
			
			// Create stream context for WSDL loading through proxy
			// This is necessary because SoapClient proxy options don't apply to WSDL loading
			$streamContext = stream_context_create([
				'http' => [
					'proxy' => 'tcp://' . $options['proxy_host'] . ':' . $options['proxy_port'],
					'request_fulluri' => true,
				],
				'https' => [
					'proxy' => 'tcp://' . $options['proxy_host'] . ':' . $options['proxy_port'],
					'request_fulluri' => true,
				],
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
				],
			]);
			$options['stream_context'] = $streamContext;
		}
		
		return new $className(
			$className::WSDL[$_ENV['MATRIKKELAPI_ENVIRONMENT'] ?? 'prod'],
			$options
		);
	}
}