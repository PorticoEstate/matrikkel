<?php

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\PorticoExportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:export-spreadsheet',
    description: 'Export Portico hierarchy to Excel spreadsheet',
)]
class ExportSpreadsheetCommand extends Command
{
    public function __construct(
        private PorticoExportService $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'kommune',
            'k',
            InputOption::VALUE_REQUIRED,
            'Municipality number (4 digits)',
            null
        );
        $this->addOption(
            'organisasjonsnummer',
            'o',
            InputOption::VALUE_REQUIRED,
            'Organization number to filter by owner',
            null
        );
        $this->addOption(
            'output',
            'O',
            InputOption::VALUE_REQUIRED,
            'Output file path (default: portico_export_TIMESTAMP.xlsx)',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Get parameters
            $kommune = $input->getOption('kommune');
            $organisasjonsnummer = $input->getOption('organisasjonsnummer');
            $outputPath = $input->getOption('output');

            // Validate kommune if provided
            if ($kommune && (!ctype_digit($kommune) || strlen($kommune) !== 4)) {
                $io->error('Kommune must be a 4-digit number');
                return Command::FAILURE;
            }

            $io->info('Exporting Portico hierarchy to spreadsheet...');

            // Export as spreadsheet
            $spreadsheet = $this->exportService->exportAsSpreadsheet(
                $kommune ? (int)$kommune : null,
                $organisasjonsnummer
            );

            // Determine output path
            if (!$outputPath) {
                $timestamp = (new \DateTime())->format('Y-m-d_His');
                $outputPath = "portico_export_{$timestamp}.xlsx";
            }

            // Create parent directory if needed
            $dir = dirname($outputPath);
            if ($dir !== '.' && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Write to file
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);

            // Get export data for stats
            $exportData = $this->exportService->export(
                $kommune ? (int)$kommune : null,
                $organisasjonsnummer
            );

            $io->success('Spreadsheet exported successfully!');
            $io->section('Export Summary');
            $io->table(['Metric', 'Value'], [
                ['Properties (Eiendommer)', count($exportData['eiendommer'])],
                ['Streets (Gater)', isset($exportData['gater']) ? count($exportData['gater']) : 0],
                ['Output File', $outputPath],
                ['File Size', $this->formatBytes(filesize($outputPath))],
            ]);

            if ($kommune) {
                $io->text("Filtered by kommune: {$kommune}");
            }
            if ($organisasjonsnummer) {
                $io->text("Filtered by organisasjonsnummer: {$organisasjonsnummer}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Export failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
