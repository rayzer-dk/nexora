<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Console;

use Commerce\Modules\Quality\Application\QualityMonitor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:quality:check', description: 'Run the quality monitor, store the score history and e-mail the store when it degrades.')]
final class QualityCheckCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly QualityMonitor $monitor)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->db->fetchFirstColumn("SELECT id FROM mc_store WHERE status='active' ORDER BY id") as $storeId) {
            $report = $this->monitor->run((int) $storeId);
            $this->monitor->record((int) $storeId, $report, 'cron');
            $output->writeln(sprintf('store=%d score=%d level=%s ok=%d warn=%d fail=%d', $storeId, $report['score'], $report['level'], $report['counts']['ok'], $report['counts']['warn'], $report['counts']['fail']));
        }

        return Command::SUCCESS;
    }
}
