<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Console;

use Commerce\Modules\Search\Infrastructure\MeilisearchProductIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:search:reindex', description: 'Rebuild the built-in search index (and Meilisearch when configured) from canonical catalog data.')]
final class SearchReindexCommand extends Command
{
    public function __construct(private readonly MeilisearchProductIndexer $indexer, private readonly \Commerce\Modules\Search\Application\SqlSearchIndex $sqlIndex)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per canonical projection batch.', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = $this->sqlIndex->rebuildAll(max(50, (int) $input->getOption('batch')));
        $output->writeln(sprintf('sql index: documents=%d terms=%d', $sql['documents'], $sql['terms']));
        if (!$this->indexer->isEnabled()) {
            return Command::SUCCESS;
        }
        $result = $this->indexer->rebuildAll((int)$input->getOption('batch'));
        $output->writeln(sprintf('<info>Reindexed %d products. Last product ID: %d.</info>', $result['processed'], $result['last_product_id']));
        return Command::SUCCESS;
    }
}
