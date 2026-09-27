<?php

declare(strict_types=1);

namespace Commerce\Core\Observability\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:db:observe', description: 'Read safe MySQL/MariaDB health counters for production capacity monitoring.')]
final class DatabaseObservabilityCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wanted=['Threads_connected','Threads_running','Max_used_connections','Connections','Slow_queries','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Innodb_row_lock_waits','Innodb_row_lock_time','Uptime'];
        $metrics=[];
        foreach ($wanted as $name) {
            try { $row=$this->db->fetchAssociative("SHOW GLOBAL STATUS LIKE '" . $name . "'"); if(is_array($row)){$metrics[$name]=(float)array_values($row)[1];} } catch (\Throwable) {}
        }
        $requests=(float)($metrics['Innodb_buffer_pool_read_requests']??0); $reads=(float)($metrics['Innodb_buffer_pool_reads']??0);
        $hit=$requests>0 ? max(0.0, 100.0-(($reads/$requests)*100.0)) : null;
        $output->writeln('Database observability snapshot');
        foreach ($metrics as $name=>$value) { $output->writeln(sprintf('  %-36s %s',$name,(string)$value)); }
        if($hit!==null){$output->writeln(sprintf('  %-36s %.4f%%','buffer_pool_hit_rate',$hit));}
        try {
            $version=(string)$this->db->fetchOne('SELECT VERSION()');
            $output->writeln('  version                              '.$version);
        } catch (\Throwable) {}
        $output->writeln('<comment>Use repeated snapshots plus APM/slow-query logging for p95/p99; one snapshot is not a load test.</comment>');
        return Command::SUCCESS;
    }
}
