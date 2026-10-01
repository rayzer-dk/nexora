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

#[AsCommand(name: 'commerce:media:gc', description: 'Remove outdated image sizes and move unused pictures to the trash folder. Dry-run unless --apply is given.')]
final class MediaGcCommand extends Command
{
    public function __construct(private readonly MediaVariantService $variants, private readonly MediaOrphanService $orphans)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Actually remove files. Without it the command only reports.')
            ->addOption('clear-sizes', null, InputOption::VALUE_NONE, 'Also remove every made size of the current generation (they are made again on demand).')
            ->addOption('grace-days', null, InputOption::VALUE_REQUIRED, 'Outdated sizes younger than this many days are kept.', '7')
            ->addOption('orphan-days', null, InputOption::VALUE_REQUIRED, 'Pictures younger than this many days are never treated as unused.', '30')
            ->addOption('trash-days', null, InputOption::VALUE_REQUIRED, 'How long trashed pictures stay in var/media-trash.', '30')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum unused pictures per run.', '200')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Time budget of the size scan.', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $deadline = time() + max(5, (int) $input->getOption('max-seconds'));
        $sizes = $this->variants->collectStale(!$apply, (int) $input->getOption('grace-days'), (bool) $input->getOption('clear-sizes'), $deadline);
        $orphans = $this->orphans->trash(!$apply, (int) $input->getOption('limit'), (int) $input->getOption('orphan-days'));
        $purged = $apply ? $this->orphans->purgeTrash((int) $input->getOption('trash-days')) : 0;
        $io->writeln(sprintf('%s: %d outdated size files (%.1f MB), %d unused pictures (%d files) %s, %d old trash folders purged.', $apply ? 'Applied' : 'Dry-run', $sizes['files'], $sizes['bytes'] / 1048576, $orphans['assets'], $orphans['files'], $apply ? 'moved to var/media-trash' : 'found', $purged));

        return Command::SUCCESS;
    }
}
