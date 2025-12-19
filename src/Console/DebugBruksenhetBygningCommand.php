<?php
/**
 * Debug command: Check bruksenheter for specific bygning
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\BruksenhetClient;
use Iaasen\Matrikkel\Client\BygningId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:debug-bruksenhet-bygning',
    description: 'Debug: Check bruksenheter for specific bygning via API and compare with database'
)]
class DebugBruksenhetBygningCommand extends Command
{
    public function __construct(
        private BruksenhetClient $bruksenhetClient,
        private \PDO $db
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'bygning-id',
            InputArgument::REQUIRED,
            'Bygning ID to check'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bygningId = (int) $input->getArgument('bygning-id');
        
        $io->title("Debug: Bruksenheter for bygning $bygningId");
        
        // Step 1: Call API
        $io->section('Step 1: Calling API');
        $io->text('Method: BruksenhetClient->findBruksenheterForByggList()');
        
        try {
            $bygningIdObject = new BygningId($bygningId);
            
            $result = $this->bruksenhetClient->findBruksenheterForByggList([
                'byggIds' => ['item' => [$bygningIdObject]]
            ]);
            
            $io->success('API call successful');
            
            // Parse response
            $bruksenhetIds = [];
            
            if (isset($result->return) && isset($result->return->entry)) {
                $entries = is_array($result->return->entry) 
                    ? $result->return->entry 
                    : [$result->return->entry];
                
                $io->text("Number of entries in response: " . count($entries));
                
                foreach ($entries as $entry) {
                    $returnedBygningId = $entry->key->value ?? null;
                    $io->text("Entry for bygning: $returnedBygningId");
                    
                    if ($returnedBygningId && isset($entry->value) && isset($entry->value->item)) {
                        $bruksenhetIdObjects = is_array($entry->value->item)
                            ? $entry->value->item
                            : [$entry->value->item];
                        
                        $io->text("  Found " . count($bruksenhetIdObjects) . " bruksenhet IDs from API:");
                        
                        foreach ($bruksenhetIdObjects as $idx => $bruksenhetIdObj) {
                            $bruksenhetId = $bruksenhetIdObj->value ?? null;
                            if ($bruksenhetId) {
                                $bruksenhetIds[] = $bruksenhetId;
                                $io->text("  [$idx] Bruksenhet ID: $bruksenhetId");
                            }
                        }
                    }
                }
            } else {
                $io->warning('No entries in response');
                $io->text('Response structure:');
                $io->text(print_r($result, true));
            }
            
            $io->newLine();
            $io->success("Total bruksenheter from API: " . count($bruksenhetIds));
            
            if (empty($bruksenhetIds)) {
                $io->warning('No bruksenheter found!');
                return Command::SUCCESS;
            }
            
            // Step 2: Check database
            $io->section('Step 2: Checking database');
            
            $placeholders = implode(',', array_fill(0, count($bruksenhetIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT bruksenhet_id, bygning_id, matrikkelenhet_id, lopenummer, uuid 
                 FROM matrikkel_bruksenheter 
                 WHERE bruksenhet_id IN ($placeholders)"
            );
            $stmt->execute($bruksenhetIds);
            $dbBruksenheter = $stmt->fetchAll();
            
            $dbBruksenhetIds = array_column($dbBruksenheter, 'bruksenhet_id');
            
            $io->text("Bruksenheter in database: " . count($dbBruksenhetIds));
            
            if (count($dbBruksenhetIds) < count($bruksenhetIds)) {
                $missing = array_diff($bruksenhetIds, $dbBruksenhetIds);
                $io->warning("MISSING from database (" . count($missing) . "):");
                foreach ($missing as $missingId) {
                    $io->text("  - Bruksenhet ID: $missingId");
                }
            } else {
                $io->success('All bruksenheter exist in database');
            }
            
            // Step 3: Show all for this bygning
            $io->section('Step 3: All database records for bygning');
            
            $stmt = $this->db->prepare(
                "SELECT bruksenhet_id, bygning_id, matrikkelenhet_id, lopenummer, uuid
                 FROM matrikkel_bruksenheter 
                 WHERE bygning_id = ?
                 ORDER BY bruksenhet_id"
            );
            $stmt->execute([$bygningId]);
            $bygningBruksenheter = $stmt->fetchAll();
            
            $io->text("Total in DB for bygning $bygningId: " . count($bygningBruksenheter));
            
            $rows = [];
            foreach ($bygningBruksenheter as $row) {
                $rows[] = [
                    $row['bruksenhet_id'],
                    $row['matrikkelenhet_id'],
                    $row['lopenummer'] ?? 'null',
                    substr($row['uuid'] ?? 'null', 0, 20)
                ];
            }
            
            if (!empty($rows)) {
                $io->table(
                    ['Bruksenhet ID', 'Matrikkelenhet', 'Løpenr', 'UUID (first 20)'],
                    $rows
                );
            }
            
            // Final analysis
            $io->section('Analysis');
            
            $io->listing([
                "API returns: " . count($bruksenhetIds) . " bruksenheter",
                "Database has: " . count($bygningBruksenheter) . " bruksenheter for this bygning"
            ]);
            
            if (count($bruksenhetIds) > count($bygningBruksenheter)) {
                $io->error("DISCREPANCY: " . (count($bruksenhetIds) - count($bygningBruksenheter)) . " bruksenheter missing from database");
                return Command::FAILURE;
            } elseif (count($bruksenhetIds) < count($bygningBruksenheter)) {
                $io->warning("Database has MORE than API returned (possible duplicates or old data)");
            } else {
                $io->success("✓ Counts match");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
