<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Twig;

use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\Navigation\Application\NavigationManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StorefrontPresentationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StorefrontPresentationSettings $settings,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly ExtensionPackageManager $extensions,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly NavigationManager $navigationManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('storefront_presentation', [$this, 'presentation']),
            new TwigFunction('storefront_navigation', [$this, 'navigation']),
            new TwigFunction('site_capabilities', [$this, 'siteCapabilities']),
            new TwigFunction('active_theme_stylesheet', [$this, 'activeThemeStylesheet']),
            new TwigFunction('storefront_identity', [$this, 'identity']),
            new TwigFunction('storefront_locales', [$this, 'locales']),
            new TwigFunction('storefront_currencies', [$this, 'currencies']),
        ];
    }

    /** @return list<array{name:string,url:string}> */
    public function navigation(): array
    {
        try {
            $ctx = $this->context();
            if ($ctx === null) { return []; }
            try {
                $configured = $this->navigationManager->items($ctx->storeId, 'header', $ctx->locale);
                if ($configured !== []) {
                    return $this->mapNavigationRows($configured);
                }
            } catch (\Throwable) {
                // Schema can legitimately be older during a rolling upgrade; keep category fallback.
            }
            $rows = $this->connection->fetchAllAssociative(
                "SELECT ct.name,sr.path FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active' JOIN mc_market_category mk ON mk.category_id=c.id AND mk.market_id=? AND mk.status='active' JOIN mc_category_translation ct ON ct.id=(SELECT ctx.id FROM mc_category_translation ctx JOIN mc_store ctxs ON ctxs.id=ctx.store_id WHERE ctx.category_id=c.id AND ctx.store_id=? AND ctx.locale IN (?,ctxs.default_locale) ORDER BY (ctx.locale=ctxs.default_locale) ASC LIMIT 1) JOIN mc_seo_route sr ON sr.id=(SELECT srx.id FROM mc_seo_route srx JOIN mc_store srxs ON srxs.id=srx.store_id WHERE srx.store_id=? AND srx.locale IN (?,srxs.default_locale) AND srx.entity_type='category' AND srx.entity_public_id=c.public_id ORDER BY (srx.locale=srxs.default_locale) ASC LIMIT 1) WHERE c.status='active' AND c.parent_id IS NULL ORDER BY sc.sort_order,c.sort_order,c.id LIMIT 8",
                [$ctx->storeId, $ctx->marketId, $ctx->storeId, $ctx->locale, $ctx->storeId, $ctx->locale],
            );
            return array_map(static fn (array $row): array => ['name' => (string) $row['name'], 'url' => '/' . ltrim((string) $row['path'], '/'), 'children'=>[]], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    public function presentation(): array
    {
        try {
            $ctx = $this->context();
            return $ctx !== null ? $this->settings->get($ctx->storeId, $ctx->locale) : StorefrontPresentationSettings::defaults();
        } catch (\Throwable) {
            return StorefrontPresentationSettings::defaults();
        }
    }

    /** @return array<string,mixed> */
    public function siteCapabilities(): array
    {
        try {
            $ctx = $this->context();
            return $ctx !== null ? $this->capabilities->get($ctx->storeId) : SiteCapabilitySettings::profile(SiteCapabilitySettings::MODE_SHOP);
        } catch (\Throwable) {
            return SiteCapabilitySettings::profile(SiteCapabilitySettings::MODE_SHOP);
        }
    }

    /** @return array{name:string,locale:string,currency:string,timezone:string} */
    public function identity(): array
    {
        try {
            $ctx = $this->context();
            if ($ctx !== null) {
                $timezone = (string) $this->connection->fetchOne('SELECT timezone FROM mc_store WHERE id=?', [$ctx->storeId]);
                return ['name' => $ctx->storeName, 'locale' => $ctx->locale, 'currency' => $ctx->currency, 'timezone' => $timezone ?: 'UTC'];
            }
        } catch (\Throwable) {
        }
        return ['name' => 'Modern Shop', 'locale' => 'uk-UA', 'currency' => 'UAH', 'timezone' => 'Europe/Kyiv'];
    }

    /** @return list<array{code:string,name:string}> */
    public function locales(): array
    {
        try {
            $ctx = $this->context();
            if ($ctx === null) { return []; }
            return array_map(static fn(array $row): array => ['code'=>(string)$row['code'],'name'=>(string)$row['name']], $this->connection->fetchAllAssociative(
                'SELECT l.code,COALESCE(NULLIF(l.native_name,\'\'),l.name,l.code) name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.sort_order,l.code', [$ctx->storeId]
            ));
        } catch (\Throwable) { return []; }
    }

    /** @return list<array{code:string,symbol:string}> */
    public function currencies(): array
    {
        try {
            $ctx = $this->context();
            if ($ctx === null) { return []; }
            return array_map(static fn(array $row): array => ['code'=>(string)$row['code'],'symbol'=>(string)($row['symbol'] ?? '')], $this->connection->fetchAllAssociative(
                'SELECT c.code,c.symbol FROM mc_store_currency sc JOIN mc_currency c ON c.code=sc.currency_code WHERE sc.store_id=? AND sc.enabled=1 AND (sc.is_default=1 OR EXISTS (SELECT 1 FROM mc_price p WHERE p.store_id=sc.store_id AND p.currency=sc.currency_code)) ORDER BY sc.is_default DESC,sc.sort_order,c.code', [$ctx->storeId]
            ));
        } catch (\Throwable) { return []; }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function mapNavigationRows(array $rows): array
    {
        return array_map(function (array $row): array {
            return [
                'name' => (string) ($row['label'] ?? ''),
                'url' => (string) ($row['url'] ?? '#'),
                'badge' => $row['badge'] ?? null,
                'open_new_tab' => !empty($row['open_new_tab']),
                'children' => $this->mapNavigationRows(is_array($row['children'] ?? null) ? $row['children'] : []),
            ];
        }, $rows);
    }

    public function activeThemeStylesheet(): ?string
    {
        return $this->extensions->activeThemeStylesheet();
    }

    private function context(): ?\Commerce\Modules\Storefront\Domain\StorefrontContext
    {
        $request = $this->requests->getCurrentRequest();
        return $request !== null ? $this->contexts->resolve($request) : null;
    }
}
