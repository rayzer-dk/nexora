<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Console;

use Commerce\Modules\Media\Application\MediaVariantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:media:warm', description: 'Make the queued AVIF sizes and the everyday sizes (thumb, card, product) of the main product photos that still lack them.')]
final class MediaWarmCommand extends Command
{
    public function __construct(private readonly MediaVariantService $variants)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Photos looked at per run.', '200000')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Time budget of one run.', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = time() + max(5, (int) $input->getOption('max-seconds'));
        $queued = $this->variants->processQueue($deadline);
        $made = $this->variants->warmPrimaries((int) $input->getOption('limit'), $deadline);
        $output->writeln(sprintf('Made %d image sizes (%d from the AVIF queue, %d still queued). Engine: %s.', $made + $queued, $queued, $this->variants->queueLength(), $this->variants->engine()));

        return Command::SUCCESS;
    }
}
