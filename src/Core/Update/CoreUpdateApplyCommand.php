<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'commerce:update:apply', description: 'Apply a staged Core Update with verified recovery, maintenance isolation, smoke probe and automatic rollback.')]
final class CoreUpdateApplyCommand extends Command
{
    public function __construct(private readonly CoreUpdateManager $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Staged update attempt id.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm the Core Update operation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('id');
        if ($id < 1) {
            $io->error('Update attempt id must be a positive integer.');
            return Command::INVALID;
        }
        if (!(bool) $input->getOption('yes')) {
            $io->warning('No changes were made. Re-run with --yes after creating an external server/database backup if required by your operational policy.');
            return Command::INVALID;
        }

        try {
            $result = $this->updates->apply($id, 'cli');
            $io->success(sprintf(
                'Core Update %s completed. Recovery=%s, migrations=%d, SQL statements=%d, HTTP smoke=%s.',
                $result['target_version'],
                $result['rollback_snapshot'] ?? 'n/a',
                $result['migrations'],
                $result['statements'],
                ($result['smoke']['version'] ?? '') === $result['target_version'] ? 'OK' : 'unknown',
            ));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            $io->note('If automatic rollback itself failed, maintenance remains enabled intentionally. Use php bin/recovery.php --list and restore a verified snapshot.');
            return Command::FAILURE;
        }
    }
}
