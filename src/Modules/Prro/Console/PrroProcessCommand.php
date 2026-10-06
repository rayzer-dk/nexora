<?php

declare(strict_types=1);

namespace Commerce\Modules\Prro\Console;

use Commerce\Modules\Prro\Application\FiscalizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:prro:process', description: 'Issue fiscal receipts (PRRO) for paid orders when automatic receipts are on.')]
final class PrroProcessCommand extends Command
{
    public function __construct(private readonly FiscalizationService $fiscal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Orders per run', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = $this->fiscal->processPending((int) $input->getOption('limit'));
        $output->writeln('receipts: ' . $done);

        return Command::SUCCESS;
    }
}
