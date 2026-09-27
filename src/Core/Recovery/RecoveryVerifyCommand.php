<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:recovery:verify', description: 'Verify a recovery snapshot by database record id.')]
final class RecoveryVerifyCommand extends Command
{
    public function __construct(private readonly RecoverySnapshotService $snapshots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Recovery snapshot database id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->snapshots->verify((int) $input->getArgument('id'));
        if (!$result['ok']) {
            $io->error('Snapshot verification failed: ' . $result['reason']);
            return Command::FAILURE;
        }
        $io->success(sprintf('Snapshot verified (%s bytes, SHA-256 %s).', number_format($result['size_bytes']), $result['sha256']));
        return Command::SUCCESS;
    }
}
