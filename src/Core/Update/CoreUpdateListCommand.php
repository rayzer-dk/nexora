<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:update:list', description: 'List recent Core Update attempts and rollback points.')]
final class CoreUpdateListCommand extends Command
{
    public function __construct(private readonly CoreUpdateManager $updates)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = array_map(static fn (array $row): array => [
            $row['id'],
            $row['target_version'],
            $row['status'],
            $row['bundle_exists'] ? 'yes' : 'no',
            $row['rollback_snapshot_key'] ?? '—',
            $row['updated_at'],
            $row['error_summary'] ?? '',
        ], $this->updates->list(50));
        $io->table(['ID', 'Version', 'Status', 'Bundle', 'Recovery', 'Updated', 'Error'], $rows);
        return Command::SUCCESS;
    }
}
