<?php
/**
 * User: ingvar.aasen
 * Date: 2025-05-28
 */

namespace Iaasen\Matrikkel\LocalDb;

/**
 * LEGACY: CSV-based bruksenhet import to old_matrikkel_bruksenheter table
 * For new SOAP API-based imports, use BruksenhetImportService instead
 */
class BruksenhetTable extends AbstractTable
{
    protected string $tableName = 'old_matrikkel_bruksenheter';

    public function insertRow(array $row) : void {
        $this->adresseRows[] = [
            'adresse_id' => (int) $row[34],
            'bruksenhet' => $row[15] ?: 'H0101',
        ];

        $this->cachedRows++;
        if($this->cachedRows >= 100) $this->flush();
    }

}
