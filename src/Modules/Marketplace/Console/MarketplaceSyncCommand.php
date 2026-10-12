<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketplace\Console;

use Commerce\Modules\Marketplace\Application\MarketplaceSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:marketplace:sync', description: 'Take new marketplace orders, push stock and prices that changed, send order statuses back.')]
final class MarketplaceSyncCommand extends Command
{
    public function __construct(private readonly MarketplaceSyncService $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'One marketplace code, for example prom');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('only');
        $result = $this->sync->run(is_string($only) && $only !== '' ? $only : null);
        if ($result === []) {
            $output->writeln('No marketplace is switched on (Admin → System → Integrations → Connections → Marketplaces).');

            return Command::SUCCESS;
        }
        $failed = false;
        foreach ($result as $code => $s) {
            $output->writeln(sprintf('%s: orders %d, stock %d, statuses %d, errors %d', $code, $s['orders'], $s['stock'], $s['statuses'], $s['errors']));
            $failed = $failed || $s['errors'] > 0;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
