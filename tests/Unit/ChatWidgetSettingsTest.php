<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Infrastructure\ChatWidgetSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatWidgetSettingsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_chat_widget (store_id INTEGER PRIMARY KEY, provider TEXT, widget_id TEXT, base_url TEXT, updated_at TEXT)');
    }

    /** @return iterable<string,array{string,string,?string}> */
    public static function snippets(): iterable
    {
        yield 'tawk snippet' => ['tawk', "s1.src='https://embed.tawk.to/5f8a1b2c3d4e5f6a7b8c9d0e/1hab2cdef';", '5f8a1b2c3d4e5f6a7b8c9d0e/1hab2cdef'];
        yield 'tawk bare' => ['tawk', '5f8a1b2c3d4e5f6a7b8c9d0e/default', '5f8a1b2c3d4e5f6a7b8c9d0e/default'];
        yield 'jivo snippet' => ['jivo', '<script src="//code.jivosite.com/widget/AbC123xYz9" async></script>', 'AbC123xYz9'];
        yield 'crisp snippet' => ['crisp', 'window.CRISP_WEBSITE_ID="AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE";', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
        yield 'chatwoot snippet' => ['chatwoot', "window.chatwootSDK.run({ websiteToken: 'abcDEF1234567890', baseUrl: BASE_URL })", 'abcDEF1234567890'];
        yield 'garbage' => ['tawk', '<script>alert(1)</script>', null];
        yield 'jivo too short' => ['jivo', 'abc', null];
    }

    #[DataProvider('snippets')]
    public function testIdIsExtractedFromPastedCodeOrRejected(string $provider, string $input, ?string $expected): void
    {
        self::assertSame($expected, ChatWidgetSettings::extractId($provider, $input));
    }

    public function testChatwootBaseMustBeHttps(): void
    {
        self::assertSame('https://chat.example.com', ChatWidgetSettings::extractChatwootBase('https://chat.example.com/some/path'));
        self::assertSame('https://chat.example.com', ChatWidgetSettings::extractChatwootBase('var BASE_URL="https://chat.example.com";'));
        self::assertNull(ChatWidgetSettings::extractChatwootBase('http://chat.example.com'));
        self::assertNull(ChatWidgetSettings::extractChatwootBase('https://localhost'));
        self::assertNull(ChatWidgetSettings::extractChatwootBase('javascript:alert(1)'));
    }

    public function testSaveStorefrontCspAndSwitchOff(): void
    {
        $chat = new ChatWidgetSettings($this->db);
        self::assertNull($chat->storefront(1));
        $chat->save(1, 'crisp', 'window.CRISP_WEBSITE_ID="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee";');
        $view = $chat->storefront(1);
        self::assertSame('crisp', $view['provider'] ?? null);
        self::assertContains('https://client.crisp.chat', $view['csp']['script'] ?? []);
        $chat->save(1, 'crisp', ''); // an empty field keeps the connected ID
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $chat->get(1)['widget_id']);
        $chat->save(1, 'chatwoot', 'abcDEF1234567890', 'https://chat.example.com');
        self::assertSame(['https://chat.example.com', 'wss://chat.example.com'], $chat->storefront(1)['csp']['connect'] ?? []);
        $chat->save(1, 'none', '');
        self::assertNull($chat->storefront(1));
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ChatWidgetSettings($this->db))->save(1, 'tawk', 'not an id');
    }
}
