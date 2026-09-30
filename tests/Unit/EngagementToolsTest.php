<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Infrastructure\ContactWidgetSettings;
use Commerce\Modules\Seo\Application\IndexNowService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class EngagementToolsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_contact_widget (store_id INTEGER PRIMARY KEY, enabled INTEGER, position TEXT, callback_enabled INTEGER, write_enabled INTEGER, phone TEXT, email TEXT, viber TEXT, messenger TEXT, telegram TEXT, updated_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_store_profile (store_id INTEGER, phone TEXT)');
        $this->db->insert('mc_store_profile', ['store_id' => 1, 'phone' => '+380 (44) 111-22-33']);
    }

    public function testWidgetIsHiddenUntilEnabledAndBuildsChannelLinks(): void
    {
        $widget = new ContactWidgetSettings($this->db);
        self::assertNull($widget->storefront(1));
        $widget->save(1, ['enabled' => '1', 'callback_enabled' => '1', 'write_enabled' => '1', 'viber' => '+380 50 123 45 67', 'messenger' => 'https://www.facebook.com/nexora.page', 'telegram' => '@nexora_shop', 'position' => 'left']);
        $view = $widget->storefront(1);
        self::assertNotNull($view);
        self::assertSame('left', $view['position']);
        self::assertTrue($view['callback']);
        $links = array_column($view['actions'], 'href', 'code');
        self::assertSame('/contact#contact-form', $links['write']);
        self::assertSame('tel:+380441112233', $links['call']); // falls back to the store profile phone
        self::assertSame('viber://chat?number=%2B380501234567', $links['viber']);
        self::assertSame('https://m.me/nexora.page', $links['messenger']);
        self::assertSame('https://t.me/nexora_shop', $links['telegram']);
    }

    #[DataProvider('invalid')]
    public function testWidgetRejectsUnsafeValues(string $field, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ContactWidgetSettings($this->db))->save(1, ['enabled' => '1', $field => $value]);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalid(): iterable
    {
        yield 'phone letters' => ['phone', 'call me'];
        yield 'email' => ['email', 'not-an-email'];
        yield 'viber short' => ['viber', '12345'];
        yield 'messenger foreign host' => ['messenger', 'https://evil.example/page'];
        yield 'telegram script' => ['telegram', 'javascript:alert(1)'];
    }

    public function testIndexNowKeyIsStablePerHostAndNeedsPublicHttps(): void
    {
        $public = new IndexNowService($this->db, new MockHttpClient(), 'https://shop.example.com', 'secret-a');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $public->key());
        self::assertSame($public->key(), (new IndexNowService($this->db, new MockHttpClient(), 'https://shop.example.com', 'secret-a'))->key());
        self::assertNotSame($public->key(), (new IndexNowService($this->db, new MockHttpClient(), 'https://shop.example.com', 'secret-b'))->key());
        self::assertSame('https://shop.example.com/' . $public->key() . '.txt', $public->keyLocation());
        self::assertTrue($public->usable());
        foreach (['http://shop.example.com', 'https://localhost', 'https://127.0.0.1', 'https://shop.local'] as $url) {
            self::assertFalse((new IndexNowService($this->db, new MockHttpClient(), $url, 's'))->usable(), $url);
        }
    }
}
