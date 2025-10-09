<?php
/**
 * User: ingvar.aasen
 * Date: 29.05.2024
 */

namespace Iaasen\Matrikkel\LocalDb;

use Iaasen\DateTime;
use Iaasen\Matrikkel\Entity\Matrikkelsok\Vegadresse;
use Laminas\Db\Adapter\Adapter;

/**
 * Address search service that searches in both old (CSV) and new (SOAP API) address tables
 * for backwards compatibility
 */
class AdresseSokService {
    protected string $oldTableName = 'old_matrikkel_adresser';
    protected string $newTableName = 'matrikkel_adresser';

	public function __construct(
		protected Adapter $dbAdapter
	) {}


	/**
	 * @param string $search
	 * @return Vegadresse[]
	 */
	public function search(string $search): array {
		if(!strlen($search)) return [];

		// Prepare search fields
		$searchContext = preg_split("/[, ]/", $search, -1, PREG_SPLIT_NO_EMPTY);
		$streetName = null;
		$postalCode = null;

		if(preg_match('/\d{4}/', reset($searchContext))) {
			$postalCode = reset($searchContext);
			array_shift($searchContext);
		}
//		$contextCount = count($searchContext);
//		for($i=0; $i < $contextCount; $i++) {
//			$field = array_shift($searchContext);
//			if(preg_match('/\d{4}/', $field)) {
//				$postalCode = $field;
//			}
//			else array_push($searchContext, $field);
//		}
		if(count($searchContext)) {
			$streetName = array_shift($searchContext);
			$streetName = str_replace(['veg', 'vei'], 've_', $streetName);
		}

		// Prepare where search
		$where = [];
		$parameters = [];
		if($streetName) {
			$where[] = "adressenavn LIKE CONCAT(?, '%')";
			$parameters[] = $streetName;
		}
		if($postalCode) {
			$where[] = "postnummer = ?";
			$parameters[] = $postalCode;
		}
		foreach($searchContext AS $context) {
			$context = str_replace(['veg', 'vei'], 've_', $context);
			$where[] = "search_context LIKE CONCAT('%', ?, '%')";
			$parameters[] = $context;
		}

		// Create the query - search in OLD table (CSV-based)
		$oldTable = $this->oldTableName;
		$sql = <<<EOT
		SELECT *
		FROM $oldTable
		EOT;

		$i = 0;
		foreach($where AS $row) {
			$sql .= PHP_EOL . ($i == 0 ? 'WHERE ' : 'AND ') . $row;
			$i++;
		}

		$sql .= PHP_EOL . <<<EOT
		ORDER BY
			CASE
				WHEN fylkesnummer = 50 THEN 0
				ELSE 1
			END,
			adressenavn,
			nummer,
			bokstav,
			poststed
		LIMIT 20;
		EOT;

		// Execute the query
		$request = $this->dbAdapter->query($sql);
		$result = $request->execute($parameters);
		$addresses = [];
		foreach ($result as $row) {
			$addresses[] = self::createMatrikkelSokObject($row);
		}
		
		// TODO: In the future, also search in NEW table (matrikkel_adresser)
		// and merge results, prioritizing newer SOAP API data
		
		return $addresses;
	}


	public static function createMatrikkelSokObject(array $row) : Vegadresse {
		return new Vegadresse([
			'id' => $row['adresse_id'],
			'tittel' => $row['adresse_tekst'] . ', ' . $row['poststed'],
			'navn' => $row['adresse_tekst'],
			'tilhoerighet' => implode(', ', [
				$row['poststed'],
				$row['tettstednavn'],
				$row['kommunenavn'],
				$row['soknenavn'],
			]),
			'kommunenr' => $row['kommunenummer'],
			'kommunenavn' => $row['kommunenavn'],
			'epsg' => $row['epsg'],
			'latitude' => $row['nord'],
			'longitude' => $row['ost'],
			'fylkesnr' => floor($row['kommunenummer']/100),
			'fylkesnavn' => '', // Missing from csv
			// 'objekttype' => '',
			// 'kilde' => '',
			'adressekode' => $row['adressekode'],
			'adressenavn' => $row['adressenavn'],
			'husnr' => $row['nummer'],
			'bokstav' => $row['bokstav'],
			'matrikkelnr' => implode('/', [
				$row['gardsnummer'], $row['bruksnummer'], $row['festenummer'], $row['undernummer']	,
			]),
			'postnr' => $row['postnummer'],
			'poststed' => $row['poststed'],
		]);
	}


	public function getLastDbUpdate() : ?DateTime {
		// Check both old and new tables, return earliest timestamp
		$oldTable = $this->oldTableName;
		$newTable = $this->newTableName;
		
		$sql = "
			SELECT MIN(timestamp_created) as earliest
			FROM (
				SELECT timestamp_created FROM $oldTable
				UNION ALL
				SELECT timestamp_created FROM $newTable WHERE EXISTS (SELECT 1 FROM $newTable LIMIT 1)
			) combined
		";
		$result = $this->dbAdapter->query($sql)->execute();
		if(!$result->count() || !$result->current()['earliest']) return null;
		return new DateTime($result->current()['earliest']);
	}

}
