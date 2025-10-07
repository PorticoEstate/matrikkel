<?php
/**
 * User: ingvar.aasen
 * Date: 2025-05-28
 */

namespace Iaasen\Matrikkel\LocalDb;

use Iaasen\DateTime;
use Laminas\Db\Adapter\Adapter;

class AbstractTable
{
    protected string $tableName;
    protected array $adresseRows = [];
    protected int $cachedRows = 0;

    public function __construct(
        protected Adapter $dbAdapter
    ) {}

    public function flush() : void
    {
        if(!count($this->adresseRows)) return;

        // Deduplicate rows based on primary key to avoid ON CONFLICT errors
        $uniqueRows = $this->deduplicateRows($this->adresseRows);

        $sql = $this->getStartInsert();
        $valueRows = [];
        foreach($uniqueRows as $adresseRow) {
            foreach($adresseRow AS $key => $column) {
                // Escape single quotes for PostgreSQL
                $column = str_replace("'", "''", $column);
                $adresseRow[$key] = "'" . $column . "'";
            }
            $valueRows[] .= '(' . implode(',', $adresseRow) . ')';
        }
        $sql .= implode(",\n", $valueRows);
        $sql .= $this->getOnConflictClause();
        $sql .= ';';
        $this->dbAdapter->query($sql)->execute();
        $this->adresseRows = [];
        $this->cachedRows = 0;
    }

    protected function deduplicateRows(array $rows) : array
    {
        // Determine primary key columns based on table name
        $primaryKeyColumns = match($this->tableName) {
            'matrikkel_adresser' => ['adresse_id'],
            'matrikkel_bruksenheter' => ['adresse_id', 'bruksenhet'],
            default => ['id']
        };

        $uniqueRows = [];
        foreach ($rows as $row) {
            // Create a composite key from primary key columns
            $key = implode('|', array_map(fn($col) => $row[$col] ?? '', $primaryKeyColumns));
            // Keep the last occurrence of each unique key (overwrites duplicates)
            $uniqueRows[$key] = $row;
        }

        return array_values($uniqueRows);
    }

    public function getStartInsert() : string
    {
        $columnNames = array_keys(current($this->adresseRows));
        $columnsString = array_map(function ($column) { return '"' . $column . '"'; }, $columnNames);
        $columnsString = implode(',', $columnsString);
        $columnsString = '(' . $columnsString . ')';
        
        // Use PostgreSQL INSERT ... ON CONFLICT instead of MySQL REPLACE INTO
        $updateColumns = array_map(function ($column) { 
            return '"' . $column . '" = EXCLUDED."' . $column . '"'; 
        }, $columnNames);
        $updateString = implode(', ', $updateColumns);
        
        return 'INSERT INTO ' . $this->tableName . ' ' . $columnsString . PHP_EOL . 
               'VALUES' . PHP_EOL;
    }
    
    public function getOnConflictClause() : string
    {
        $columnNames = array_keys(current($this->adresseRows));
        $updateColumns = array_map(function ($column) { 
            return '"' . $column . '" = EXCLUDED."' . $column . '"'; 
        }, $columnNames);
        $updateString = implode(', ', $updateColumns);
        
        // Determine primary key based on table name
        $primaryKeyClause = match($this->tableName) {
            'matrikkel_adresser' => '"adresse_id"',
            'matrikkel_bruksenheter' => '"adresse_id", "bruksenhet"',
            default => '"id"'
        };
        
        return PHP_EOL . 'ON CONFLICT (' . $primaryKeyClause . ') DO UPDATE SET ' . $updateString;
    }

    public function deleteOldRows() : int
    {
        $date = new DateTime();
        $date->modify('-3 hour'); // Go back 3 hours to get before UTC in case of timezone errors
        $sql = 'DELETE FROM ' . $this->tableName . ' WHERE timestamp_created < \'' . $date->formatMysql() . '\';';
        $result = $this->dbAdapter->query($sql)->execute();
        return $result->getAffectedRows();
    }

    public function countDbAddressRows() : int {
        $sql = 'SELECT COUNT(*) FROM ' . $this->tableName . ';';
        $result = $this->dbAdapter->query($sql)->execute();
        return current($result->current());
    }

    public function truncateTable(): void
    {
        if(!str_starts_with($this->tableName, 'matrikkel')) return;
        $sql = 'TRUNCATE TABLE ' . $this->tableName . ';';
        $this->dbAdapter->query($sql)->execute();
    }

}
