<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Console;

use Commerce\Modules\Quality\Application\LinkCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:links:check', description: 'Search the shop content for links to missing pages and pictures that are gone.')]
final class LinkCheckCommand extends Command
{
    public function __construct(private readonly LinkCheckService $check)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->check->run();
        $output->writeln(sprintf('documents=%d links=%d images=%d external=%d issues=%d seconds=%.2f', $r['documents'], $r['links'], $r['images'], $r['external'], $r['issues'], $r['seconds']));

        return Command::SUCCESS;
    }
}
