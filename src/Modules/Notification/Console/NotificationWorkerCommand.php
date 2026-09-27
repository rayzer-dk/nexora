<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Console;

use Commerce\Modules\Notification\Application\NotificationOutboxWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:notifications:work', description: 'Process a bounded batch of queued notifications.')]
final class NotificationWorkerCommand extends Command
{
    public function __construct(private readonly NotificationOutboxWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows per run', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->worker->run((int) $input->getOption('limit'));
        $output->writeln(sprintf(
            'Notifications: sent=%d retried=%d failed=%d scanned=%d',
            $stats['sent'],
            $stats['retried'],
            $stats['failed'],
            $stats['scanned'],
        ));
        return Command::SUCCESS;
    }
}
