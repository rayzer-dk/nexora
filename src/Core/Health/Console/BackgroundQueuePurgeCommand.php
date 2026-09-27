<?php

declare(strict_types=1);

namespace Commerce\Core\Health\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:queues:purge', description: 'Delete old completed background records while preserving pending, failed and dead work.')]
final class BackgroundQueuePurgeCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retain completed records for this many days', '30');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows removed from each queue per run', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(7, min(365, (int) $input->getOption('days')));
        $limit = max(100, min(50000, (int) $input->getOption('limit')));

        $eventIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM mc_outbox_event WHERE status='delivered' AND completed_at < (NOW(6) - INTERVAL {$days} DAY) ORDER BY id LIMIT {$limit}"
        ));
        $deletedEvents = 0;
        if ($eventIds !== []) {
            $deletedEvents = $this->db->executeStatement('DELETE FROM mc_outbox_event WHERE id IN (?)', [$eventIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }

        $integrationIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM mc_integration_sync_queue WHERE status='completed' AND completed_at < (NOW(6) - INTERVAL {$days} DAY) ORDER BY id LIMIT {$limit}"
        ));
        $deletedIntegrations = 0;
        if ($integrationIds !== []) {
            $deletedIntegrations = $this->db->executeStatement('DELETE FROM mc_integration_sync_queue WHERE id IN (?)', [$integrationIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }

        $notificationIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM mc_notification_outbox WHERE status='sent' AND sent_at < (NOW(6) - INTERVAL {$days} DAY) ORDER BY id LIMIT {$limit}"
        ));
        $deletedNotifications = 0;
        if ($notificationIds !== []) {
            $deletedNotifications = $this->db->executeStatement('DELETE FROM mc_notification_outbox WHERE id IN (?)', [$notificationIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }

        $output->writeln(sprintf('purged: events=%d integrations=%d notifications=%d retention_days=%d', $deletedEvents, $deletedIntegrations, $deletedNotifications, $days));
        return Command::SUCCESS;
    }
}
