<?php

declare(strict_types=1);

namespace Commerce\Core\Health\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:queues:status', description: 'Show actionable health of background queues and detect stalled work.')]
final class BackgroundQueueStatusCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outboxPending = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_outbox_event WHERE status IN ('pending','partial','processing')");
        $outboxDead = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_outbox_event WHERE status IN ('dead','delivered_with_failures')");
        $integrationPending = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_integration_sync_queue WHERE status IN ('pending','processing')");
        $integrationDead = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_integration_sync_queue WHERE status='dead'");
        $notificationPending = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_notification_outbox WHERE status IN ('pending','processing')");
        $notificationFailed = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_notification_outbox WHERE status='failed'");
        $stalledIntegrations = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_integration_sync_queue WHERE status='processing' AND (locked_at IS NULL OR locked_at < (NOW(6) - INTERVAL 5 MINUTE))");
        $stalledNotifications = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_notification_outbox WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < (NOW(6) - INTERVAL 15 MINUTE)");

        $oldestOutbox = $this->db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW(6)) FROM mc_outbox_event WHERE status IN ('pending','partial','processing')");
        $oldestIntegration = $this->db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW(6)) FROM mc_integration_sync_queue WHERE status IN ('pending','processing')");
        $oldestNotification = $this->db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW(6)) FROM mc_notification_outbox WHERE status IN ('pending','processing')");

        $output->writeln('outbox.pending=' . $outboxPending . ' dead=' . $outboxDead . ' oldest_s=' . (int) ($oldestOutbox ?: 0));
        $output->writeln('integrations.pending=' . $integrationPending . ' dead=' . $integrationDead . ' stalled=' . $stalledIntegrations . ' oldest_s=' . (int) ($oldestIntegration ?: 0));
        $output->writeln('notifications.pending=' . $notificationPending . ' failed=' . $notificationFailed . ' stalled=' . $stalledNotifications . ' oldest_s=' . (int) ($oldestNotification ?: 0));

        $backlogTooOld = (int) ($oldestOutbox ?: 0) > 900 || (int) ($oldestIntegration ?: 0) > 900 || (int) ($oldestNotification ?: 0) > 900;
        if ($backlogTooOld) {
            $output->writeln('warning=background backlog is older than 15 minutes; verify workers are running');
        }

        return (($outboxDead + $integrationDead + $notificationFailed + $stalledIntegrations + $stalledNotifications) > 0 || $backlogTooOld)
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
