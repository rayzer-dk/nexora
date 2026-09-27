<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Extension\ExtensionPoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:list-points', description: 'List stable UI extension points exposed by this Nexora release.')]
final class ExtensionListPointsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (ExtensionPoint::values() as $point) {
            $output->writeln($point);
        }
        return Command::SUCCESS;
    }
}
