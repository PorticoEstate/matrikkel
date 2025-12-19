<?php

namespace Iaasen\Matrikkel\Service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Excel export service for Portico hierarchy
 * 
 * Exports the 4-level hierarchy (Eiendom → Bygg → Inngang → Bruksenhet)
 * to Excel with separate sheets for each level.
 * 
 * lokasjonskode is split into loc1, loc2, loc3, loc4 columns.
 * Example:
 *   - "5000" → loc1=5000, loc2=null, loc3=null, loc4=null
 *   - "5000-01" → loc1=5000, loc2=01, loc3=null, loc4=null
 *   - "5000-01-01" → loc1=5000, loc2=01, loc3=01, loc4=null
 *   - "5000-01-01-001" → loc1=5000, loc2=01, loc3=01, loc4=001
 */
class ExcelExportService
{
	/**
	 * Export Portico hierarchy to Spreadsheet object
	 * 
	 * @param array $exportData Result from PorticoExportService::export()
	 * @return Spreadsheet
	 */
	public function export(array $exportData): Spreadsheet
	{
		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0); // Remove default sheet

		// Create sheets for each level
		$this->createEiendomSheet($spreadsheet, $exportData);
		$this->createByggSheet($spreadsheet, $exportData);
		$this->createInngangSheet($spreadsheet, $exportData);
		$this->createBruksenhetSheet($spreadsheet, $exportData);
		$this->createGateSheet($spreadsheet, $exportData);

