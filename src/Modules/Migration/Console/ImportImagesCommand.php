<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Console;

use Commerce\Modules\Migration\Application\ImportImageQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:import:images', description: 'Downloads the next photos of imported products from the old shop.')]
final class ImportImagesCommand extends Command
{
    public function __construct(private readonly ImportImageQueue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Photos per run', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->queue->process((int) $input->getOption('limit'), 45.0);
        $counts = $this->queue->counts();
        $output->writeln(sprintf('Stored %d, failed %d; waiting %d.', $result['done'], $result['failed'], $counts['pending']));

        return Command::SUCCESS;
    }
}
