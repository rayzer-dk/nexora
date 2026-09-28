<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final readonly class StorefrontContextResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(Request $request): StorefrontContext
    {
        $host = $this->normalizeHost($request->getHost());
        $store = $this->storeForHost($host);
        if (!is_array($store)) {
            $activeCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_store WHERE status='active'");
            if ($activeCount === 1) {
                $store = $this->connection->fetchAssociative("SELECT id,name,default_locale,default_currency FROM mc_store WHERE status='active' LIMIT 1");
            }
        }
        if (!is_array($store)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('storefront.error.store_not_mapped'));
        }

        $storeId = (int) $store['id'];
        $locale = (string) $store['default_locale'];
        $requestedLocale = trim((string) ($request->query->get('lang') ?: $request->cookies->get('store_locale', '')));
        if ($requestedLocale !== '' && $this->isEnabledLocale($storeId, $requestedLocale)) {
            $locale = $requestedLocale;
        }

        $market = $this->resolveMarket($storeId, $request);
        if (!is_array($market)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('storefront.error.market_not_configured'));
        }

        $currency = (string) ($market['default_currency'] ?: $store['default_currency']);
        $requestedCurrency = strtoupper(trim((string) ($request->query->get('currency') ?: $request->cookies->get('store_currency', ''))));
        if ($requestedCurrency !== '' && $this->isEnabledCurrency($storeId, $requestedCurrency)) {
            $currency = $requestedCurrency;
        }

        $request->setLocale($locale);
        \Commerce\Core\I18n\CanonicalUiText::useLocale($locale);

        return new StorefrontContext(
            storeId: $storeId,
            marketId: (int) $market['id'],
            locale: $locale,
            currency: $currency,
            countryCode: (string) ($market['country_code'] ?: 'UA'),
            storeName: (string) $store['name'],
        );
    }

    public function defaultLocale(int $storeId): ?string
    {
        $locale = $this->connection->fetchOne(
            "SELECT s.default_locale FROM mc_store s WHERE s.id=? AND s.status='active' LIMIT 1",
            [$storeId],
        );

        if (!is_string($locale) || $locale === '') {
            return null;
        }

        return $this->isEnabledLocale($storeId, $locale) ? $locale : null;
    }

    private function storeForHost(string $host): array|false
    {
        if ($host === '') {
            return false;
        }
        try {
            return $this->connection->fetchAssociative(
                "SELECT s.id,s.name,s.default_locale,s.default_currency FROM mc_store_domain d JOIN mc_store s ON s.id=d.store_id AND s.status='active' WHERE d.host=? AND d.status='active' ORDER BY d.is_primary DESC,d.id ASC LIMIT 1",
                [$host],
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveMarket(int $storeId, Request $request): array|false
    {
        $marketCode = trim((string) ($request->query->get('market') ?: $request->cookies->get('store_market', '')));
        if ($marketCode !== '') {
            $market = $this->connection->fetchAssociative(
                "SELECT m.id,m.default_locale,m.default_currency,mc.country_code FROM mc_market m LEFT JOIN mc_market_country mc ON mc.market_id=m.id WHERE m.store_id=? AND m.code=? AND m.status='active' ORDER BY mc.country_code ASC LIMIT 1",
                [$storeId, $marketCode],
            );
            if (is_array($market)) {
                return $market;
            }
        }
        return $this->connection->fetchAssociative(
            "SELECT m.id,m.default_locale,m.default_currency,mc.country_code FROM mc_market m LEFT JOIN mc_market_country mc ON mc.market_id=m.id WHERE m.store_id=? AND m.status='active' ORDER BY m.id ASC,mc.country_code ASC LIMIT 1",
            [$storeId],
        );
    }

    private function isEnabledLocale(int $storeId, string $locale): bool
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$storeId, $locale]) === 1;
    }

    private function isEnabledCurrency(int $storeId, string $currency): bool
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mc_store_currency sc
             WHERE sc.store_id=? AND sc.currency_code=? AND sc.enabled=1
               AND (sc.is_default=1 OR EXISTS (SELECT 1 FROM mc_price p WHERE p.store_id=sc.store_id AND p.currency=sc.currency_code LIMIT 1))",
            [$storeId, $currency],
        ) === 1;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
