<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime\Console;

use Commerce\Core\Event\DomainEventOutboxWorker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Commerce\Core\Runtime\DeferredWorkerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:work', description: 'Process durable domain events and notifications in one bounded worker pass.')]
final class DeferredWorkCommand extends Command
{
    public function __construct(
        private readonly DomainEventOutboxWorker $events,
        /** @var iterable<DeferredWorkerInterface> */
        #[AutowireIterator('commerce.deferred_worker')] private readonly iterable $workers = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows for each queue', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(500, (int) $input->getOption('limit')));
        $events = $this->events->run($limit);
        $line = sprintf('Events: claimed=%d delivered=%d partial=%d dead=%d', $events['claimed'], $events['delivered'], $events['partial'], $events['dead']);
        foreach ($this->workers as $worker) {
            $counters = [];
            foreach ($worker->run($limit) as $name => $count) {
                $counters[] = $name . '=' . $count;
            }
            $line .= '; ' . $worker->queue() . ': ' . implode(' ', $counters);
        }
        $output->writeln($line);
        return Command::SUCCESS;
    }
}
