<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime\Console;

use Commerce\Core\Event\DomainEventOutboxWorker;
use Commerce\Modules\Notification\Application\NotificationOutboxWorker;
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
        private readonly NotificationOutboxWorker $notifications,
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
        $notifications = $this->notifications->run($limit);
        $output->writeln(sprintf(
            'Events: claimed=%d delivered=%d partial=%d dead=%d; notifications: scanned=%d sent=%d retried=%d failed=%d',
            $events['claimed'],
            $events['delivered'],
            $events['partial'],
            $events['dead'],
            $notifications['scanned'],
            $notifications['sent'],
            $notifications['retried'],
            $notifications['failed'],
        ));
        return Command::SUCCESS;
    }
}
