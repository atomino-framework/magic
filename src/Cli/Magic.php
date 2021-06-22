<?php namespace Atomino\Magic\Cli;

use Atomino\Bundle\Authenticate\Authenticator;
use Atomino\Core\Cli\Attributes\Command;
use Atomino\Core\Cli\CliCommand;
use Atomino\Core\Cli\CliModule;
use Atomino\Core\Cli\Style;
use Atomino\Core\Config\Config;
use Atomino\Magic\Generator;
use Atomino\Neutrons\CodeFinder;
use DI\Container;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\Output;

class Magic extends CliModule {

	public function __construct(private CodeFinder $codeFinder, private Config $config) { }

	#[Command('magic', description: "Creates magic ui config")]
	protected function entity(CliCommand $command) {
		$command->addArgument('entity', InputArgument::REQUIRED, '', null);
		$command->define(function (Input $input, Output $output, Style $style){
			$generator = new Generator($this->config["entity-namespace"], $this->config['api-namespace'], $this->config['descriptor-path'], $style, $this->codeFinder);
			$entity = $input->getArgument('entity');
			$generator->generate($entity);
		});
	}

}
