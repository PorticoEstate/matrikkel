<?php
/**
 * User: ingvar.aasen
 * Date: 03.10.2023
 */

namespace Iaasen\Matrikkel\Service;

use Iaasen\Matrikkel\Client\MatrikkelsokClient;
use Iaasen\Matrikkel\Entity\Matrikkelsok\AbstractMatrikkelsok;
use Iaasen\Matrikkel\Entity\Matrikkelsok\Eiendom;
use Iaasen\Matrikkel\Entity\Matrikkelsok\Veg;
use Iaasen\Matrikkel\Entity\Matrikkelsok\Vegadresse;

class MatrikkelsokService {

	public function __construct(
		protected MatrikkelsokClient $matrikkelsokClient,
	) {}


	/**
	 * @param string $search
	 * @param int $limit Maximum number of results to return (default 20)
	 * @param int $offset Starting position for pagination (default 0)
	 * @return AbstractMatrikkelsok[]
	 */
	public function searchAddresses(string $search, int $limit = 20, int $offset = 0) : array {
		$allAddresses = [];
		$batchSize = 20; // The API returns max 20 per call
		
		// Special case: limit = -1 means return ALL results
		$returnAllResults = ($limit === -1);
		if ($returnAllResults) {
			$limit = PHP_INT_MAX; // Set to a very large number to fetch all
		}
		
		// Calculate how many complete batches we need to skip to reach the offset
		$batchesToSkip = (int)floor($offset / $batchSize);
		$offsetWithinBatch = $offset % $batchSize;
		
		// We need to fetch all batches from the beginning until we have enough data
		$currentBatch = 0;
		$targetRecordsCollected = 0;
		$lastBatchItems = null; // Track the last batch to detect repeats
		$lastBatchFirstItem = null;
		$lastBatchLastItem = null;
		
		while ($currentBatch <= $batchesToSkip || $targetRecordsCollected < $limit) {
			// Always start from the beginning - don't use large startPosisjon values
			$startPosition = $currentBatch * $batchSize;
			
			$result = $this->matrikkelsokClient->findTekstelementerForAutoutfylling([
				'sokeStreng' => $search,
				'parametre' => 'OBJEKTTYPE:Vegadresse',
				'returFelter' => [],
				'startPosisjon' => $startPosition,
			]);

			if(!isset($result->return->item)) $items = [];
			elseif(is_string($result->return->item)) $items = [$result->return->item];
			else $items = $result->return->item;

			// If no results in this batch, we've reached the end
			if (empty($items)) {
				break;
			}

			// Check if we're getting the same batch as before (API repeating last batch)
			$currentBatchFirstItem = isset($items[0]) ? $items[0] : null;
			$currentBatchLastItem = isset($items[count($items)-1]) ? $items[count($items)-1] : null;
			
			if ($lastBatchFirstItem !== null && 
				$currentBatchFirstItem === $lastBatchFirstItem && 
				$currentBatchLastItem === $lastBatchLastItem) {
				// We've hit the API limit and it's repeating the last batch
				break;
			}
			
			$lastBatchFirstItem = $currentBatchFirstItem;
			$lastBatchLastItem = $currentBatchLastItem;

			$batchAddresses = [];
			foreach($items AS $item) {
				if($address = self::convertSearchResultToObject($item)) {
					$batchAddresses[] = $address;
				}
			}

			// Only start collecting after we've reached our offset batch
			if ($currentBatch >= $batchesToSkip) {
				// If we're in the first batch of our range, handle partial collection
				if ($currentBatch == $batchesToSkip) {
					// Skip items before our offset within this batch
					$batchAddresses = array_slice($batchAddresses, $offsetWithinBatch);
				}

				// Add batch results to our collection
				$allAddresses = array_merge($allAddresses, $batchAddresses);
				$targetRecordsCollected = count($allAddresses);
			}

			// If we got less than expected from the API, we've reached the end
			if (count($items) < $batchSize) {
				break;
			}

			// Move to next batch
			$currentBatch++;
		}

		// Return only the requested number of results (unless limit=-1 which means all)
		if ($returnAllResults) {
			return $allAddresses;
		} else {
			return array_slice($allAddresses, 0, $limit);
		}
	}


	protected static function convertSearchResultToObject(string $row) : ?AbstractMatrikkelsok {
		$pattern = '/^ ?(' . implode('|', AbstractMatrikkelsok::SEARCH_RESULT_FIELD_NAMES) . '): (.*)/';

		$segments = explode(',', $row);
		$fields = [];
		$lastField = null;
		foreach($segments AS $segment) {
			$matches = [];
			// New field
			if(preg_match($pattern, $segment, $matches)) {
				$fields[$matches[1]] = $matches[2];
				$lastField = $matches[1];
			}
			// Append to last field
			else {
				if(strlen($fields[$lastField])) $fields[$lastField] .= ', ';
				$fields[$lastField] .= trim($segment);
			}
		}

		switch ($fields['OBJEKTTYPE']) {
			case 'VEGADRESSE': return new Vegadresse($fields);
			case 'VEG': return new Veg($fields);
			case 'EIENDOM': return new Eiendom($fields);
			default: return null;
		}
	}

}
