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
			'representasjonspunkt_y'
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
				$locs = $this->splitLokasjonskode($bygg['lokasjonskode'] ?? null);

				$sheet->setCellValue([1, $row], $locs['loc1']);
				$sheet->setCellValue([2, $row], $locs['loc2']);
				$sheet->setCellValue([3, $row], $bygg['lokasjonskode'] ?? '');
				$sheet->setCellValue([4, $row], $bygg['matrikkel_bygning_nummer'] ?? '');
				$sheet->setCellValue([5, $row], $bygg['lopenummer_i_eiendom'] ?? '');
				$sheet->setCellValue([6, $row], $bygg['bygningstype_kode_id'] ?? '');
				$sheet->setCellValue([7, $row], $bygg['antall_etasjer'] ?? '');
				$sheet->setCellValue([8, $row], $bygg['bruksareal'] ?? '');
				$sheet->setCellValue([9, $row], $bygg['byggeaar'] ?? '');
				$sheet->setCellValue([10, $row], $bygg['representasjonspunkt_x'] ?? '');
				$sheet->setCellValue([11, $row], $bygg['representasjonspunkt_y'] ?? '');

				$row++;
			}
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
			'lopenummer_i_bygg'
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
			'bruksareal'
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
}
