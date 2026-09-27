<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:recovery:snapshot', description: 'Create a rollback snapshot of application files and the database.')]
final class RecoverySnapshotCommand extends Command
{
    public function __construct(private readonly RecoverySnapshotService $snapshots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Snapshot reason', 'manual-cli')
            ->addOption('without-vendor', null, InputOption::VALUE_NONE, 'Do not include installed Composer vendor files.')
            ->addOption('backup-profile', 'b', InputOption::VALUE_REQUIRED, 'Backup profile: recovery, database, data or full.', 'recovery');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile=(string)$input->getOption('backup-profile');
        if(!in_array($profile,['recovery','database','data','full'],true)){ $io->error('Unknown backup profile.'); return Command::INVALID; }
        $io->note($profile==='full'?'Creating a full backup with database, media and application files.':'Creating a consistent backup profile: '.$profile.'.');
        $result = $this->snapshots->create(
            (string) $input->getOption('reason'),
            'cli',
            !(bool) $input->getOption('without-vendor'),
            $profile,
        );
        $io->success(sprintf(
            'Recovery snapshot %s created (%s bytes, SHA-256 %s).',
            $result['snapshot_key'],
            number_format($result['size_bytes']),
            $result['sha256'],
        ));
        return Command::SUCCESS;
    }
}
