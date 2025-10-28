<?php
/**
 * User: ingvar.aasen
 * Date: 22.05.2024
 */

namespace Iaasen\Matrikkel\LocalDb\deprecated;

use Iaasen\Debug\Timer;
use Iaasen\Matrikkel\LocalDb\deprecated\AdresseTable;
use Iaasen\Matrikkel\LocalDb\deprecated\BruksenhetTable;
use SplFileObject;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

class AdresseImportService {

    const CACHE_FOLDER = 'data/cache';
    const CSV_FILENAME = 'matrikkelenAdresseLeilighetsniva.csv';
    const ZIP_FILE = self::CACHE_FOLDER.'/matrikkel-address-import.zip';
    protected string $list;

    public static $settings = [
        'norge' => [
            'adresse_url' => 'https://nedlasting.geonorge.no/geonorge/Basisdata/MatrikkelenAdresse/CSV/Basisdata_0000_Norge_25833_MatrikkelenAdresse_CSV.zip',
            'extract_folder' => self::CACHE_FOLDER. '/Basisdata_0000_Norge_25833_MatrikkelenAdresse_CSV',
            'extract_filename' => 'matrikkelenAdresse.csv',
            'row_count' => 2589100,
        ],
        'norge_leilighetsnivaa' => [
            'adresse_url' => 'https://nedlasting.geonorge.no/geonorge/Basisdata/MatrikkelenAdresseLeilighetsniva/CSV/Basisdata_0000_Norge_25833_MatrikkelenAdresseLeilighetsniva_CSV.zip',
            'extract_folder' => self::CACHE_FOLDER. '/Basisdata_0000_Norge_25833_MatrikkelenAdresseLeilighetsniva_CSV',
            'extract_filename' => 'matrikkelenAdresseLeilighetsniva.csv',
            'row_count' => 3580100,
        ],
        'trondelag' => [
            'adresse_url' => 'https://nedlasting.geonorge.no/geonorge/Basisdata/MatrikkelenAdresse/CSV/Basisdata_50_Trondelag_25833_MatrikkelenAdresse_CSV.zip',
            'extract_folder' => self::CACHE_FOLDER. '/Basisdata_50_Trondelag_25833_MatrikkelenAdresse_CSV',
            'extract_filename' => 'matrikkelenAdresse.csv',
            'row_count' => 248900,
        ],
        'trondelag_leilighetsnivaa' => [
            'adresse_url' => 'https://nedlasting.geonorge.no/geonorge/Basisdata/MatrikkelenAdresseLeilighetsniva/CSV/Basisdata_50_Trondelag_25833_MatrikkelenAdresseLeilighetsniva_CSV.zip',
            'extract_folder' => self::CACHE_FOLDER . '/Basisdata_50_Trondelag_25833_MatrikkelenAdresseLeilighetsniva_CSV',
            'extract_filename' => 'matrikkelenAdresseLeilighetsniva.csv',
            'row_count' => 340900,
        ],
    ];

    public function __construct(
        protected AdresseTable $adresseTable,
        protected BruksenhetTable $bruksenhetTable,
    ) {}

