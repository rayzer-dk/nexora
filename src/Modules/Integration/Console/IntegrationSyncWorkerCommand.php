<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Console;

use Commerce\Modules\Integration\Application\IntegrationSyncWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'commerce:integration:work',description:'Process Google Commerce and Marketing Hub integration queues.')]
final class IntegrationSyncWorkerCommand extends Command
{
    public function __construct(private readonly IntegrationSyncWorker $worker){parent::__construct();}
    protected function configure(): void {$this->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum jobs','50');}
    protected function execute(InputInterface $input,OutputInterface $output): int {$s=$this->worker->run((int)$input->getOption('limit'));$output->writeln(json_encode($s,JSON_UNESCAPED_SLASHES));return Command::SUCCESS;}
}
