<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Checkout\Application\CheckoutMethodSettings;
use Commerce\Modules\Order\Application\OrderMethodPresenter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CheckoutMethodSettingsTest extends TestCase
{
    private function connection(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_system_setting (setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT, updated_at TEXT)');

        return $db;
    }

    public function testEveryMethodIsEnabledUntilConfigured(): void
    {
        $settings = new CheckoutMethodSettings(new SystemSettingStore($this->connection()));
        self::assertTrue($settings->isEnabled(1, 'self_pickup'));
        self::assertTrue($settings->isEnabled(1, 'cash_on_delivery'));
        self::assertTrue($settings->isEnabled(1, 'monobank'), 'methods that cannot be switched here stay available');
    }

    public function testTickedMethodsStayOnAndOthersTurnOffPerStore(): void
    {
        $db = $this->connection();
        $store = new SystemSettingStore($db);
        // SQLite has no ON DUPLICATE KEY; seed the JSON document directly.
        $db->insert('mc_system_setting', ['setting_key' => 'checkout.methods.1', 'setting_value' => json_encode(['self_pickup' => true, 'cash_on_delivery' => false, 'nova_post' => false]), 'updated_at' => '2026-01-01']);
        $settings = new CheckoutMethodSettings($store);
        self::assertTrue($settings->isEnabled(1, 'self_pickup'));
        self::assertFalse($settings->isEnabled(1, 'cash_on_delivery'));
        self::assertFalse($settings->isEnabled(1, 'nova_post'));
        self::assertTrue($settings->isEnabled(1, 'bank_transfer'), 'a method missing from the document defaults to on');
        self::assertTrue($settings->isEnabled(2, 'cash_on_delivery'), 'other stores are not affected');
    }

    public function testPresenterBuildsReadableDeliveryLine(): void
    {
        $presenter = new OrderMethodPresenter(new StorefrontUiTranslator(dirname(__DIR__, 2)));
        self::assertSame('Self pickup', $presenter->delivery('self_pickup', 'en-US'));
        self::assertSame('Cash on delivery (pay on receipt)', $presenter->payment('cash_on_delivery', 'en-US'));
        self::assertSame('custom_carrier', $presenter->delivery('custom_carrier', 'en-US'));
        $line = $presenter->deliverySummary('self_pickup', ['point' => 'Main store', 'address' => '1 Main St', 'working_hours' => 'Mon-Fri 9-18'], 'en-US');
        self::assertSame('Self pickup — Main store, 1 Main St (Mon-Fri 9-18)', $line);
    }
}
