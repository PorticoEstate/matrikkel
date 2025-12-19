<?php
/**
 * Test command: Test completeMissingBruksenheterForBygninger for specific bygning
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Service\BruksenhetImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matrikkel:test-complete-bruksenheter',
    description: 'Test: Complete missing bruksenheter for specific bygning'
)]
class TestCompleteBruksenheterCommand extends Command
{
    public function __construct(
        private BruksenhetImportService $bruksenhetImportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'bygning-id',
            InputArgument::REQUIRED,
            'Bygning ID to complete bruksenheter for'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bygningId = (int) $input->getArgument('bygning-id');
        
        $io->title("Test: Complete missing bruksenheter for bygning $bygningId");
        
        try {
            $imported = $this->bruksenhetImportService->completeMissingBruksenheterForBygninger(
                $io,
                [$bygningId]
            );
            
            if ($imported > 0) {
                $io->success("Imported $imported missing bruksenheter");
            } else {
                $io->info("No missing bruksenheter found");
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
