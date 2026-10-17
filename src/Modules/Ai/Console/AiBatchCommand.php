<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Console;

use Commerce\Modules\Ai\Application\AiBatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:ai:batch', description: 'Works off the next jobs of AI batches (catalogue translation) within a time budget.')]
final class AiBatchCommand extends Command
{
    public function __construct(private readonly AiBatchService $batches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per run', '20')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Time budget in seconds', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->batches->process(max(1, (int) $input->getOption('limit')), max(1.0, (float) $input->getOption('max-seconds')));
        $output->writeln(sprintf('Done %d, failed %d; waiting %d.%s', $result['done'], $result['failed'], $result['pending'], $result['paused_for_limit'] ? ' Daily AI limit reached: the batch goes on tomorrow.' : ''));

        return Command::SUCCESS;
    }
}
