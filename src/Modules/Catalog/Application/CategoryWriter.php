<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateCategoryCommand;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CategoryWriter
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private SeoUrlManager $seo,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
        private \Commerce\Modules\Localization\Application\TranslationFallbackFiller $fallbackTexts,
    ) {
    }

    /** Changes only the position of a category among its siblings (the Order field), in the store and in the market. */
    public function reorder(int $storeId, int $marketId, string $publicId, int $sortOrder): void
    {
        $sortOrder = max(0, min(100000, $sortOrder));
        $this->connection->transactional(function (Connection $db) use ($storeId, $marketId, $publicId, $sortOrder): void {
            $id = $db->fetchOne('SELECT c.id FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? WHERE c.public_id=?', [$storeId, Uuid::fromString($publicId)->toBinary()]);
            if ($id === false) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6324b72effe1'));
            }
            $db->update('mc_category', ['sort_order' => $sortOrder, 'updated_at' => $this->now()], ['id' => (int) $id]);
            $db->update('mc_store_category', ['sort_order' => $sortOrder], ['store_id' => $storeId, 'category_id' => (int) $id]);
            $db->update('mc_market_category', ['sort_order' => $sortOrder], ['market_id' => $marketId, 'category_id' => (int) $id]);
            $this->events->publish($this->eventFactory->create(EventNames::CATEGORY_UPDATED, 'category', $publicId, ['store_id' => $storeId, 'market_id' => $marketId], ['source' => 'catalog']));
        });
    }

    /** Switches one category between active and inactive (the switch in the category list). */
    public function setStatus(int $storeId, int $marketId, string $publicId, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \DomainException('status');
        }
        $this->connection->transactional(function (Connection $db) use ($storeId, $marketId, $publicId, $status): void {
            $id = $db->fetchOne('SELECT c.id FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? WHERE c.public_id=?', [$storeId, Uuid::fromString($publicId)->toBinary()]);
            if ($id === false) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6324b72effe1'));
            }
            $db->update('mc_category', ['status' => $status, 'updated_at' => $this->now()], ['id' => (int) $id]);
            $db->update('mc_store_category', ['status' => $status], ['store_id' => $storeId, 'category_id' => (int) $id]);
            $db->update('mc_market_category', ['status' => $status], ['market_id' => $marketId, 'category_id' => (int) $id]);
            $this->events->publish($this->eventFactory->create(EventNames::CATEGORY_UPDATED, 'category', $publicId, ['store_id' => $storeId, 'market_id' => $marketId], ['source' => 'catalog']));
        });
    }

    /** @return array{id:int,public_id:string,url:string} */
    public function create(CreateCategoryCommand $command): array
    {
        return $this->connection->transactional(function (Connection $db) use ($command): array {
            $this->assertContext($db, $command->storeId, $command->marketId, $command->locale, $command->parentId);
            $uuid = $this->publicIds->generate();
            $now = $this->now();

            $db->insert('mc_category', [
                'public_id' => $uuid->toBinary(), 'parent_id' => $command->parentId, 'status' => 'active',
                'sort_order' => $command->sortOrder, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int) $db->lastInsertId();
            $db->insert('mc_store_category', ['store_id' => $command->storeId, 'category_id' => $id, 'status' => 'active', 'sort_order' => $command->sortOrder]);
            $db->insert('mc_market_category', ['market_id' => $command->marketId, 'category_id' => $id, 'status' => 'active', 'sort_order' => $command->sortOrder]);
            $db->insert('mc_category_translation', [
                'category_id' => $id, 'store_id' => $command->storeId, 'locale' => $command->locale, 'name' => trim($command->name),
                'slug' => null, 'description' => null, 'meta_title' => null, 'meta_description' => null,
            ]);

            $route = $this->seo->ensureForCreatedEntity(
                $command->storeId, $command->locale, SeoEntityType::Category, $uuid->toRfc4122(), $command->name, $command->manualSlug,
            );
            $this->events->publish($this->eventFactory->create(
                EventNames::CATEGORY_CREATED,
                'category',
                $uuid->toRfc4122(),
                ['store_id' => $command->storeId, 'market_id' => $command->marketId],
                ['source' => 'catalog'],
            ));

            $this->fallbackTexts->fillCategory($db, $command->storeId, $id);
            return ['id' => $id, 'public_id' => $uuid->toRfc4122(), 'url' => '/' . ltrim($route->path, '/')];
        });
    }

    /** @return array{id:int,public_id:string,url:string,status:string} */
    public function update(UpdateCategoryCommand $command): array
    {
        return $this->connection->transactional(function (Connection $db) use ($command): array {
            $this->assertContext($db, $command->storeId, $command->marketId, $command->locale, $command->parentId);
            $row = $db->fetchAssociative(
                'SELECT c.* FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? WHERE c.id=? FOR UPDATE',
                [$command->storeId, $command->categoryId],
            );
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6324b72effe1'));
            }
            if ($command->parentId !== null && $this->wouldCreateCycle($db, $command->categoryId, $command->parentId)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c8252316d3fa'));
            }

            $now = $this->now();
            $db->update('mc_category', ['parent_id' => $command->parentId, 'status' => $command->status, 'sort_order' => $command->sortOrder, 'updated_at' => $now], ['id' => $command->categoryId]);
            $db->update('mc_store_category', ['status' => $command->status, 'sort_order' => $command->sortOrder], ['store_id' => $command->storeId, 'category_id' => $command->categoryId]);
            $db->update('mc_market_category', ['status' => $command->status, 'sort_order' => $command->sortOrder], ['market_id' => $command->marketId, 'category_id' => $command->categoryId]);
            $db->update('mc_category_translation', ['name' => trim($command->name), 'is_fallback' => 0], ['category_id' => $command->categoryId, 'store_id' => $command->storeId, 'locale' => $command->locale]);

            $publicId = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $route = $this->seo->ensureForCreatedEntity($command->storeId, $command->locale, SeoEntityType::Category, $publicId, $command->name);
            if ($command->manualSlug !== null && trim($command->manualSlug) !== '' && trim($command->manualSlug) !== $route->slug) {
                $route = $this->seo->changeSlug($route, $command->manualSlug);
            }
            $this->events->publish($this->eventFactory->create(
                EventNames::CATEGORY_UPDATED,
                'category',
                $publicId,
                ['store_id' => $command->storeId, 'market_id' => $command->marketId],
                ['source' => 'catalog'],
            ));

            $this->fallbackTexts->fillCategory($db, $command->storeId, $command->categoryId);
            return ['id' => $command->categoryId, 'public_id' => $publicId, 'url' => '/' . ltrim($route->path, '/'), 'status' => $command->status];
        });
    }

    private function wouldCreateCycle(Connection $db, int $categoryId, int $parentId): bool
    {
        $seen = [$categoryId => true];
        $current = $parentId;
        for ($depth = 0; $depth < 100 && $current > 0; $depth++) {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $next = $db->fetchOne('SELECT parent_id FROM mc_category WHERE id=? LIMIT 1', [$current]);
            if ($next === false || $next === null) {
                return false;
            }
            $current = (int) $next;
        }
        return $current > 0;
    }

    private function assertContext(Connection $db, int $storeId, int $marketId, string $locale, ?int $parentId): void
    {
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_market WHERE id = ? AND store_id = ? AND status = ?', [$marketId, $storeId, 'active']) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.59a5e685ba38'));
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id = ? AND locale_code = ? AND enabled = 1', [$storeId, $locale]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f1b5493feca0'));
        }
        if ($parentId !== null && (int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_category WHERE store_id = ? AND category_id = ?', [$storeId, $parentId]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c1359de22ca0'));
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
