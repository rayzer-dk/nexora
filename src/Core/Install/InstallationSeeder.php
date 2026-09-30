<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Admin\Domain\AdminUser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class InstallationSeeder
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private PasswordHasherFactoryInterface $passwordHashers,
        private SiteCapabilitySettings $siteCapabilities,
        private string $projectDir,
    ) {
    }

    /** @return array{store_id:int,market_id:int,admin_id:int} */
    public function seed(InstallRequest $request): array
    {
        return $this->connection->transactional(function (Connection $db) use ($request): array {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_installation WHERE id = 1') > 0) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.23a86fc68eec'));
            }

            $now = $this->now();
            $locale = $request->localeCode();
            $currency = $request->currencyCode();
            $country = $request->countryCode();
            \Commerce\Core\I18n\CanonicalUiText::useLocale(in_array(substr($locale, 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US');
            $this->seedLocaleAndCurrency($db, $now, $locale, $currency);

            $storePublicId = $this->publicIds->binary();
            $db->insert('mc_store', [
                'public_id' => $storePublicId,
                'code' => 'default',
                'name' => trim($request->storeName),
                'default_locale' => $locale,
                'default_currency' => $currency,
                'timezone' => $request->timezoneName(),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $storeId = (int) $db->lastInsertId();

            $publicHost = strtolower(rtrim(trim((string) parse_url($request->publicUrl, PHP_URL_HOST)), '.'));
            if ($publicHost === '' || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $publicHost) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.ne_vdalosia_vyznachyty_korektnyi_domen_mahazynu_z_pu'));
            }
            $db->insert('mc_store_domain', [
                'store_id' => $storeId,
                'host' => $publicHost,
                'is_primary' => 1,
                'redirect_to_primary' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $db->insert('mc_store_locale', ['store_id' => $storeId, 'locale_code' => $locale, 'enabled' => 1, 'is_default' => 1, 'url_prefix' => null, 'sort_order' => 10]);
            $db->insert('mc_store_currency', ['store_id' => $storeId, 'currency_code' => $currency, 'enabled' => 1, 'is_default' => 1, 'auto_convert' => 0, 'rounding_increment_minor' => 1, 'sort_order' => 10]);

            $db->insert('mc_market', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'code' => strtolower($country), 'name' => RegionCatalog::countryName($country, $locale),
                'default_locale' => $locale, 'default_currency' => $currency, 'legacy_tax_display_mode' => 'inclusive',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $marketId = (int) $db->lastInsertId();
            $db->insert('mc_market_country', ['market_id' => $marketId, 'country_code' => $country]);
            $db->insert('mc_market_tax_policy', [
                'market_id' => $marketId, 'consumer_display_mode' => 'price_only', 'business_display_mode' => 'net_with_gross',
                'prices_entered_including_tax' => 1, 'calculation_basis' => 'destination', 'merchant_feed_gross_price' => 1, 'updated_at' => $now,
            ]);
            $db->insert('mc_market_consumer_policy', [
                'market_id' => $marketId, 'withdrawal_days' => 14, 'legal_guarantee_months' => null, 'default_delivery_days' => null,
                'lowest_price_lookback_days' => 30, 'show_lowest_price_on_reduction' => 0, 'require_explicit_optional_extras' => 1,
                'payment_obligation_label_required' => 1, 'updated_at' => $now,
            ]);
            $standardTaxClass = $db->fetchOne("SELECT id FROM mc_tax_class WHERE code='standard' AND enabled=1 LIMIT 1");
            if ($standardTaxClass !== false && $country !== 'OTHER') {
                $db->insert('mc_tax_rate', [
                    'tax_class_id' => (int) $standardTaxClass, 'country_code' => $country, 'region_code' => null, 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.standard_tax'),
                    'rate_bps' => RegionCatalog::preset($country)['vat_bps'], 'priority' => 100, 'valid_from' => '2000-01-01 00:00:00.000000', 'valid_to' => null,
                    'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $db->insert('mc_inventory_location', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'code' => 'main', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.osnovnyi_sklad'),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $locationId = (int) $db->lastInsertId();
            $db->insert('mc_market_inventory_location', ['market_id' => $marketId, 'location_id' => $locationId, 'priority' => 100]);

            $hasher = $this->passwordHashers->getPasswordHasher(AdminUser::class);
            $db->insert('mc_admin_user', [
                'public_id' => $this->publicIds->binary(),
                'email' => trim($request->adminEmail),
                'email_normalized' => mb_strtolower(trim($request->adminEmail), 'UTF-8'),
                'display_name' => trim($request->adminName) !== '' ? trim($request->adminName) : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator'),
                'password_hash' => $hasher->hash($request->adminPassword),
                'roles' => json_encode(['ROLE_SUPER_ADMIN'], JSON_THROW_ON_ERROR),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
            ]);
            $adminId = (int) $db->lastInsertId();

            $db->insert('mc_store_profile', [
                'store_id' => $storeId, 'legal_name' => null, 'registration_number' => null, 'country_code' => $country === 'OTHER' ? 'US' : $country,
                'registration_address' => null, 'email' => trim($request->adminEmail), 'phone' => null,
                'privacy_contact' => trim($request->adminEmail), 'return_contact' => trim($request->adminEmail),
                'warranty_contact' => trim($request->adminEmail), 'updated_at' => $now,
            ]);

            $this->siteCapabilities->applyMode($storeId, $request->siteMode, 'system:installer');
            $this->seedForumBoards($db, $storeId, $now);

            $this->seedInformationPages($db, $storeId, $now, trim($request->storeName), trim($request->adminEmail), $locale);
            $db->insert('mc_consent_policy', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'policy_version' => '1.0',
                'legal_document_id' => null, 'status' => 'active', 'default_region_mode' => 'eu_strict',
                'effective_from' => $now, 'effective_to' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $db->insert('mc_installation', [
                'id' => 1, 'public_id' => $this->publicIds->binary(), 'install_id' => $this->publicIds->binary(),
                'platform_version' => PlatformVersion::VERSION, 'extension_api_version' => PlatformVersion::EXTENSION_API,
                'installed_at' => $now, 'updated_at' => $now,
            ]);

            return ['store_id' => $storeId, 'market_id' => $marketId, 'admin_id' => $adminId];
        });
    }

    private function seedLocaleAndCurrency(Connection $db, string $now, string $locale, string $currency): void
    {
        foreach (array_unique([$locale, 'en-US', ...RegionCatalog::BUNDLED_LOCALES]) as $code) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_locale WHERE code = ?', [$code]) > 0) {
                continue;
            }
            $parts = explode('-', $code);
            $db->insert('mc_locale', [
                'code' => $code, 'language_code' => $parts[0], 'region_code' => $parts[1] ?? null,
                'name' => RegionCatalog::localeName($code, 'en'), 'native_name' => RegionCatalog::localeName($code, $code),
                'direction' => 'ltr', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // The whole currency list is registered so the merchant only has to switch a currency on; a code missing from
        // the list can still be added by hand in Admin → System → Localization.
        foreach (RegionCatalog::currencies() as $code => $row) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_currency WHERE code = ?', [$code]) === 0) {
                $db->insert('mc_currency', [
                    'code' => $code, 'numeric_code' => $row['numeric'], 'name' => $row['name'], 'symbol' => $row['symbol'],
                    'minor_units' => $row['minor_units'], 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_currency WHERE code = ?', [$currency]) === 0) {
            $db->insert('mc_currency', ['code' => $currency, 'numeric_code' => null, 'name' => $currency, 'symbol' => $currency, 'minor_units' => 2, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function seedForumBoards(Connection $db, int $storeId, string $now): void
    {
        if (!$db->createSchemaManager()->tablesExist(['mc_forum_board'])) {
            return;
        }
        foreach ([
            ['slug' => 'general', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.zahalni_obhovorennia'), 'description' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.pytannia_dosvid_i_spilkuvannia_spilnoty'), 'sort_order' => 10],
            ['slug' => 'news-help', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.novyny_ta_dopomoha'), 'description' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.novyny_saitu_pidkazky_ta_dopomoha_korystuvacham'), 'sort_order' => 20],
        ] as $board) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_forum_board WHERE store_id=? AND slug=?', [$storeId, $board['slug']]) > 0) {
                continue;
            }
            $db->insert('mc_forum_board', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'slug' => $board['slug'],
                'name' => $board['name'], 'description' => $board['description'], 'status' => 'active',
                'sort_order' => $board['sort_order'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedInformationPages(Connection $db, int $storeId, string $now, string $storeName, string $email, string $storeLocale): void
    {
        // Legal page drafts ship in Ukrainian and English only: other store languages start from the English drafts.
        $pageLocale = in_array(substr($storeLocale, 0, 2), ['uk', 'ru'], true) ? 'uk-UA' : 'en-US';
        \Commerce\Core\I18n\CanonicalUiText::useLocale($pageLocale);
        $path = $this->projectDir . '/config/content/information_pages.json';
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $templates = new \Commerce\Modules\Content\System\InformationPageTemplates($this->projectDir);
        $profile = ['store_name' => $storeName, 'email' => $email, 'privacy_contact' => $email, 'return_contact' => $email, 'warranty_contact' => $email];
        foreach (($document['pages'] ?? []) as $key => $definition) {
            $db->insert('mc_content_entry', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'content_type' => 'page', 'system_key' => (string) $key, 'status' => 'draft',
                'author_subject' => 'system:installer', 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $contentId = (int) $db->lastInsertId();
            $db->insert('mc_content_translation', [
                'content_id' => $contentId, 'locale' => $pageLocale, 'title' => trim((string) ($definition['title_key'] ?? '')) !== '' ? \Commerce\Core\I18n\CanonicalUiText::get((string) $definition['title_key']) : (string) ($definition['title'] ?? $key),
                'excerpt' => null, 'body_html' => $templates->body((string) $key, $pageLocale, $profile), 'meta_title' => null, 'meta_description' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
