<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminContextResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(Request $request): AdminContext
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        $requestedStore = max(0, (int) ($request->query->get('store_id') ?: $request->headers->get('X-Store-Id', '0')));
        $storeId = $requestedStore;
        if ($storeId === 0 && $session !== null) {
            $storeId = max(0, (int) $session->get('admin_context.store_id', 0));
        }
        if ($storeId === 0) {
            $storeId = (int) $this->connection->fetchOne("SELECT id FROM mc_store WHERE status='active' ORDER BY id LIMIT 1");
        }
        if ($storeId < 1 || (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_store WHERE id=? AND status='active'", [$storeId]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7ebab1e6337e'));
        }

        $requestedMarket = max(0, (int) ($request->query->get('market_id') ?: $request->headers->get('X-Market-Id', '0')));
        $marketId = $requestedMarket;
        if ($marketId === 0 && $session !== null && (int) $session->get('admin_context.store_id', 0) === $storeId) {
            $marketId = max(0, (int) $session->get('admin_context.market_id', 0));
        }
        if ($marketId === 0) {
            $marketId = (int) $this->connection->fetchOne("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1", [$storeId]);
        }
        if ($marketId < 1 || (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_market WHERE id=? AND store_id=? AND status='active'", [$marketId, $storeId]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9bd0a8264f4d'));
        }

        $requestedLocale = trim((string) ($request->query->get('locale') ?: $request->headers->get('X-Locale', '')));
        $locale = $requestedLocale;
        if ($locale === '' && $session !== null && (int) $session->get('admin_context.store_id', 0) === $storeId) {
            $locale = trim((string) $session->get('admin_context.locale', ''));
        }
        if ($locale === '') {
            $locale = (string) $this->connection->fetchOne('SELECT default_locale FROM mc_market WHERE id=?', [$marketId]);
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$storeId, $locale]) !== 1) {
            $locale = (string) $this->connection->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        }
        if ($locale === '' || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$storeId, $locale]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0b7b5852a318'));
        }

        $requestedCurrency = strtoupper(trim((string) ($request->query->get('currency') ?: $request->headers->get('X-Currency', ''))));
        $currency = $requestedCurrency;
        if ($currency === '' && $session !== null && (int) $session->get('admin_context.store_id', 0) === $storeId) {
            $currency = strtoupper(trim((string) $session->get('admin_context.currency', '')));
        }
        if ($currency === '') {
            $currency = strtoupper((string) $this->connection->fetchOne('SELECT default_currency FROM mc_market WHERE id=?', [$marketId]));
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_store_currency WHERE store_id=? AND currency_code=? AND enabled=1', [$storeId, $currency]) !== 1) {
            $currency = strtoupper((string) $this->connection->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]));
        }
        if ($currency === '' || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_store_currency WHERE store_id=? AND currency_code=? AND enabled=1', [$storeId, $currency]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.10fff149cbbd'));
        }

        if ($session !== null) {
            $session->set('admin_context.store_id', $storeId);
            $session->set('admin_context.market_id', $marketId);
            $session->set('admin_context.locale', $locale);
            $session->set('admin_context.currency', $currency);
        }

        return new AdminContext($storeId, $marketId, $locale, $currency);
    }
}
