<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Console;

use Commerce\Modules\Search\Infrastructure\MeilisearchProductIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:search:reindex', description: 'Rebuild the optional Meilisearch index from canonical storefront data.')]
final class SearchReindexCommand extends Command
{
    public function __construct(private readonly MeilisearchProductIndexer $indexer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per canonical projection batch.', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->indexer->isEnabled()) {
            $output->writeln('<comment>Meilisearch is disabled. SQL search remains active.</comment>');
            return Command::SUCCESS;
        }
        $result = $this->indexer->rebuildAll((int)$input->getOption('batch'));
        $output->writeln(sprintf('<info>Reindexed %d products. Last product ID: %d.</info>', $result['processed'], $result['last_product_id']));
        return Command::SUCCESS;
    }
}
