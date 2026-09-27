<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Console;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'commerce:shipping-cache:clear',
    description: 'Safely clears disposable carrier city/branch lookup cache.',
)]
final class ClearShippingCacheCommand extends Command
{
    public function __construct(private readonly CacheItemPoolInterface $shippingCache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->shippingCache->clear()) {
            $output->writeln('<error>Shipping cache could not be cleared.</error>');
            return Command::FAILURE;
        }

        $output->writeln(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.console.clearshippingcachecommand.info_tymchasovyi_kesh_dostavky_ochyshcheno_zamovlenn'));
        return Command::SUCCESS;
    }
}
