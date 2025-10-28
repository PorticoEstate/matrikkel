<?php
/**
 * User: ingvar.aasen
 * Date: 2025-05-28
 */

namespace Iaasen\Matrikkel\LocalDb;

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
                // Handle NULL values
                if ($column === null || $column === '') {
                    $adresseRow[$key] = 'NULL';
                } else {
                    // Ensure column is scalar (string/number/boolean)
                    if (is_object($column) || is_array($column)) {
                        throw new \RuntimeException(
                            "Non-scalar value for column '$key' in table {$this->tableName}: " . 
                            gettype($column) . " " . print_r($column, true)
                        );
                    }
                    // Escape single quotes for PostgreSQL
                    $column = str_replace("'", "''", $column);
                    $adresseRow[$key] = "'" . $column . "'";
                }
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

    private function deduplicateRows(array $rows): array
    {
        // Primary key kolonne for hver tabell
        $primaryKeys = [
            'matrikkel_adresser' => ['adresse_id'],
            'matrikkel_kommuner' => ['kommunenummer'],  // Unik naturlig nøkkel
            'matrikkel_matrikkelenheter' => ['matrikkelenhet_id'],
            'matrikkel_bruksenheter' => ['adresse_id', 'bruksenhet'],
            'matrikkel_personer' => ['person_id'],
            'matrikkel_juridiske_personer' => ['juridisk_person_id'],
            // Standardverdi hvis ikke definert
        ];

        $primaryKeyCols = $primaryKeys[$this->tableName] ?? ['id'];

        // Bygg en map med composite key => row data
        $uniqueRows = [];
        foreach ($rows as $row) {
            // Lag composite key fra primærnøkkelkolonnene
            $keyParts = [];
            foreach ($primaryKeyCols as $col) {
                $keyParts[] = $row[$col] ?? '';
            }
            $compositeKey = implode('|', $keyParts);
            
            // Behold siste forekomst av hver unik primærnøkkel
            $uniqueRows[$compositeKey] = $row;
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
            'matrikkel_bruksenheter' => '"bruksenhet_id"',
            'matrikkel_kommuner' => '"kommunenummer"',
            'matrikkel_matrikkelenheter' => '"matrikkelenhet_id"',
            'matrikkel_personer' => '"matrikkel_person_id"',  // Changed from person_id
            'matrikkel_fysiske_personer' => '"id"',
            'matrikkel_juridiske_personer' => '"id"',
            'matrikkel_bygninger' => '"bygning_id"',
            'matrikkel_veger' => '"veg_id"',
            'matrikkel_eierforhold' => '"id"',
            default => '"id"'
        };
        
        return PHP_EOL . 'ON CONFLICT (' . $primaryKeyClause . ') DO UPDATE SET ' . $updateString;
    }

    public function countDbAddressRows() : int {
        $sql = 'SELECT COUNT(*) FROM ' . $this->tableName . ';';
        $result = $this->dbAdapter->query($sql)->execute();
        return current($result->current());
    }

}
