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
            $this->seedLocaleAndCurrency($db, $now);

            $storePublicId = $this->publicIds->binary();
            $db->insert('mc_store', [
                'public_id' => $storePublicId,
                'code' => 'default',
                'name' => trim($request->storeName),
                'default_locale' => 'uk-UA',
                'default_currency' => 'UAH',
                'timezone' => 'Europe/Kyiv',
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

            $db->insert('mc_store_locale', ['store_id' => $storeId, 'locale_code' => 'uk-UA', 'enabled' => 1, 'is_default' => 1, 'url_prefix' => null, 'sort_order' => 10]);
            $db->insert('mc_store_currency', ['store_id' => $storeId, 'currency_code' => 'UAH', 'enabled' => 1, 'is_default' => 1, 'auto_convert' => 0, 'rounding_increment_minor' => 1, 'sort_order' => 10]);

            $db->insert('mc_market', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'code' => 'ua', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.ukraina'),
                'default_locale' => 'uk-UA', 'default_currency' => 'UAH', 'legacy_tax_display_mode' => 'inclusive',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $marketId = (int) $db->lastInsertId();
            $db->insert('mc_market_country', ['market_id' => $marketId, 'country_code' => 'UA']);
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
            if ($standardTaxClass !== false) {
                $db->insert('mc_tax_rate', [
                    'tax_class_id' => (int) $standardTaxClass, 'country_code' => 'UA', 'region_code' => null, 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.standartnyi_pdv'),
                    'rate_bps' => 2000, 'priority' => 100, 'valid_from' => '2000-01-01 00:00:00.000000', 'valid_to' => null,
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
                'store_id' => $storeId, 'legal_name' => null, 'registration_number' => null, 'country_code' => 'UA',
                'registration_address' => null, 'email' => trim($request->adminEmail), 'phone' => null,
                'privacy_contact' => trim($request->adminEmail), 'return_contact' => trim($request->adminEmail),
                'warranty_contact' => trim($request->adminEmail), 'updated_at' => $now,
            ]);

            $this->siteCapabilities->applyMode($storeId, $request->siteMode, 'system:installer');
            $this->seedForumBoards($db, $storeId, $now);

            $this->seedInformationPages($db, $storeId, $now, trim($request->storeName), trim($request->adminEmail));
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

    private function seedLocaleAndCurrency(Connection $db, string $now): void
    {
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_locale WHERE code = ?', ['uk-UA']) === 0) {
            $db->insert('mc_locale', [
                'code' => 'uk-UA', 'language_code' => 'uk', 'region_code' => 'UA', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.ukrainska_ukraina'),
                'native_name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.ukrainska'), 'direction' => 'ltr', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_currency WHERE code = ?', ['UAH']) === 0) {
            $db->insert('mc_currency', [
                'code' => 'UAH', 'numeric_code' => '980', 'name' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationseeder.ukrainska_hryvnia'), 'symbol' => '₴',
                'minor_units' => 2, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
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

    private function seedInformationPages(Connection $db, int $storeId, string $now, string $storeName, string $email): void
    {
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
                'content_id' => $contentId, 'locale' => 'uk-UA', 'title' => trim((string) ($definition['title_key'] ?? '')) !== '' ? \Commerce\Core\I18n\CanonicalUiText::get((string) $definition['title_key']) : (string) ($definition['title'] ?? $key),
                'excerpt' => null, 'body_html' => $templates->body((string) $key, 'uk-UA', $profile), 'meta_title' => null, 'meta_description' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