		return $spreadsheet;
	}

	/**
	 * Create Eiendom (Property) sheet
	 */
	private function createEiendomSheet(Spreadsheet $spreadsheet, array $exportData): void
	{
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle('Eiendom');

		// Headers
		$headers = [
			'loc1',
			'lokasjonskode',
			'matrikkelnummer_tekst',
			'kommunenummer',
			'areal'
		];

		foreach ($headers as $col => $header)
		{
			$sheet->setCellValue([$col + 1, 1], $header);
		}

		$this->formatHeader($sheet, 1, count($headers));

		// Data rows
		$row = 2;
		foreach ($exportData['eiendommer'] as $eiendom)
		{
			$locs = $this->splitLokasjonskode($eiendom['lokasjonskode'] ?? null);

			$sheet->setCellValue([1, $row], $locs['loc1']);
			$sheet->setCellValue([2, $row], $eiendom['lokasjonskode'] ?? '');
			$sheet->setCellValue([3, $row], $eiendom['matrikkelnummer_tekst'] ?? '');
			$sheet->setCellValue([4, $row], $eiendom['kommunenummer']);
			$sheet->setCellValue([5, $row], $eiendom['areal'] ?? '');

			$row++;
		}

		// Auto-width columns
		$this->autoWidth($sheet, 1, count($headers));
	}

	/**
	 * Create Bygg (Building) sheet
	 */
	private function createByggSheet(Spreadsheet $spreadsheet, array $exportData): void
	{
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle('Bygg');

		// Headers
		$headers = [
			'loc1',
			'loc2',
			'lokasjonskode',
			'matrikkel_bygning_nummer',
			'lopenummer_i_eiendom',
			'bygningstype_kode_id',
			'antall_etasjer',
			'bruksareal',
			'byggeaar',
			'representasjonspunkt_x',
			'representasjonspunkt_y',
			'google_maps_url'
		];

		foreach ($headers as $col => $header)
		{
			$sheet->setCellValue([$col + 1, 1], $header);
		}

		$this->formatHeader($sheet, 1, count($headers));

		// Aggregate buildings by matrikkel_bygning_nummer to avoid duplicates across eiendommer
		$aggregated = [];

		foreach ($exportData['eiendommer'] as $eiendom) {
			foreach ($eiendom['bygg'] as $bygg) {
				$mbn = $bygg['matrikkel_bygning_nummer'] ?? null;

				// If building number is missing, treat as unique by bygning_id to avoid merging unrelated rows
				$key = $mbn ?: ('id_' . ($bygg['bygning_id'] ?? uniqid('bygg_', true)));

				if (!isset($aggregated[$key])) {
					// Initialize aggregation bucket
					$aggregated[$key] = [
						'lokasjonskode' => $bygg['lokasjonskode'] ?? null,
						'locs' => $this->splitLokasjonskode($bygg['lokasjonskode'] ?? null),
						'matrikkel_bygning_nummer' => $mbn,
						'lopenummer_i_eiendom' => $bygg['lopenummer_i_eiendom'] ?? null,
						'bygningstype_kode_id' => $bygg['bygningstype_kode_id'] ?? null,
						'antall_etasjer' => $bygg['antall_etasjer'] ?? null,
						'bruksareal_sum' => (float) ($bygg['bruksareal'] ?? 0),
						'byggeaar_min' => $bygg['byggeaar'] ?? null,
						'representasjonspunkt_x' => $bygg['representasjonspunkt_x'] ?? null,
						'representasjonspunkt_y' => $bygg['representasjonspunkt_y'] ?? null,
					];
				} else {
					$bucket = &$aggregated[$key];

					// Choose the smallest lokasjonskode (stable, e.g., 5000-01 < 5000-02) and carry its locs + lopenummer
					$currentLok = $bucket['lokasjonskode'];
					$newLok = $bygg['lokasjonskode'] ?? null;
					if ($newLok !== null && ($currentLok === null || strcmp((string)$newLok, (string)$currentLok) < 0)) {
						$bucket['lokasjonskode'] = $newLok;
						$bucket['locs'] = $this->splitLokasjonskode($newLok);
						$bucket['lopenummer_i_eiendom'] = $bygg['lopenummer_i_eiendom'] ?? $bucket['lopenummer_i_eiendom'];
					}

					// First non-null bygningstype_kode_id
					if ($bucket['bygningstype_kode_id'] === null && ($bygg['bygningstype_kode_id'] ?? null) !== null) {
						$bucket['bygningstype_kode_id'] = $bygg['bygningstype_kode_id'];
					}

					// antall_etasjer: take max
					$etasjer = $bygg['antall_etasjer'] ?? null;
					if ($etasjer !== null) {
						if ($bucket['antall_etasjer'] === null) {
							$bucket['antall_etasjer'] = $etasjer;
						} else {
							$bucket['antall_etasjer'] = max((int)$bucket['antall_etasjer'], (int)$etasjer);
						}
					}

					// bruksareal: sum
					$bucket['bruksareal_sum'] += (float) ($bygg['bruksareal'] ?? 0);

					// byggeaar: min
					$byggAar = $bygg['byggeaar'] ?? null;
					if ($byggAar !== null) {
						if ($bucket['byggeaar_min'] === null) {
							$bucket['byggeaar_min'] = $byggAar;
						} else {
							$bucket['byggeaar_min'] = min((int)$bucket['byggeaar_min'], (int)$byggAar);
						}
					}

					// representasjonspunkt: keep first non-null
					if ($bucket['representasjonspunkt_x'] === null && ($bygg['representasjonspunkt_x'] ?? null) !== null) {
						$bucket['representasjonspunkt_x'] = $bygg['representasjonspunkt_x'];
					}
					if ($bucket['representasjonspunkt_y'] === null && ($bygg['representasjonspunkt_y'] ?? null) !== null) {
						$bucket['representasjonspunkt_y'] = $bygg['representasjonspunkt_y'];
					}
				}
			}
		}

		// Data rows from aggregated buckets
		$row = 2;
		foreach ($aggregated as $bucket) {
			$locs = $bucket['locs'] ?? ['loc1' => null, 'loc2' => null];

			$sheet->setCellValue([1, $row], $locs['loc1']);
			$sheet->setCellValue([2, $row], $locs['loc2']);
			$sheet->setCellValue([3, $row], $bucket['lokasjonskode'] ?? '');
			$sheet->setCellValue([4, $row], $bucket['matrikkel_bygning_nummer'] ?? '');
			$sheet->setCellValue([5, $row], $bucket['lopenummer_i_eiendom'] ?? '');
			$sheet->setCellValue([6, $row], $bucket['bygningstype_kode_id'] ?? '');
			$sheet->setCellValue([7, $row], $bucket['antall_etasjer'] ?? '');
			$sheet->setCellValue([8, $row], $bucket['bruksareal_sum'] ?? '');
			$sheet->setCellValue([9, $row], $bucket['byggeaar_min'] ?? '');
			$sheet->setCellValue([10, $row], $bucket['representasjonspunkt_x'] ?? '');
			$sheet->setCellValue([11, $row], $bucket['representasjonspunkt_y'] ?? '');

			// Build a Google Maps link when both coordinates are present (convert UTM32 → WGS84 DMS)
			if (($bucket['representasjonspunkt_x'] ?? null) !== null && ($bucket['representasjonspunkt_y'] ?? null) !== null) {
				$utmX = (float) $bucket['representasjonspunkt_x'];
				$utmY = (float) $bucket['representasjonspunkt_y'];
				[$latDec, $lonDec] = $this->utm32ToLatLon($utmX, $utmY);
				$latDms = $this->decimalToDmsString($latDec, true);
				$lonDms = $this->decimalToDmsString($lonDec, false);
				$mapsUrl = sprintf('https://www.google.com/maps/place/%s+%s/@%F,%F,17z/', $latDms, $lonDms, $latDec, $lonDec);
				$sheet->setCellValue([12, $row], $mapsUrl);
			} else {
				$sheet->setCellValue([12, $row], '');
			}

			$row++;
		}

		$this->autoWidth($sheet, 1, count($headers));
	}

	/**
	 * Create Inngang (Entrance) sheet
	 */
	private function createInngangSheet(Spreadsheet $spreadsheet, array $exportData): void
	{
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle('Inngang');

		// Headers
		$headers = [
			'loc1',
			'loc2',
			'loc3',
			'lokasjonskode',
			'inngang_id',
			'gatenavn',
			'husnummer',
			'bokstav',
			'veg_id',
			'adressekode',
			'lopenummer_i_bygg',
			'matrikkelnummer_tekst'
		];

		foreach ($headers as $col => $header)
		{
			$sheet->setCellValue([$col + 1, 1], $header);
		}

		$this->formatHeader($sheet, 1, count($headers));

		// Data rows
		$row = 2;
		foreach ($exportData['eiendommer'] as $eiendom)
		{
			foreach ($eiendom['bygg'] as $bygg)
			{
				foreach ($bygg['innganger'] as $inngang)
				{
					$locs = $this->splitLokasjonskode($inngang['lokasjonskode'] ?? null);

					$sheet->setCellValue([1, $row], $locs['loc1']);
					$sheet->setCellValue([2, $row], $locs['loc2']);
					$sheet->setCellValue([3, $row], $locs['loc3']);
					$sheet->setCellValue([4, $row], $inngang['lokasjonskode'] ?? '');
					$sheet->setCellValue([5, $row], $inngang['inngang_id']);
					$sheet->setCellValue([6, $row], $inngang['gatenavn'] ?? '');
					$sheet->setCellValue([7, $row], $inngang['husnummer'] ?? '');
					$sheet->setCellValue([8, $row], $inngang['bokstav'] ?? '');
					$sheet->setCellValue([9, $row], $inngang['veg_id'] ?? '');
					$sheet->setCellValue([10, $row], $inngang['adressekode'] ?? '');
					$sheet->setCellValue([11, $row], $inngang['lopenummer_i_bygg'] ?? '');
					$sheet->setCellValue([12, $row], $eiendom['matrikkelnummer_tekst'] ?? '');

					$row++;
				}
			}
		}

		$this->autoWidth($sheet, 1, count($headers));
	}

	/**
	 * Create Bruksenhet (Dwelling Unit) sheet
	 */
	private function createBruksenhetSheet(Spreadsheet $spreadsheet, array $exportData): void
	{
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle('Bruksenhet');

		// Headers
		$headers = [
			'loc1',
			'loc2',
			'loc3',
			'loc4',
			'lokasjonskode',
			'lopenummer_i_inngang',
			'bruksenhettype_kode_id',
			'etasjeplan_kode_id',
			'etasjenummer',
			'antall_rom',
			'bruksareal',
			'matrikkelnummer_tekst'
		];

		foreach ($headers as $col => $header)
		{
			$sheet->setCellValue([$col + 1, 1], $header);
		}

		$this->formatHeader($sheet, 1, count($headers));

		// Data rows
		$row = 2;
		foreach ($exportData['eiendommer'] as $eiendom)
		{
			foreach ($eiendom['bygg'] as $bygg)
			{
				foreach ($bygg['innganger'] as $inngang)
				{
					foreach ($inngang['bruksenheter'] as $bruksenhet)
					{
						$locs = $this->splitLokasjonskode($bruksenhet['lokasjonskode'] ?? null);

						$sheet->setCellValue([1, $row], $locs['loc1']);
						$sheet->setCellValue([2, $row], $locs['loc2']);
						$sheet->setCellValue([3, $row], $locs['loc3']);
						$sheet->setCellValue([4, $row], $locs['loc4']);
						$sheet->setCellValue([5, $row], $bruksenhet['lokasjonskode'] ?? '');
						$sheet->setCellValue([6, $row], $bruksenhet['lopenummer_i_inngang'] ?? '');
						$sheet->setCellValue([7, $row], $bruksenhet['bruksenhettype_kode_id'] ?? '');
						$sheet->setCellValue([8, $row], $bruksenhet['etasjeplan_kode_id'] ?? '');
						$sheet->setCellValue([9, $row], $bruksenhet['etasjenummer'] ?? '');
						$sheet->setCellValue([10, $row], $bruksenhet['antall_rom'] ?? '');
						$sheet->setCellValue([11, $row], $bruksenhet['bruksareal'] ?? '');
						$sheet->setCellValue([12, $row], $eiendom['matrikkelnummer_tekst'] ?? '');

						$row++;
					}
				}
			}
		}

		$this->autoWidth($sheet, 1, count($headers));
	}

	/**
	 * Create Gater (Streets) sheet
	 */
	private function createGateSheet(Spreadsheet $spreadsheet, array $exportData): void
	{
		if (empty($exportData['gater'])) {
			return;
		}

		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle('Gater');

		$headers = [
			'kommunenummer',
			'adressekode',
			'adressenavn',
		];

		foreach ($headers as $col => $header) {
			$sheet->setCellValue([$col + 1, 1], $header);
		}

		$this->formatHeader($sheet, 1, count($headers));

		$row = 2;
		foreach ($exportData['gater'] as $gate) {
			$sheet->setCellValue([1, $row], $gate['kommunenummer'] ?? '');
			$sheet->setCellValue([2, $row], $gate['adressekode'] ?? '');
			$sheet->setCellValue([3, $row], $gate['adressenavn'] ?? '');
			$row++;
		}

		$this->autoWidth($sheet, 1, count($headers));
	}

	/**
	 * Split lokasjonskode into 4 components
	 * 
	 * Examples:
	 *   "5000" → {loc1: 5000, loc2: null, loc3: null, loc4: null}
	 *   "5000-01" → {loc1: 5000, loc2: 01, loc3: null, loc4: null}
	 *   "5000-01-01" → {loc1: 5000, loc2: 01, loc3: 01, loc4: null}
	 *   "5000-01-01-001" → {loc1: 5000, loc2: 01, loc3: 01, loc4: 001}
	 */
	private function splitLokasjonskode(?string $lokasjonskode): array
	{
		$parts = [
			'loc1' => null,
			'loc2' => null,
			'loc3' => null,
			'loc4' => null,
		];

		if (empty($lokasjonskode))
		{
			return $parts;
		}

		$components = explode('-', $lokasjonskode);

		for ($i = 0; $i < min(4, count($components)); $i++)
		{
			$parts['loc' . ($i + 1)] = $components[$i];
		}

		return $parts;
	}

	/**
	 * Format header row (bold, background color)
	 */
	private function formatHeader(Worksheet $sheet, int $row, int $columnCount): void
	{
		for ($col = 1; $col <= $columnCount; $col++)
		{
			$cellRef = Coordinate::stringFromColumnIndex($col) . $row;
			$style = $sheet->getStyle($cellRef);

			$style->getFont()->setBold(true);
			$style->getFont()->setColor(new Color('FFFFFF'));

			$style->getFill()->setFillType(Fill::FILL_SOLID);
			$style->getFill()->getStartColor()->setARGB('FF4472C4');

			$style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
			$style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
		}
	}

	/**
	 * Auto-width columns
	 */
	private function autoWidth(Worksheet $sheet, int $startCol, int $endCol): void
	{
		for ($col = $startCol; $col <= $endCol; $col++)
		{
			$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
		}
	}

	/**
	 * Convert UTM zone 32 (EPSG:25832) to WGS84 lat/lon (decimal degrees)
	 * Simplified algorithm suitable for Google Maps links.
	 */
	private function utm32ToLatLon(float $x, float $y): array
	{
		// Constants for ETRS89 / UTM zone 32N
		$k0 = 0.9996;
		$a = 6378137.0;
		$e = 0.08181919084262149; // WGS84 eccentricity
		$e1sq = 0.006739496742276434; // e^2 / (1 - e^2)
		$falseEasting = 500000.0;
		$lonOrigin = 9.0; // degrees

		$x = $x - $falseEasting;
		$y = $y;

		$m = $y / $k0;
		$mu = $m / ($a * (1 - pow($e, 2) / 4 - 3 * pow($e, 4) / 64 - 5 * pow($e, 6) / 256));

		$e1 = (1 - sqrt(1 - pow($e, 2))) / (1 + sqrt(1 - pow($e, 2)));
		$j1 = 3 * $e1 / 2 - 27 * pow($e1, 3) / 32;
		$j2 = 21 * pow($e1, 2) / 16 - 55 * pow($e1, 4) / 32;
		$j3 = 151 * pow($e1, 3) / 96;
		$j4 = 1097 * pow($e1, 4) / 512;

		$fp = $mu + $j1 * sin(2 * $mu) + $j2 * sin(4 * $mu) + $j3 * sin(6 * $mu) + $j4 * sin(8 * $mu);

		$sinFp = sin($fp);
		$cosFp = cos($fp);
		$tanFp = tan($fp);

		$c1 = $e1sq * pow($cosFp, 2);
		$t1 = pow($tanFp, 2);
		$r1 = ($a * (1 - pow($e, 2))) / pow(1 - pow($e * $sinFp, 2), 1.5);
		$n1 = $a / sqrt(1 - pow($e * $sinFp, 2));

		$d = $x / ($n1 * $k0);

		// Latitude
		$lat = $fp - ($n1 * $tanFp / $r1) * (
			pow($d, 2) / 2 -
			(5 + 3 * $t1 + 10 * $c1 - 4 * pow($c1, 2) - 9 * $e1sq) * pow($d, 4) / 24 +
			(61 + 90 * $t1 + 298 * $c1 + 45 * pow($t1, 2) - 252 * $e1sq - 3 * pow($c1, 2)) * pow($d, 6) / 720
		);

		// Longitude
		$lon = ($d - (1 + 2 * $t1 + $c1) * pow($d, 3) / 6 + (5 - 2 * $c1 + 28 * $t1 - 3 * pow($c1, 2) + 8 * $e1sq + 24 * pow($t1, 2)) * pow($d, 5) / 120) / $cosFp;
		$lon = deg2rad($lonOrigin) + $lon;

		return [rad2deg($lat), rad2deg($lon)];
	}

	private function decimalToDmsString(float $deg, bool $isLat): string
	{
		$direction = $deg < 0 ? ($isLat ? 'S' : 'W') : ($isLat ? 'N' : 'E');
		$deg = abs($deg);
		$d = floor($deg);
		$minFloat = ($deg - $d) * 60;
		$m = floor($minFloat);
		$s = ($minFloat - $m) * 60;
		return sprintf('%d°%02d\'%0.1f"%s', $d, $m, $s, $direction);
	}
}
