<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

use Commerce\Core\Site\SiteCapabilitySettings;
use InvalidArgumentException;

final readonly class InstallRequest
{
    public function __construct(
        public string $storeName,
        public string $adminName,
        public string $adminEmail,
        public string $adminPassword,
        public string $publicUrl,
        public string $siteMode = SiteCapabilitySettings::MODE_SHOP,
        public string $country = 'UA',
        public string $currency = '',
        public string $locale = '',
        public string $timezone = '',
    ) {
        if (trim($storeName) === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installrequest.vkazhit_nazvu_mahazynu'));
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installrequest.vkazhit_korektnyi_email_administratora'));
        }
        if (mb_strlen($adminPassword, 'UTF-8') < 12) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.adminaccesscontroller.parol_administratora_maie_mistyty_shchonaimenshe_12_'));
        }
        if ($currency !== '' && (preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.localization.currency.invalid_code'));
        }
        if ($locale !== '' && !in_array($locale, RegionCatalog::BUNDLED_LOCALES, true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.localization.locale.invalid_code'));
        }
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.localization.locale.invalid_code'));
        }
        if (!in_array($siteMode, SiteCapabilitySettings::modes(), true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installrequest.nekorektnyi_rezhym_saitu'));
        }
        $scheme = parse_url($publicUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installrequest.publichna_adresa_maie_vykorystovuvaty_http_abo_https'));
        }
    }

    public function countryCode(): string
    {
        return RegionCatalog::preset($this->country)['country'];
    }

    public function currencyCode(): string
    {
        return $this->currency !== '' ? $this->currency : RegionCatalog::preset($this->country)['currency'];
    }

    public function localeCode(): string
    {
        return $this->locale !== '' ? $this->locale : RegionCatalog::preset($this->country)['locale'];
    }

    public function timezoneName(): string
    {
        return $this->timezone !== '' ? $this->timezone : RegionCatalog::preset($this->country)['timezone'];
    }
}
