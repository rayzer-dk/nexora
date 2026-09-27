<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:list-contracts', description: 'List public provider contracts available to trusted Nexora extensions.')]
final class ExtensionListContractsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $contracts = [
            'provider.payment' => 'Commerce\\Modules\\Payment\\Application\\PaymentProviderInterface',
            'provider.shipping' => 'Commerce\\Modules\\Delivery\\Application\\DeliveryProviderInterface',
            'provider.product_block' => 'Commerce\\Modules\\ProductPage\\Application\\ProductBlockProviderInterface',
            'provider.ai' => 'Commerce\\Modules\\Ai\\Application\\TextGenerationProviderInterface',
        ];
        foreach ($contracts as $capability => $interface) {
            $output->writeln($capability . ' => ' . $interface);
        }
        return Command::SUCCESS;
    }
}
