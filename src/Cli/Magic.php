<?php namespace Atomino\Magic\Cli;

use Atomino\Cli\Attributes\Command;
use Atomino\Cli\CliCommand;
use Atomino\Cli\CliModule;
use Atomino\Magic\Generator;
use Symfony\Component\Console\Input\InputArgument;

class Magic extends CliModule{

	#[Command('magic')]
	public function entity():CliCommand{
		return (new class() extends CliCommand{
			protected function exec(mixed $config){
				$generator = new Generator($config["entity-namespace"], $config['api-namespace'], $config['descriptor-path'], $this->style);
				$entity = $this->input->getArgument('entity');
				$generator->generate($entity);
			}
		})
			->addArgument('entity', InputArgument::REQUIRED, '', null);
	}
}