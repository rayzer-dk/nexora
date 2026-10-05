<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Twig;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Data for the storefront chrome: the compact header switchers (language with flag, currency with symbol) and the
 * catalog mega-menu tree.
 *
 * Both switchers are plain links: `?lang=` / `?currency=` are read by StorefrontContextResolver and persisted
 * in the `store_locale` / `store_currency` cookies by StorefrontPreferenceSubscriber, so no JavaScript and no
 * "apply" button is needed. The language links keep the visitor on the same page: when the current URL belongs to
 * a product, category or article, the link points at that entity's URL in the target language (when it has one).
 * A switcher is only offered when the store has more than one real option (a currency counts only when prices
 * exist in it, exactly like StorefrontContextResolver accepts it).
 */
final class StorefrontChromeExtension extends AbstractExtension
{
    /** Language subtag -> region used to draw the flag when the locale code carries no region (e.g. "de"). */
    private const DEFAULT_REGION = [
        'uk' => 'UA', 'en' => 'GB', 'de' => 'DE', 'pl' => 'PL', 'ru' => 'RU', 'da' => 'DK', 'fr' => 'FR', 'es' => 'ES',
        'it' => 'IT', 'cs' => 'CZ', 'ro' => 'RO', 'bg' => 'BG', 'tr' => 'TR', 'pt' => 'PT', 'nl' => 'NL', 'sv' => 'SE',
        'nb' => 'NO', 'no' => 'NO', 'fi' => 'FI', 'hu' => 'HU', 'sk' => 'SK',
    ];

    /** @var array<string,mixed> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly StorefrontLayoutExtension $layout,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('storefront_language_switch', [$this, 'languageSwitch']),
            new TwigFunction('storefront_currency_switch', [$this, 'currencySwitch']),
            new TwigFunction('storefront_mega_tree', [$this, 'megaTree']),
        ];
    }

    /**
     * Category tree for the mega-menu. A category shows its own cover photo; without one it borrows the first product photo
     * of the category (or of its first sub-category with a photo) so the panel is rarely empty; the template draws a
     * placeholder only when there is no photo at all.
     *
     * @return list<array<string,mixed>>
     */
    public function megaTree(): array
    {
        return $this->memo['mega'] ??= $this->buildMegaTree();
    }

