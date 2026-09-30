<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates and edits the texts of a product or category in every enabled store language, next to the
 * language the merchant is working in. A translation that does not exist yet is created here (with its
 * address), so a product no longer needs a CSV import to appear in a second language.
 */
final readonly class CatalogTranslationService
{
    /** field => max length */
    public const PRODUCT_FIELDS = ['name' => 255, 'short_description' => 1000, 'description' => 100000, 'meta_title' => 255, 'meta_description' => 500];
    public const CATEGORY_FIELDS = ['name' => 255, 'description' => 100000, 'description_bottom' => 100000, 'meta_title' => 255, 'meta_description' => 500];
    private const RICH = ['description', 'description_bottom'];

    public function __construct(
        private Connection $db,
        private SeoUrlManager $seo,
        #[Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private HtmlSanitizerInterface $sanitizer,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
    ) {
    }

    /** @return list<array{code:string,name:string,is_default:bool}> */
    public function locales(int $storeId): array
    {
        $default = $this->defaultLocale($storeId);
        $rows = $this->db->fetchAllAssociative('SELECT l.code,l.native_name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.sort_order,l.code', [$storeId]);

        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'name' => (string) $r['native_name'], 'is_default' => (string) $r['code'] === $default], $rows);
    }

    public function defaultLocale(int $storeId): string
    {
        return (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
    }

    /** @return array<string,array<string,string>> locale => field => text (only locales that already have a row) */
    public function productTexts(int $storeId, int $productId): array
    {
        return $this->texts('mc_product_translation', 'product_id', $productId, $storeId, array_keys(self::PRODUCT_FIELDS));
    }

    /** @return array<string,array<string,string>> */
    public function categoryTexts(int $storeId, int $categoryId): array
    {
        return $this->texts('mc_category_translation', 'category_id', $categoryId, $storeId, array_keys(self::CATEGORY_FIELDS));
    }

    /** @return list<string> enabled locales in which the product has no name or no description */
    public function missingForProduct(int $storeId, int $productId): array
    {
        $texts = $this->productTexts($storeId, $productId);
        $missing = [];
        foreach ($this->locales($storeId) as $locale) {
            $row = $texts[$locale['code']] ?? [];
            if (trim((string) ($row['name'] ?? '')) === '' || trim((string) ($row['description'] ?? '')) === '') {
                $missing[] = $locale['code'];
            }
        }

        return $missing;
    }

    /** @param array<string,mixed> $input */
    public function saveProduct(int $storeId, int $productId, string $locale, array $input): void
    {
        $this->assertLocale($storeId, $locale);
        $publicId = $this->publicId('mc_product', $productId);
        $data = $this->clean($input, self::PRODUCT_FIELDS);
        if ($data['name'] === null) {
            throw new \InvalidArgumentException('name_required');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->transactional(function (Connection $db) use ($storeId, $productId, $locale, $data, $now, $publicId): void {
            $exists = (int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_translation WHERE product_id=? AND store_id=? AND locale=?', [$productId, $storeId, $locale]) > 0;
            if ($exists) {
                $db->update('mc_product_translation', $data + ['updated_at' => $now], ['product_id' => $productId, 'store_id' => $storeId, 'locale' => $locale]);
            } else {
                $db->insert('mc_product_translation', $data + ['product_id' => $productId, 'store_id' => $storeId, 'locale' => $locale, 'slug' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->seo->ensureForCreatedEntity($storeId, $locale, SeoEntityType::Product, $publicId, (string) $data['name']);
        });
        $this->events->publish($this->eventFactory->create(EventNames::PRODUCT_UPDATED, 'product', $publicId, ['store_id' => $storeId, 'locale' => $locale], ['source' => 'translation']));
    }

    /** @param array<string,mixed> $input */
    public function saveCategory(int $storeId, int $categoryId, string $locale, array $input): void
    {
        $this->assertLocale($storeId, $locale);
        $publicId = $this->publicId('mc_category', $categoryId);
        $data = $this->clean($input, self::CATEGORY_FIELDS);
        if ($data['name'] === null) {
            throw new \InvalidArgumentException('name_required');
        }
        $this->db->transactional(function (Connection $db) use ($storeId, $categoryId, $locale, $data, $publicId): void {
            $exists = (int) $db->fetchOne('SELECT COUNT(*) FROM mc_category_translation WHERE category_id=? AND store_id=? AND locale=?', [$categoryId, $storeId, $locale]) > 0;
            if ($exists) {
                $db->update('mc_category_translation', $data, ['category_id' => $categoryId, 'store_id' => $storeId, 'locale' => $locale]);
            } else {
                $db->insert('mc_category_translation', $data + ['category_id' => $categoryId, 'store_id' => $storeId, 'locale' => $locale, 'slug' => null]);
            }
            $this->seo->ensureForCreatedEntity($storeId, $locale, SeoEntityType::Category, $publicId, (string) $data['name']);
        });
    }

    /** @return array{id:int,public_id:string,name:string}|null */
    public function product(int $storeId, string $publicId): ?array
    {
        return $this->entity('mc_product', 'mc_store_product', 'product_id', 'mc_product_translation', 'product_id', $storeId, $publicId);
    }

    /** @return array{id:int,public_id:string,name:string}|null */
    public function category(int $storeId, string $publicId): ?array
    {
        return $this->entity('mc_category', 'mc_store_category', 'category_id', 'mc_category_translation', 'category_id', $storeId, $publicId);
    }

    /** @return array{id:int,public_id:string,name:string}|null */
    private function entity(string $table, string $storeTable, string $storeKey, string $translationTable, string $translationKey, int $storeId, string $publicId): ?array
    {
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $default = $this->defaultLocale($storeId);
        $row = $this->db->fetchAssociative(
            "SELECT e.id, COALESCE((SELECT t.name FROM $translationTable t WHERE t.$translationKey=e.id AND t.store_id=? AND t.locale=? LIMIT 1),(SELECT t2.name FROM $translationTable t2 WHERE t2.$translationKey=e.id AND t2.store_id=? ORDER BY t2.id LIMIT 1),'') AS name
             FROM $table e JOIN $storeTable s ON s.$storeKey=e.id AND s.store_id=? WHERE e.public_id=? LIMIT 1",
            [$storeId, $default, $storeId, $storeId, $binary],
        );

        return is_array($row) ? ['id' => (int) $row['id'], 'public_id' => $publicId, 'name' => (string) $row['name']] : null;
    }

    private function assertLocale(int $storeId, string $locale): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$storeId, $locale]) !== 1) {
            throw new \InvalidArgumentException('locale_invalid');
        }
    }

    private function publicId(string $table, int $id): string
    {
        $binary = $this->db->fetchOne("SELECT public_id FROM $table WHERE id=?", [$id]);
        if (!is_string($binary)) {
            throw new \InvalidArgumentException('not_found');
        }

        return Uuid::fromBinary($binary)->toRfc4122();
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,int> $fields
     * @return array<string,?string>
     */
    private function clean(array $input, array $fields): array
    {
        $out = [];
        foreach ($fields as $field => $max) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && in_array($field, self::RICH, true)) {
                $value = trim($this->sanitizer->sanitize(mb_substr($value, 0, $max)));
            } elseif ($value !== '') {
                $value = trim(strip_tags(mb_substr($value, 0, $max)));
            }
            $out[$field] = $value === '' ? null : mb_substr($value, 0, $max);
        }

        return $out;
    }

    /**
     * @param list<string> $fields
     * @return array<string,array<string,string>>
     */
    private function texts(string $table, string $key, int $id, int $storeId, array $fields): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT locale,' . implode(',', $fields) . " FROM $table WHERE $key=? AND store_id=?", [$id, $storeId]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['locale']] = array_map(static fn ($v): string => (string) ($v ?? ''), array_intersect_key($row, array_flip($fields)));
        }

        return $out;
    }
}
