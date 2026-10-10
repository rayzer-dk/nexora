<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Seo\Application\LocaleUrlPrefixPolicy;
use Doctrine\DBAL\Connection;

/**
 * URL prefixes of the store languages: the default language lives at the root ("/catalog"), every other enabled
 * language under its language code ("/ru/catalog"). When two languages share a code (en-US, en-GB) the whole locale
 * is used for them ("/en-us/", "/en-gb/").
 */
final class LocalePrefixes
{
    /** @var array<int,array{default:string,prefixes:array<string,string>}> */
    private array $cache = [];

    public function __construct(private readonly Connection $connection, private readonly LocaleUrlPrefixPolicy $policy)
    {
    }

    /**
     * @return array{default:string,prefixes:array<string,string>} prefixes: locale => prefix without slashes, for non-default languages only
     */
    public function forStore(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT locale_code,is_default FROM mc_store_locale WHERE store_id=? AND enabled=1 ORDER BY is_default DESC,sort_order,locale_code',
                [$storeId],
            );
        } catch (\Throwable) {
            $rows = [];
        }
        $default = '';
        $others = [];
        foreach ($rows as $row) {
            if ((int) $row['is_default'] === 1 && $default === '') {
                $default = (string) $row['locale_code'];
            } else {
                $others[] = (string) $row['locale_code'];
            }
        }
        if ($default === '' && $others !== []) {
            $default = array_shift($others);
        }

        return $this->cache[$storeId] = ['default' => $default, 'prefixes' => $others === [] ? [] : $this->build($others)];
    }

    /**
     * @param list<string> $locales
     * @return array<string,string>
     */
    private function build(array $locales): array
    {
        $short = [];
        foreach ($locales as $locale) {
            $short[$locale] = $this->policy->apply('', $locale, false);
        }
        $count = array_count_values($short);
        $out = [];
        foreach ($locales as $locale) {
            $prefix = $count[$short[$locale]] > 1 ? strtolower($locale) : $short[$locale];
            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $prefix) === 1) {
                $out[$locale] = $prefix;
            }
        }

        return $out;
    }

    /** @return list<string> every language prefix of every active store (for robots.txt) */
    public function allPrefixes(): array
    {
        try {
            $ids = $this->connection->fetchFirstColumn("SELECT id FROM mc_store WHERE status='active'");
        } catch (\Throwable) {
            return [];
        }
        $all = [];
        foreach ($ids as $id) {
            $all = array_merge($all, array_values($this->forStore((int) $id)['prefixes']));
        }

        return array_values(array_unique($all));
    }

    /** The prefix of a locale with its leading slash ("/ru"), or '' for the default language. */
    public function prefixOf(int $storeId, string $locale): string
    {
        $prefix = $this->forStore($storeId)['prefixes'][$locale] ?? '';

        return $prefix === '' ? '' : '/' . $prefix;
    }
}
