#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env file
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

echo "=== PROXY CONFIGURATION ===\n";
echo "HTTP_PROXY (raw): ";
var_dump($_ENV['HTTP_PROXY'] ?? 'NOT SET');

echo "\nHTTPS_PROXY (raw): ";
var_dump($_ENV['HTTPS_PROXY'] ?? 'NOT SET');

if (isset($_ENV['HTTP_PROXY'])) {
    $proxy = $_ENV['HTTP_PROXY'];
    if (is_array($proxy)) {
        echo "\nHTTP_PROXY is an array - using first element\n";
        $proxy = reset($proxy);
    }
    
    echo "\nParsing proxy URL: $proxy\n";
    $host = parse_url($proxy, PHP_URL_HOST);
    $port = parse_url($proxy, PHP_URL_PORT);
    
    echo "  - Host: $host\n";
    echo "  - Port: $port\n";
}

echo "\n=== TESTING SOAP CLIENT CREATION ===\n";
try {
    $factory = new \Iaasen\Matrikkel\Client\SoapClientFactory();
    $client = $factory->create(\Iaasen\Matrikkel\Client\KommuneClient::class);
    echo "✓ SOAP client created successfully\n";
    
    // Try to call findKommuneById
    echo "\nTesting API call (findKommuneById 4601)...\n";
    $result = $client->findKommuneById(['kommuneId' => ['value' => 4601]]);
    echo "✓ API call successful: " . ($result->return->navn ?? 'NO NAME') . "\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
