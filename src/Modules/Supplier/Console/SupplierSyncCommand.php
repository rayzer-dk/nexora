<?php

declare(strict_types=1);

namespace Commerce\Modules\Supplier\Console;

use Commerce\Modules\Supplier\Application\SupplierService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:suppliers:sync', description: 'Read the price and stock feeds of suppliers whose interval has elapsed.')]
final class SupplierSyncCommand extends Command
{
    public function __construct(private readonly SupplierService $suppliers)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('suppliers=%d', $this->suppliers->runDue()));

        return Command::SUCCESS;
    }
}
