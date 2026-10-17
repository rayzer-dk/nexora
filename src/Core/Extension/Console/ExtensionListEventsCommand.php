<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Event\EventNames;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:list-events', description: 'List the domain events the core publishes to trusted extensions (--all adds reserved names).')]
final class ExtensionListEventsCommand extends Command
{
    /**
     * Events the core actually publishes through the outbox. The other EventNames constants are reserved names: a
     * module may subscribe to them, but nothing emits them yet. ExtensionListEventsTest checks this list against the
     * publishers in src/ and against docs/DEVELOPER_MODULES_UK.md.
     */
    public const PUBLISHED = [
        EventNames::CATEGORY_CREATED,
        EventNames::CATEGORY_UPDATED,
        EventNames::PRODUCT_CREATED,
        EventNames::PRODUCT_UPDATED,
        EventNames::CUSTOMER_REGISTERED,
        EventNames::ORDER_CANCELLED,
        EventNames::ORDER_COMPLETED,
        EventNames::ORDER_PLACED,
        EventNames::PAYMENT_STATUS_CHANGED,
        EventNames::ORDER_SHIPPED,
    ];

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Also print reserved names that the core never publishes, marked as reserved.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = self::PUBLISHED;
        sort($published, SORT_STRING);
        foreach ($published as $event) {
            $output->writeln($event);
        }
        if (!(bool) $input->getOption('all')) {
            return Command::SUCCESS;
        }
        $reserved = array_values(array_diff(array_values((new ReflectionClass(EventNames::class))->getConstants()), $published));
        sort($reserved, SORT_STRING);
        foreach ($reserved as $event) {
            $output->writeln($event . '  (reserved: not published by the core, a handler never runs)');
        }
        return Command::SUCCESS;
    }
}
