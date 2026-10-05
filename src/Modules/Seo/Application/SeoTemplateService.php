<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/**
 * Default page title and description patterns of products, categories, articles and pages, per language. A pattern is used only
 * when the item has no title or description of its own; variables: {name} {store} {price} {brand} {category} {sku}.
 */
final class SeoTemplateService
{
    public const TYPES = ['product', 'category', 'article', 'page'];
    public const VARIABLES = ['name', 'store', 'price', 'brand', 'category', 'sku'];

    /** @var array<int,array<string,mixed>> */
    private array $cache = [];

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return array<string,array<string,array{title:string,description:string}>> type => locale => patterns */
    public function all(int $storeId): array
    {
        return $this->cache[$storeId] ??= ($this->store->getArray('seo.templates.' . $storeId) ?? []);
    }

    /** @param array<string,mixed> $input type => locale => [title, description] */
    public function save(int $storeId, array $input): void
    {
        $clean = [];
        foreach (self::TYPES as $type) {
            foreach ((array) ($input[$type] ?? []) as $locale => $fields) {
                if (!is_array($fields) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', (string) $locale) !== 1) {
                    continue;
                }
                $title = mb_substr(trim(strip_tags((string) ($fields['title'] ?? ''))), 0, 200);
                $description = mb_substr(trim(strip_tags((string) ($fields['description'] ?? ''))), 0, 400);
                if ($title !== '' || $description !== '') {
                    $clean[$type][(string) $locale] = ['title' => $title, 'description' => $description];
                }
            }
        }
        $this->store->setArray('seo.templates.' . $storeId, $clean);
        unset($this->cache[$storeId]);
    }

    /**
     * The pattern of a field filled with the values of the item, or $fallback when there is no pattern (or it ends up empty).
     *
     * @param 'title'|'description' $field
     * @param array<string,string> $vars
     */
    public function render(int $storeId, string $locale, string $type, string $field, array $vars, string $fallback): string
    {
        $pattern = (string) ($this->all($storeId)[$type][$locale][$field] ?? '');
        if ($pattern === '') {
            return $fallback;
        }
        $map = [];
        foreach (self::VARIABLES as $name) {
            $map['{' . $name . '}'] = trim((string) ($vars[$name] ?? ''));
        }
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', strtr($pattern, $map)));
        // A pattern that leaves only separators behind (an empty brand, say) is not worth showing.
        $text = trim($text, " \t-–—|·,:");

        return $text !== '' ? $text : $fallback;
    }
}
