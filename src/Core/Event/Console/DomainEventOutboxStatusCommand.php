<?php

declare(strict_types=1);

namespace Commerce\Core\Event\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:events:status', description: 'Show domain-event outbox and delivery health.')]
final class DomainEventOutboxStatusCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connection->fetchAllAssociative('SELECT status,COUNT(*) count FROM mc_outbox_event GROUP BY status ORDER BY status');
        if ($rows === []) {
            $output->writeln('Domain event outbox is empty.');
        } else {
            foreach ($rows as $row) {
                $output->writeln((string) $row['status'] . '=' . (int) $row['count']);
            }
        }
        $deadDeliveries = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_domain_event_delivery WHERE status='dead'");
        $output->writeln('dead_deliveries=' . $deadDeliveries);
        return $deadDeliveries > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
