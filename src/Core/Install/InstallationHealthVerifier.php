<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class InstallationHealthVerifier
{
    public function __construct(private Connection $connection, private string $projectDir)
    {
    }

    /**
     * @return array{healthy:bool,checks:list<array{code:string,label:string,passed:bool,current:string,expected:string}>}
     */
    public function verify(): array
    {
        $checks = [];
        $add = static function (array &$target, string $code, string $label, bool $passed, string $current, string $expected): void {
            $target[] = compact('code', 'label', 'passed', 'current', 'expected');
        };

        try {
            $installationCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_installation WHERE id=1');
            $add($checks, 'installation', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.stan_vstanovlennia'), $installationCount === 1, (string) $installationCount, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.1_zapys'));

            $storeCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_store WHERE status='active'");
            $add($checks, 'store', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.aktyvnyi_mahazyn'), $storeCount >= 1, (string) $storeCount, '>=1');

            $marketCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_market WHERE status='active'");
            $add($checks, 'market', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.aktyvnyi_rynok'), $marketCount >= 1, (string) $marketCount, '>=1');

            $adminCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_admin_user WHERE status='active'");
            $add($checks, 'admin', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.aktyvnyi_administrator'), $adminCount >= 1, (string) $adminCount, '>=1');

            $localeCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_store_locale WHERE locale_code='uk-UA' AND enabled=1");
            $add($checks, 'locale', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.ukrainska_lokal'), $localeCount >= 1, (string) $localeCount, 'uk-UA enabled');

            $currencyCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_store_currency WHERE currency_code='UAH' AND enabled=1");
            $add($checks, 'currency', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.valiuta_uah'), $currencyCount >= 1, (string) $currencyCount, 'UAH enabled');

            $locationCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_inventory_location WHERE status='active'");
            $add($checks, 'inventory', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.aktyvnyi_sklad'), $locationCount >= 1, (string) $locationCount, '>=1');

            $pageCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_content_entry WHERE content_type='page'");
            $add($checks, 'content.pages', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.bazovi_informatsiini_storinky'), $pageCount >= 5, (string) $pageCount, '>=5');

            $taxClassCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_tax_class WHERE code='standard' AND enabled=1");
            $add($checks, 'tax.standard', \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installationhealthverifier.standartnyi_podatkovyi_klas'), $taxClassCount === 1, (string) $taxClassCount, '1');

            $migrationCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_migration_versions');
            $expectedMigrations = count(glob($this->projectDir . '/migrations/Version*.php') ?: []);
            $add($checks, 'migrations', \Commerce\Core\I18n\CanonicalUiText::get('install.health.migrations'), $migrationCount === $expectedMigrations, $migrationCount . '/' . $expectedMigrations, $expectedMigrations . '/' . $expectedMigrations);
        } catch (Throwable $e) {
            $add($checks, 'database', \Commerce\Core\I18n\CanonicalUiText::get('install.health.database_failed'), false, \Commerce\Core\I18n\CanonicalUiText::get('install.health.details_hidden'), \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.bez_pomylok'));
        }

        return [
            'healthy' => !in_array(false, array_column($checks, 'passed'), true),
            'checks' => $checks,
        ];
    }
}
