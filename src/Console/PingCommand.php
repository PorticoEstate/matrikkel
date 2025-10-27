<?php
/**
 * User: ingvar.aasen
 * Date: 13.09.2023
 */

namespace Iaasen\Matrikkel\Console;

use Iaasen\Matrikkel\Client\KommuneClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'matrikkel:ping', description: 'Test the SOAP-connection')]
class PingCommand extends AbstractCommand {

	public function __construct(
		protected KommuneClient $kommuneClient,
	) {
		parent::__construct();
	}


	public function execute(InputInterface $input, OutputInterface $output) : int {
		$this->io->title('MatrikkelAPI ping');
		try {
			// Test med findAlleKommuner - enkel SOAP-operasjon
			$params = [
				'MatrikkelContext' => $this->kommuneClient->getMatrikkelContext()
			];
			$result = $this->kommuneClient->__call('findAlleKommuner', [$params]);
			
			if ($result && isset($result->return)) {
				$kommuner = is_array($result->return) ? $result->return : [$result->return];
				$count = count($kommuner);
				$this->io->success("✅ SOAP connection OK! Matrikkel API is reachable.");
				$this->io->writeln("   Retrieved {$count} kommuner from KommuneServiceWS");
			} else {
				$this->io->warning('SOAP connection works but returned unexpected result');
			}
		}
		catch (\Exception $e) {
			$this->io->error($e->getCode() . ' : ' . $e->getMessage());
			$this->io->error('❌ SOAP connection failed - Matrikkel API is not reachable');
			return Command::FAILURE;
		}
		return Command::SUCCESS;
	}

}
