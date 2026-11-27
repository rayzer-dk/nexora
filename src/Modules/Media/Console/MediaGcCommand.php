<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Console;

use Commerce\Modules\Media\Application\MediaOrphanService;
use Commerce\Modules\Media\Application\MediaVariantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:media:gc', description: 'Remove outdated image sizes and purge the old trash. With --orphans also move unused pictures to the trash (normally done by hand in Media library > Cleanup). Dry-run unless --apply is given.')]
final class MediaGcCommand extends Command
{
    public function __construct(private readonly MediaVariantService $variants, private readonly MediaOrphanService $orphans, private readonly int $cacheMaxMb = 0, private readonly int $cacheIdleDays = 0)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Actually remove files. Without it the command only reports.')
            ->addOption('orphans', null, InputOption::VALUE_NONE, 'Also move unused pictures to the trash. Off by default: unused pictures are reviewed and removed by hand in the admin.')
            ->addOption('clear-sizes', null, InputOption::VALUE_NONE, 'Also remove every made size of the current generation (they are made again on demand).')
            ->addOption('grace-days', null, InputOption::VALUE_REQUIRED, 'Outdated sizes younger than this many days are kept.', '7')
            ->addOption('orphan-days', null, InputOption::VALUE_REQUIRED, 'Pictures younger than this many days are never treated as unused.', '30')
            ->addOption('trash-days', null, InputOption::VALUE_REQUIRED, 'How long trashed pictures stay in var/media-trash.', '30')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum unused pictures per run.', '200')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Time budget of the size scan.', '60')
            ->addOption('max-cache-mb', null, InputOption::VALUE_REQUIRED, 'Soft cache budget in MiB; zero disables it.', (string) $this->cacheMaxMb)
            ->addOption('idle-days', null, InputOption::VALUE_REQUIRED, 'Evict idle variants only on filesystems that update atime; zero disables it.', (string) $this->cacheIdleDays);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $deadline = time() + max(5, (int) $input->getOption('max-seconds'));
        $sizes = $this->variants->collectStale(!$apply, (int) $input->getOption('grace-days'), (bool) $input->getOption('clear-sizes'), $deadline);
        $maxMb = max(0, min(1048576, (int) $input->getOption('max-cache-mb')));
        $idleDays = max(0, min(3650, (int) $input->getOption('idle-days')));
        if ($maxMb > 0 || $idleDays > 0) {
            $trim = $this->variants->trimCache(!$apply, $maxMb * 1048576, $idleDays, $deadline);
            $io->writeln(sprintf('Cache policy: %d files (%.1f MB) %s; %.1f MB remaining in scanned files%s.', $trim['files'], $trim['bytes'] / 1048576, $apply ? 'removed' : 'eligible', $trim['remaining_bytes'] / 1048576, $trim['complete'] ? '' : ' (partial scan)'));
        }
        $withOrphans = (bool) $input->getOption('orphans');
        $orphans = $withOrphans ? $this->orphans->trash(!$apply, (int) $input->getOption('limit'), (int) $input->getOption('orphan-days')) : ['assets' => count($this->orphans->find((int) $input->getOption('limit'), (int) $input->getOption('orphan-days'))), 'files' => 0];
        $purged = $apply ? $this->orphans->purgeTrash((int) $input->getOption('trash-days')) : 0;
        $io->writeln(sprintf('%s: %d outdated size files (%.1f MB), %d unused pictures (%d files) %s, %d old trash folders purged.', $apply ? 'Applied' : 'Dry-run', $sizes['files'], $sizes['bytes'] / 1048576, $orphans['assets'], $orphans['files'], $apply && $withOrphans ? 'moved to var/media-trash' : 'found (review them in Media library > Cleanup)', $purged));

        return Command::SUCCESS;
    }
}
