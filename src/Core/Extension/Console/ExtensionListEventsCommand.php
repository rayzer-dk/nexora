<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Event\EventNames;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:list-events', description: 'List public domain events available to trusted extensions.')]
final class ExtensionListEventsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $values = array_values((new ReflectionClass(EventNames::class))->getConstants());
        sort($values, SORT_STRING);
        foreach ($values as $event) {
            $output->writeln((string) $event);
        }
        return Command::SUCCESS;
    }
}
