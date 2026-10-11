<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Notification\Messenger\MessengerSettings;
use PHPUnit\Framework\TestCase;

final class MessengerSettingsTest extends TestCase
{
    private function settings(bool $tg = true, string $token = '1:abc', string $user = '@shop_bot', bool $viber = true, string $uri = 'my shop'): MessengerSettings
    {
        return new MessengerSettings($tg, $token, $user, 'https://api.telegram.org/', $viber, 'vtoken', '', $uri, 'https://chatapi.viber.com/');
    }

    public function testReadinessNeedsSwitchAndKeys(): void
    {
        self::assertTrue($this->settings()->telegramReady());
        self::assertFalse($this->settings(tg: false)->telegramReady());
        self::assertFalse($this->settings(user: '')->telegramReady());
        self::assertFalse($this->settings(viber: false)->viberReady());
        self::assertFalse($this->settings(uri: '')->viberReady());
        self::assertFalse($this->settings(false, '', '', false, '')->anyReady());
    }

    public function testDeepLinksCarryTheTokenEscaped(): void
    {
        $s = $this->settings();
        self::assertSame('https://t.me/shop_bot?start=abc123', $s->deepLink('telegram', 'abc123'));
        self::assertSame('viber://pa?chatURI=my%20shop&context=abc123', $s->deepLink('viber', 'abc123'));
        self::assertSame('Shop', $s->viberName());
        self::assertSame('https://api.telegram.org', $s->telegramApiBase());
    }

    public function testWebhookSecretFollowsTheBotToken(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->settings()->webhookSecret());
        self::assertNotSame($this->settings()->webhookSecret(), $this->settings(token: '2:xyz')->webhookSecret());
    }
}
