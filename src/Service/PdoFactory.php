<?php
/**
 * PDO Factory - Creates PDO instance for direct database access
 * 
 * Simple factory to provide PDO instance from environment configuration.
 * Used by services that need direct SQL access (PersonImportService, EierforholdImportService).
 * 
 * @author Sigurd Nes
 * @date 2025-01-23
 */

namespace Iaasen\Matrikkel\Service;

use PDO;

class PdoFactory
{
    public static function create(): PDO
    {
        $host = '10.0.2.15';
        $port = 5435;
        $database = 'matrikkel';

        // Prefer real environment variables, fall back to .env file when running CLI
        $username = getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : ($_ENV['DB_USERNAME'] ?? null);
        $password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? null);

        if (empty($username) || $password === null) {
            // Try to load from .env file at project root
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile) && is_readable($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, '#') === 0) continue;
                    if (!str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    if ($k === 'DB_USERNAME' && !$username) {
                        $username = $v;
                    }
                    if ($k === 'DB_PASSWORD' && ($password === null || $password === '')) {
                        $password = $v;
                    }
                }
            }
        }

        $username = $username ?? 'postgres';
        $password = $password ?? '';
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        return $pdo;
    }
}
