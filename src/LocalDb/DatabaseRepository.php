<?php

namespace Iaasen\Matrikkel\LocalDb;

use PDO;
use PDOException;

/**
 * Base repository class for database operations
 * Provides PDO connection to PostgreSQL database
 */
abstract class DatabaseRepository
{
    protected PDO $pdo;

    public function __construct(
        string $dbHost,
        string $dbPort,
        string $dbName,
        string $dbUsername,
        string $dbPassword
    ) {
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
        
        try {
            $this->pdo = new PDO(
                $dsn,
                $dbUsername,
                $dbPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a query and return all results
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return one result
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute a count query
     */
    protected function fetchCount(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