    /** @return list<array<string,mixed>> */
    private function buildMegaTree(): array
    {
        $tree = $this->layout->categoryTree();
        $missing = [];
        $collect = static function (array $nodes) use (&$collect, &$missing): void {
            foreach ($nodes as $node) {
                if (($node['image'] ?? null) === null) {
                    $missing[] = (int) $node['id'];
                }
                $collect($node['children'] ?? []);
            }
        };
        $collect($tree);
        $photos = [];
        if ($missing !== []) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT c.id,(SELECT ma.storage_key FROM mc_product_category pcx JOIN mc_product px ON px.id=pcx.product_id AND px.status='published'
                        JOIN mc_product_media pm ON pm.product_id=px.id AND pm.role IN ('primary','gallery') JOIN mc_media_asset ma ON ma.id=pm.media_asset_id
                        WHERE pcx.category_id=c.id ORDER BY (pm.role='primary') DESC,pm.sort_order ASC,px.id ASC LIMIT 1) AS image_key
                     FROM mc_category c WHERE c.id IN (" . implode(',', array_fill(0, count($missing), '?')) . ')',
                    $missing,
                );
                foreach ($rows as $row) {
                    $key = $row['image_key'] ?? null;
                    if (is_string($key) && trim($key) !== '' && !str_contains($key, '..')) {
                        $photos[(int) $row['id']] = '/media/' . ltrim(str_replace('\\', '/', trim($key)), '/');
                    }
                }
            } catch (\Throwable) {
                // photos are decoration; the placeholder covers for them
            }
        }
        $fill = static function (array $nodes) use (&$fill, $photos): array {
            foreach ($nodes as $i => $node) {
                $node['children'] = $fill($node['children'] ?? []);
                if (($node['image'] ?? null) === null) {
                    $node['image'] = $photos[(int) $node['id']] ?? null;
                }
                if (($node['image'] ?? null) === null) {
                    foreach ($node['children'] as $child) {
                        if (($child['image'] ?? null) !== null) {
                            $node['image'] = $child['image'];
                            break;
                        }
                    }
                }
                $nodes[$i] = $node;
            }

            return $nodes;
        };

        return $fill($tree);
    }

    /**
     * @return array{current:array<string,mixed>,options:list<array<string,mixed>>}|null null when fewer than two languages are enabled
     */
    public function languageSwitch(): ?array
    {
        return array_key_exists('lang', $this->memo) ? $this->memo['lang'] : ($this->memo['lang'] = $this->buildLanguages());
    }

    /**
     * @return array{current:array<string,mixed>,options:list<array<string,mixed>>}|null null when fewer than two currencies can be shown
     */
    public function currencySwitch(): ?array
    {
        return array_key_exists('cur', $this->memo) ? $this->memo['cur'] : ($this->memo['cur'] = $this->buildCurrencies());
    }

    /** @return array{current:array<string,mixed>,options:list<array<string,mixed>>}|null */
    private function buildLanguages(): ?array
    {
        [$request, $ctx] = $this->requestAndContext();
        if ($request === null || $ctx === null) {
            return null;
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT sl.locale_code code,sl.is_default,COALESCE(NULLIF(l.native_name,''),l.name,l.code) name
                 FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code
                 WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.sort_order,l.code",
                [$ctx->storeId],
            );
        } catch (\Throwable) {
            return null;
        }
        if (count($rows) < 2) {
            return null;
        }
        $defaultLocale = (string) $rows[0]['code'];
        foreach ($rows as $row) {
            if ((int) $row['is_default'] === 1) {
                $defaultLocale = (string) $row['code'];
                break;
            }
        }
        $alternates = $this->alternatePaths($request, $ctx);
        $currentPath = $this->currentPath($request);
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['code']] = $this->languageName((string) $row['code'], (string) $row['name']);
        }
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));
        $options = [];
        $current = null;
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $path = $alternates[$code] ?? $alternates[$defaultLocale] ?? $currentPath;
            $option = [
                'code' => $code,
                'short' => strtoupper(explode('-', $code, 2)[0]),
                // the language in its own words ("English", native name); the full catalogue name only when two locales share a language
                'name' => in_array($names[$code], $duplicates, true) ? (string) $row['name'] : $names[$code],
                'flag' => $this->flagRegion($code),
                'url' => $this->url($request, $path, ['lang' => $code]),
                'current' => $code === $ctx->locale,
            ];
            $options[] = $option;
            if ($option['current']) {
                $current = $option;
            }
        }

        return ['current' => $current ?? $options[0], 'options' => $options];
    }

    /** @return array{current:array<string,mixed>,options:list<array<string,mixed>>}|null */
    private function buildCurrencies(): ?array
    {
        [$request, $ctx] = $this->requestAndContext();
        if ($request === null || $ctx === null) {
            return null;
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT c.code,c.name,c.symbol FROM mc_store_currency sc JOIN mc_currency c ON c.code=sc.currency_code
                 WHERE sc.store_id=? AND sc.enabled=1
                   AND (sc.is_default=1 OR EXISTS (SELECT 1 FROM mc_price p WHERE p.store_id=sc.store_id AND p.currency=sc.currency_code LIMIT 1))
                 ORDER BY sc.is_default DESC,sc.sort_order,c.code",
                [$ctx->storeId],
            );
        } catch (\Throwable) {
            return null;
        }
        if (count($rows) < 2) {
            return null;
        }
        $path = $this->currentPath($request);
        $options = [];
        $current = null;
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $symbol = trim((string) ($row['symbol'] ?? ''));
            $option = [
                'code' => $code,
                'symbol' => $symbol !== '' ? $symbol : $code,
                'name' => (string) $row['name'],
                'url' => $this->url($request, $path, ['currency' => $code]),
                'current' => $code === $ctx->currency,
            ];
            $options[] = $option;
            if ($option['current']) {
                $current = $option;
            }
        }

        return ['current' => $current ?? $options[0], 'options' => $options];
    }

    /**
     * locale => path (no leading slash) of the entity behind the current URL, for every locale that has a route for it.
     *
     * @return array<string,string>
     */
    private function alternatePaths(Request $request, StorefrontContext $ctx): array
    {
        $path = $this->currentPath($request);
        if ($path === '') {
            return [];
        }
        try {
            $entity = $this->db->fetchAssociative(
                'SELECT entity_type,entity_public_id FROM mc_seo_route WHERE store_id=? AND path_hash=? ORDER BY (locale=?) DESC LIMIT 1',
                [$ctx->storeId, hash('sha256', $path, true), $ctx->locale],
            );
            if (!is_array($entity)) {
                return [];
            }
            $rows = $this->db->fetchAllAssociative(
                'SELECT locale,path FROM mc_seo_route WHERE store_id=? AND entity_type=? AND entity_public_id=?',
                [$ctx->storeId, $entity['entity_type'], $entity['entity_public_id']],
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['locale']] = trim((string) $row['path'], '/');
        }

        return $out;
    }

    private function currentPath(Request $request): string
    {
        return trim(rawurldecode($request->getPathInfo()), '/');
    }

    /** @param array<string,string> $override */
    private function url(Request $request, string $path, array $override): string
    {
        $query = $request->query->all();
        unset($query['lang'], $query['currency']);
        $query = array_filter($query, static fn (mixed $v): bool => is_scalar($v) || is_array($v));
        $href = '/' . implode('/', array_map('rawurlencode', $path === '' ? [] : explode('/', $path)));
        if ($path === '') {
            $href = '/';
        }

        return $href . '?' . http_build_query($query + $override, '', '&', PHP_QUERY_RFC3986);
    }

    private function languageName(string $code, string $fallback): string
    {
        $name = class_exists(\Locale::class) ? trim((string) \Locale::getDisplayLanguage($code, $code)) : '';
        if ($name === '' || $name === strtolower(explode('-', $code, 2)[0])) {
            return $fallback;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    private function flagRegion(string $locale): string
    {
        $parts = explode('-', str_replace('_', '-', $locale));
        if (strtolower($parts[0]) === 'en') {
            return 'GB'; // English is shown with the British flag whichever regional variant the locale carries
        }
        if (isset($parts[1]) && preg_match('/^[A-Za-z]{2}$/', $parts[1]) === 1) {
            return strtoupper($parts[1]);
        }

        return self::DEFAULT_REGION[strtolower($parts[0])] ?? '';
    }

    /** @return array{0:?Request,1:?StorefrontContext} */
    private function requestAndContext(): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [null, null];
        }
        try {
            return [$request, $this->contexts->resolve($request)];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
