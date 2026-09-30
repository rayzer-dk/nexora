<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Console;

use Commerce\Modules\Seo\Application\IndexNowService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:indexnow:submit', description: 'Send new and changed indexable URLs to IndexNow for every store that enabled it.')]
final class IndexNowSubmitCommand extends Command
{
    public function __construct(private readonly IndexNowService $indexNow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Submit every indexable URL, not only those changed since the last successful run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = 0;
        foreach ($this->indexNow->enabledStores() as $storeId) {
            $result = $this->indexNow->submit($storeId, (bool) $input->getOption('all'));
            $output->writeln(sprintf('store %d: %s, %d URL', $storeId, $result['status'], $result['count']));
            $failed += $result['ok'] ? 0 : 1;
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
