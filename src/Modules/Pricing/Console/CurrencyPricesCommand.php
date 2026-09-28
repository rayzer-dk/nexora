<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Console;

use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'commerce:currency:sync', description: 'Refresh exchange rates (NBU) and rebuild converted prices for auto-converted currencies.')]
final class CurrencyPricesCommand extends Command
{
    public function __construct(private readonly ExchangeRateService $rates, private readonly CurrencyPriceSynchronizer $prices)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fetch', null, InputOption::VALUE_NONE, 'Fetch current NBU rates before converting.')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Rebuild all converted prices instead of only changed products.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = Command::SUCCESS;
        if ((bool) $input->getOption('fetch') && $this->rates->requiredPairs() !== []) {
            try {
                $r = $this->rates->refreshFromNbu();
                $output->writeln(sprintf('rates: %s %s stored=%d missing=%s', $r['provider'], $r['date'], $r['stored'], $r['missing'] === [] ? '-' : implode(',', $r['missing'])));
            } catch (Throwable $e) {
                // The last stored rate stays valid until it expires; conversion continues with it.
                $output->writeln('rates: fetch failed, keeping last valid rates (' . mb_substr($e->getMessage(), 0, 160) . ')');
                $status = Command::FAILURE;
            }
        }
        foreach ($this->prices->sync(null, (bool) $input->getOption('full')) as $row) {
            $output->writeln(sprintf('store=%d %s mode=%s rate=%s written=%d removed=%d', $row['store_id'], $row['currency'], $row['mode'], $row['rate'] ?? '-', $row['written'], $row['removed']));
        }

        return $status;
    }
}
