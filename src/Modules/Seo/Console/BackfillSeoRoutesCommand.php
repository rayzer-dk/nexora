<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Console;

use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'commerce:seo:backfill', description: 'Create missing canonical SEO routes from existing localized catalog content.')]
final class BackfillSeoRoutesCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly SeoUrlManager $seo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->backfillProducts() + $this->backfillCategories() + $this->backfillBrands();
        $output->writeln(sprintf('<info>SEO route backfill completed. Ensured %d localized routes.</info>', $count));
        return Command::SUCCESS;
    }

    private function backfillProducts(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.public_id, t.store_id, t.locale, t.name, t.slug'
            . ' FROM mc_product_translation t JOIN mc_product p ON p.id = t.product_id ORDER BY t.id ASC'
        );
        foreach ($rows as $row) {
            $this->seo->ensureForCreatedEntity(
                (int) $row['store_id'], (string) $row['locale'], SeoEntityType::Product,
                Uuid::fromBinary((string) $row['public_id'])->toRfc4122(), (string) $row['name'],
                ($row['slug'] ?? '') !== '' ? (string) $row['slug'] : null,
            );
        }
        return count($rows);
    }

    private function backfillCategories(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.public_id, t.store_id, t.locale, t.name, t.slug'
            . ' FROM mc_category_translation t JOIN mc_category c ON c.id = t.category_id ORDER BY t.id ASC'
        );
        foreach ($rows as $row) {
            $this->seo->ensureForCreatedEntity(
                (int) $row['store_id'], (string) $row['locale'], SeoEntityType::Category,
                Uuid::fromBinary((string) $row['public_id'])->toRfc4122(), (string) $row['name'],
                ($row['slug'] ?? '') !== '' ? (string) $row['slug'] : null,
            );
        }
        return count($rows);
    }

    private function backfillBrands(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.public_id, t.store_id, t.locale, b.name, t.slug'
            . ' FROM mc_brand_translation t JOIN mc_brand b ON b.id = t.brand_id ORDER BY t.id ASC'
        );
        foreach ($rows as $row) {
            $this->seo->ensureForCreatedEntity(
                (int) $row['store_id'], (string) $row['locale'], SeoEntityType::Brand,
                Uuid::fromBinary((string) $row['public_id'])->toRfc4122(), (string) $row['name'],
                ($row['slug'] ?? '') !== '' ? (string) $row['slug'] : null,
            );
        }
        return count($rows);
    }
}
