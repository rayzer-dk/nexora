<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Projection;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:storefront:warm-facets', description: 'Precompute popular storefront facet payloads without making them mandatory.')]
final class StorefrontFacetWarmupCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly DbalStorefrontCatalogQuery $catalog,
        private readonly StorefrontFacetProjectionStore $store,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('categories', null, InputOption::VALUE_REQUIRED, 'Popular categories per context.', '40');
        $this->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Projection TTL in seconds.', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $categoryLimit = min(200, max(1, (int)$input->getOption('categories')));
        $ttl = min(3600, max(30, (int)$input->getOption('ttl')));
        $contexts = $this->db->fetchAllAssociative(
            "SELECT s.id store_id,s.name store_name,m.id market_id,COALESCE((SELECT MIN(mc.country_code) FROM mc_market_country mc WHERE mc.market_id=m.id),'UA') country_code,sl.locale_code locale,sc.currency_code currency
             FROM mc_store s JOIN mc_market m ON m.store_id=s.id AND m.status='active'
             JOIN mc_store_locale sl ON sl.store_id=s.id AND sl.enabled=1
             JOIN mc_store_currency sc ON sc.store_id=s.id AND sc.enabled=1
             WHERE s.status='active' ORDER BY s.id,m.id,sl.locale_code,sc.currency_code"
        );
        $written = 0;
        foreach ($contexts as $row) {
            $context = new StorefrontContext((int)$row['store_id'],(int)$row['market_id'],(string)$row['locale'],(string)$row['currency'],(string)$row['country_code'],(string)$row['store_name']);
            $this->store->put($context, null, $this->catalog->catalogFacets($context, null), $ttl); $written++;
            $categories = $this->db->fetchFirstColumn(
                "SELECT pc.category_id FROM mc_product_category pc
                 JOIN mc_store_product sp ON sp.product_id=pc.product_id AND sp.store_id=? AND sp.status='active'
                 JOIN mc_market_product mp ON mp.product_id=pc.product_id AND mp.market_id=? AND mp.status='active'
                 GROUP BY pc.category_id ORDER BY COUNT(DISTINCT pc.product_id) DESC,pc.category_id ASC LIMIT {$categoryLimit}",
                [$context->storeId,$context->marketId],
            );
            foreach ($categories as $categoryId) {
                $id=(int)$categoryId;
                $this->store->put($context, $id, $this->catalog->catalogFacets($context, $id), $ttl); $written++;
            }
        }
        $output->writeln(sprintf('<info>Facet projections refreshed: %d.</info>', $written));
        return Command::SUCCESS;
    }
}
