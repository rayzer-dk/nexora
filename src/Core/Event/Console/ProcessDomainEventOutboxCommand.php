<?php

declare(strict_types=1);

namespace Commerce\Core\Event\Console;

use Commerce\Core\Event\DomainEventOutboxWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:events:work', description: 'Process transactional domain-event outbox deliveries.')]
final class ProcessDomainEventOutboxCommand extends Command
{
    public function __construct(private readonly DomainEventOutboxWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events for this run.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(500, (int) $input->getOption('limit')));
        $stats = $this->worker->run($limit);
        $output->writeln(sprintf(
            'claimed=%d delivered=%d partial=%d dead=%d',
            $stats['claimed'],
            $stats['delivered'],
            $stats['partial'],
            $stats['dead'],
        ));
        return Command::SUCCESS;
    }
}
