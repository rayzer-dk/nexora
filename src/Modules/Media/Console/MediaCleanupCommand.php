<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Console;

use Commerce\Modules\Media\Application\MediaImageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:media:cleanup', description: 'Find or delete unreferenced media assets and generated derivatives.')]
final class MediaCleanupCommand extends Command
{
    public function __construct(private readonly MediaImageService $media)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delete', null, InputOption::VALUE_NONE, 'Actually delete orphaned assets. Default is dry-run.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum assets per run', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $delete = (bool) $input->getOption('delete');
        $result = $this->media->cleanupOrphans(!$delete, (int) $input->getOption('limit'));
        if ($delete) {
            $io->success(sprintf('Deleted %d orphan media records and %d files.', $result['deleted_assets'], $result['deleted_files']));
        } else {
            $io->note(sprintf('Dry-run: %d orphan media records found. Run again with --delete to remove them.', $result['deleted_assets']));
        }
        return Command::SUCCESS;
    }
}