    public function importAddresses(SymfonyStyle $io, string $list) : bool
    {
		Timer::setStart();
        $this->list = $list;

        $io->write('Download the import file... ');
		$success = $this->downloadFile($list);
		if(!$success) {
            $error = error_get_last();
            $errorMessage = $error ? $error['message'] : 'Unknown error';
			$io->writeln('<error>Failed</error>');
            $io->writeln('Failed to download file from ' . self::$settings[$list]['adresse_url']);
            $io->writeln('Error: ' . $errorMessage);
            $io->writeln('');
            $io->writeln('Troubleshooting tips:');
            $io->writeln('1. Check your internet connection');
            $io->writeln('2. Verify proxy settings in .env file');
            $io->writeln('3. Try running: docker compose exec app ping nedlasting.geonorge.no');
			return false;
		}
        $io->writeln('<info>Success</info>');

		$io->write('Extract the CSV file from the ZIP file... ');
        $success = $this->extractFile();
        if(!$success) {
            $io->writeln('Failed to extract the zip-file: '.self::ZIP_FILE);
            return false;
        }

		$fileObject = $this->openImportFile($list);
		$io->writeln('<info>Success</info>');

        // It takes very long time to count the number of lines in the file. Hardcoding an estimate instead
		//$fileLineCount = $this->countFileLines($fileObject);
        $fileLineCount = self::$settings[$list]['row_count'];

		$io->writeln('The file has about ' . $fileLineCount . ' lines');

		$io->writeln('Import addresses');
		$progressBar = $io->createProgressBar($fileLineCount);

        // Skip header row
        $fileObject->next();

		$count = 0;
		while($row = $fileObject->fgetcsv()) {
			if($row[2] == 'vegadresse') {
                if(str_contains($list, 'leilighetsnivaa')) {
                    $this->adresseTable->insertRowLeilighetsnivaa($row);
                    $this->bruksenhetTable->insertRow($row);
                }
                else {
                    $this->adresseTable->insertRow($row);
                }
            }
            $count++;
            if($count % 100 === 0) {
                $progressBar->setProgress($count);
            }
		}

        $progressBar->finish();
        $this->closeFile($fileObject);
        $this->deleteImportFiles($list);
        $io->writeln('');

        $this->adresseTable->flush();
        $this->bruksenhetTable->flush();

		$oldRows = $this->adresseTable->deleteOldRows();
		$io->writeln('');
		$io->writeln('Deleted ' . $oldRows . ' old address rows');

        if(str_contains($list, 'leilighetsnivaa')) {
            $oldRows = $this->bruksenhetTable->deleteOldRows();
            $io->writeln('Deleted ' . $oldRows . ' old bruksenhet rows');
        }
        else {
            $io->writeln('Truncated the bruksenhet table');
            $this->bruksenhetTable->truncateTable();
        }

        $io->writeln('');
		$io->writeln($count . ' street address rows imported');
		$io->writeln('The address table now contains ' . $this->adresseTable->countDbAddressRows() . ' addresses');
		$io->info('Completed in ' . round(Timer::getElapsed(), 3) . ' seconds');
		return true;
	}

	protected function downloadFile(string $list) : bool
    {
        $url = self::$settings[$list]['adresse_url'];
        $zipFile = self::ZIP_FILE;
        
        // Ensure the cache directory exists
        if (!is_dir(self::CACHE_FOLDER)) {
            mkdir(self::CACHE_FOLDER, 0755, true);
        }
        
        // Initialize cURL session
        $ch = curl_init();
        
        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false, // Don't return data, write directly to file
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_TIMEOUT => 300, // 5 minutes
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Matrikkel-Client/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOPROGRESS => false, // Enable progress reporting
            CURLOPT_PROGRESSFUNCTION => function($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) {
                // Optional: could add progress reporting here
                return 0; // Continue download
            },
        ]);
        
        // Add proxy settings if available
        if (!empty($_ENV['HTTP_PROXY'] ?? $_ENV['http_proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $_ENV['HTTP_PROXY'] ?? $_ENV['http_proxy']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
        
        // Open file for writing
        $fp = fopen($zipFile, 'w+');
        if (!$fp) {
            curl_close($ch);
            return false;
        }
        
        // Set file as output target
        curl_setopt($ch, CURLOPT_FILE, $fp);
        
        // Execute the download
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Clean up
        curl_close($ch);
        fclose($fp);
        
        // Check for errors
        if (!$success || $httpCode !== 200) {
            if ($error) {
                error_log("cURL Error: $error (HTTP $httpCode)");
            }
            // Remove partial file on failure
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
            return false;
        }
        
        return file_exists($zipFile) && filesize($zipFile) > 0;
	}

    protected function extractFile(): bool
    {
        $zip = new ZipArchive();
        $zip->open(self::ZIP_FILE);
        $success = $zip->extractTo(self::CACHE_FOLDER);
        $zip->close();
        return $success;
    }

	protected function openImportFile(string $list) : SplFileObject
    {
		$fileObject = new SplFileObject(self::$settings[$list]['extract_folder'].'/'.self::$settings[$list]['extract_filename'], 'r');
		$fileObject->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::READ_CSV);
        $fileObject->setCsvControl(';');
		return $fileObject;
	}

	protected function closeFile(SplFileObject &$fileObject) : void
    {
		$fileObject = null;
	}

	protected function deleteImportFiles(string $list) : void
    {
		unlink(self::$settings[$list]['extract_folder'].'/'.self::$settings[$list]['extract_filename']);
        rmdir(self::$settings[$list]['extract_folder']);
        unlink(self::ZIP_FILE);
	}

	public function countFileLines(SplFileObject $fileObject) : int
    {
		$count = 0;
		while(!$fileObject->eof()) {
			$count++;
			$fileObject->next();
		}
		$fileObject->rewind();
		return $count;
	}

}
