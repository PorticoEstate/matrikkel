<?php
/**
 * Debug command to inspect Matrikkelenhet structure from API
 * 
 * @author Sigurd Nes
 * @date 2025-10-08
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\NedlastningClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'matrikkel:debug-matrikkelenhet', description: 'Debug Matrikkelenhet structure')]
class DebugMatrikkelenhetCommand extends Command
{
    public function __construct(
        private NedlastningClient $nedlastningClient
    ) {
        parent::__construct();
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title("Debug Matrikkelenhet Structure");
        
        // Fetch first matrikkelenhet using NedlastningClient
        $batch = $this->nedlastningClient->findObjekterEtterId(
            0,
            'Matrikkelenhet',
            null,
            1
        );
        
        if (empty($batch)) {
            $io->error("Ingen objekter hentet");
            return Command::FAILURE;
        }
        
        $matrikkelenhet = $batch[0];
        
        $io->section("Matrikkelenhet Object Dump");
        $io->text(print_r($matrikkelenhet, true));
        
        $io->section("Eierforhold Check");
        if (isset($matrikkelenhet->eierforhold)) {
            $io->text("eierforhold exists!");
            $io->text(print_r($matrikkelenhet->eierforhold, true));
        } else {
            $io->warning("eierforhold does NOT exist on object");
        }
        
        $io->section("Object Properties");
        $io->text(print_r(get_object_vars($matrikkelenhet), true));
        
        return Command::SUCCESS;
    }
}
