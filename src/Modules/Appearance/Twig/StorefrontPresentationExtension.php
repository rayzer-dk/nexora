<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Twig;

use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Appearance\Infrastructure\ChatWidgetSettings;
use Commerce\Modules\Appearance\Infrastructure\ContactWidgetSettings;
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
        private readonly ContactWidgetSettings $contactWidget,
        private readonly ChatWidgetSettings $chatWidget,
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
            new TwigFunction('contact_widget', [$this, 'contactWidget']),
            new TwigFunction('header_contacts', [$this, 'headerContacts']),
            new TwigFunction('chat_widget', [$this, 'chatWidget']),
            new TwigFunction('storefront_locales', [$this, 'locales']),
            new TwigFunction('storefront_currencies', [$this, 'currencies']),
        ];
    }

    /** @return array<string,mixed>|null */
    public function contactWidget(): ?array
    {
        try {
            $ctx = $this->context();

            return $ctx === null ? null : $this->contactWidget->storefront($ctx->storeId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The shop's own phone and e-mail (contact widget first, then the company profile) for the header. @return array{phone:string,phone_href:string,email:string} */
    public function headerContacts(): array
    {
        $empty = ['phone' => '', 'phone_href' => '', 'email' => ''];
        try {
            $ctx = $this->context();
            if ($ctx === null) {
                return $empty;
            }
            $widget = $this->contactWidget();
            $profile = $this->connection->fetchAssociative('SELECT phone,email FROM mc_store_profile WHERE store_id=?', [$ctx->storeId]) ?: [];
            $phone = '';
            foreach ((array) ($widget['actions'] ?? []) as $action) {
                if (($action['code'] ?? '') === 'call' && str_starts_with((string) $action['href'], 'tel:')) {
                    $phone = (string) substr((string) $action['href'], 4);
                }
            }
            $display = trim((string) ($profile['phone'] ?? ''));
            $digits = $phone !== '' ? $phone : (preg_replace('/[^0-9+]/', '', $display) ?? '');

            return [
                'phone' => $display !== '' ? $display : $digits,
                'phone_href' => $digits !== '' ? 'tel:' . $digits : '',
                'email' => trim((string) ($profile['email'] ?? '')),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** @return array{provider:string,id:string,base:string}|null */
    public function chatWidget(): ?array
    {
        try {
            $ctx = $this->context();
            $chat = $ctx === null ? null : $this->chatWidget->storefront($ctx->storeId);
            if ($chat === null) {
                return null;
            }
            // The security-headers subscriber extends the CSP with this vendor's hosts (and only this vendor's).
            \Commerce\Modules\Security\Http\CspExtra::merge($this->requests->getCurrentRequest(), $chat['csp']);

            return ['provider' => $chat['provider'], 'id' => $chat['id'], 'base' => $chat['base']];
        } catch (\Throwable) {
            return null;
        }
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
            // Every active top-level category of the market, in the shopper's language when translated, else in the shop's default language.
            $rows = $this->connection->fetchAllAssociative(
                "SELECT COALESCE(ct.name,dt.name) AS name,COALESCE(sr.path,dr.path) AS path
                 FROM mc_category c
                 JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
                 JOIN mc_market_category mk ON mk.category_id=c.id AND mk.market_id=? AND mk.status='active'
                 LEFT JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
                 LEFT JOIN mc_category_translation dt ON dt.category_id=c.id AND dt.store_id=? AND dt.locale=(SELECT default_locale FROM mc_store WHERE id=?)
                 LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id
                 LEFT JOIN mc_seo_route dr ON dr.store_id=? AND dr.locale=(SELECT default_locale FROM mc_store WHERE id=?) AND dr.entity_type='category' AND dr.entity_public_id=c.public_id
                 WHERE c.status='active' AND c.parent_id IS NULL AND COALESCE(ct.name,dt.name) IS NOT NULL AND COALESCE(sr.path,dr.path) IS NOT NULL
                 ORDER BY sc.sort_order,c.sort_order,c.id LIMIT 24",
                [$ctx->storeId, $ctx->marketId, $ctx->storeId, $ctx->locale, $ctx->storeId, $ctx->storeId, $ctx->storeId, $ctx->locale, $ctx->storeId, $ctx->storeId],
            );
            return array_map(static fn (array $row): array => ['name' => (string) $row['name'], 'url' => '/' . ltrim((string) $row['path'], '/'), 'children'=>[]], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    /** @var array<int,array<string,mixed>> */
    private array $presentationMemo = [];

    public function presentation(): array
    {
        try {
            $ctx = $this->context();
            return $ctx !== null ? ($this->presentationMemo[$ctx->storeId] ??= $this->settings->get($ctx->storeId)) : StorefrontPresentationSettings::defaults();
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
        return ['name' => '', 'locale' => 'uk-UA', 'currency' => 'UAH', 'timezone' => 'Europe/Kyiv'];
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
                "SELECT c.code,c.symbol FROM mc_store_currency sc JOIN mc_currency c ON c.code=sc.currency_code
                 WHERE sc.store_id=? AND sc.enabled=1
                   AND (sc.is_default=1 OR EXISTS (SELECT 1 FROM mc_price p WHERE p.store_id=sc.store_id AND p.currency=sc.currency_code LIMIT 1))
                 ORDER BY sc.is_default DESC,sc.sort_order,c.code", [$ctx->storeId]
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
                'icon' => (string) ($row['icon'] ?? ''),
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
