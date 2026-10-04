<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Core\Security\SecretVault;
use Commerce\Modules\Notification\Application\NotificationDispatcher;
use Commerce\Modules\Notification\Application\SmsService;
use Commerce\Modules\Notification\Application\SmsSettings;
use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Notification\Channel\Sms\SmsFlyApi;
use Commerce\Modules\Notification\Domain\SmsText;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmsTest extends TestCase
{
    /** @return iterable<string,array{string,string,int,int}> */
    public static function texts(): iterable
    {
        yield 'latin single' => ['Order 1001 shipped', 'gsm7', 18, 1];
        yield 'latin 160 is one' => [str_repeat('a', 160), 'gsm7', 160, 1];
        yield 'latin 161 is two' => [str_repeat('a', 161), 'gsm7', 161, 2];
        yield 'latin 306 is two' => [str_repeat('a', 306), 'gsm7', 306, 2];
        yield 'latin 307 is three' => [str_repeat('a', 307), 'gsm7', 307, 3];
        yield 'extended char counts twice' => ['[ok]', 'gsm7', 6, 1];
        yield 'cyrillic 70 is one' => [str_repeat('я', 70), 'ucs2', 70, 1];
        yield 'cyrillic 71 is two' => [str_repeat('я', 71), 'ucs2', 71, 2];
        yield 'one cyrillic letter switches the whole text' => [str_repeat('a', 69) . 'я', 'ucs2', 70, 1];
        yield 'emoji is two units' => ['😀', 'ucs2', 2, 1];
        yield 'empty' => ['', 'gsm7', 0, 0];
    }

    #[DataProvider('texts')]
    public function testSegmentRules(string $text, string $encoding, int $units, int $segments): void
    {
        $a = SmsText::analyze($text);
        $this->assertSame($encoding, $a['encoding']);
        $this->assertSame($units, $a['units']);
        $this->assertSame($segments, $a['segments']);
    }

    public function testPhoneIsNormalized(): void
    {
        $this->assertSame('+380501234567', SmsService::normalizePhone('050 123-45-67'));
        $this->assertSame('+380501234567', SmsService::normalizePhone('+38 (050) 123 45 67'));
        $this->assertSame('+4915112345678', SmsService::normalizePhone('+49 151 12345678'));
        $this->assertNull(SmsService::normalizePhone('12345'));
        $this->assertNull(SmsService::normalizePhone('abc'));
    }

    public function testManualSendIsLoggedAndAFailingGatewayIsReported(): void
    {
        $db = $this->database();
        $sent = new \ArrayObject();
        $service = $this->service($db, $sent, false);

        $disabled = $service->sendManual(1, 7, '0501234567', 'Hello', false, 3);
        $this->assertSame(['ok' => false, 'error' => 'disabled'], $disabled);

        $this->settings($db)->save(1, ['enabled' => true, 'endpoint' => 'https://sms.example.com/send', 'token' => 'secret', 'sender' => 'Shop']);
        $this->assertSame(['ok' => true, 'error' => ''], $service->sendManual(1, 7, '0501234567', 'Привіт', true, 3));
        $this->assertSame(['+380501234567|Привіт|1'], $sent->getArrayCopy());
        $this->assertSame(['ok' => false, 'error' => 'phone'], $service->sendManual(1, 7, '12', 'Hi', false, 3));
        $this->assertSame(['ok' => false, 'error' => 'text'], $service->sendManual(1, 7, '0501234567', '  ', false, 3));

        $failing = $this->service($db, $sent, true);
        $result = $failing->sendManual(1, 7, '0501234567', 'Hi', false, 3);
        $this->assertFalse($result['ok']);
        $this->assertSame('gateway down', $result['error']);

        $log = $service->log(1, 7);
        $this->assertSame(['failed', 'failed', 'failed', 'sent', 'failed'], array_column($log, 'status'));
        $this->assertSame('3', (string) $log[3]['admin_id']);
        $this->assertSame('1', (string) $log[3]['flash']);
    }

    public function testAutomaticSmsFollowsTheSwitchesAndIsSentOncePerOrderAndEvent(): void
    {
        $db = $this->database();
        $db->insert('mc_sales_order', ['id' => 9, 'store_id' => 1, 'order_number' => 'A-9', 'customer_phone' => '0671112233', 'customer_name' => 'Ira', 'total_minor' => 12550, 'currency' => 'UAH', 'locale' => 'en-US']);
        $db->insert('mc_fulfillment', ['id' => 1, 'order_id' => 9, 'tracking_number' => '5900001']);
        $sent = new \ArrayObject();
        $service = $this->service($db, $sent, false);

        $service->sendAuto(9, 'shipped'); // nothing saved and the environment is off: no SMS
        $this->assertCount(0, $sent);

        $this->settings($db)->save(1, ['enabled' => true, 'endpoint' => 'https://sms.example.com/send', 'auto_shipped' => true, 'auto_placed' => false]);
        $service->sendAuto(9, 'placed');
        $this->assertCount(0, $sent);
        $service->sendAuto(9, 'shipped');
        $service->sendAuto(9, 'shipped');
        $this->assertSame(['+380671112233|Order A-9 has been shipped. Tracking: 5900001|0'], $sent->getArrayCopy());

        $this->settings($db)->save(1, ['enabled' => true, 'endpoint' => 'https://sms.example.com/send', 'auto_ready' => true, 'tpl_ready' => 'Hi {customer_name}, {order_number} for {total} waits']);
        $service->sendAuto(9, 'ready');
        $this->assertSame('+380671112233|Hi Ira, A-9 for 125.50 UAH waits|0', $sent[1]);
    }

    public function testEnvironmentOnlyInstallationKeepsTheOrderPlacedSms(): void
    {
        $db = $this->database();
        $db->insert('mc_sales_order', ['id' => 5, 'store_id' => 1, 'order_number' => 'B-5', 'customer_phone' => '0671112233', 'customer_name' => 'Ira', 'total_minor' => 100, 'currency' => 'UAH', 'locale' => 'en-US']);
        $sent = new \ArrayObject();
        $service = $this->service($db, $sent, false, true);

        $this->assertTrue($service->autoEnabled(1, 'placed'));
        $this->assertFalse($service->autoEnabled(1, 'shipped'));
        $service->sendAuto(5, 'placed');
        $this->assertCount(1, $sent);
    }

    public function testSmsFlyRequestAndAnswer(): void
    {
        $body = SmsFlyApi::request('KEY', 'MyShop', '+380501234567', 'Привіт');
        $this->assertSame('SENDMESSAGE', $body['action']);
        $this->assertSame('KEY', $body['auth']['key']);
        $this->assertSame('380501234567', $body['data']['recipient']);
        $this->assertSame(['sms'], $body['data']['channels']);
        $this->assertSame('MyShop', $body['data']['sms']['source']);
        $this->assertSame('Привіт', $body['data']['sms']['text']);

        SmsFlyApi::assertAccepted(['success' => 1, 'data' => ['messageID' => 5]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMS-fly: Bad key');
        SmsFlyApi::assertAccepted(['success' => 0, 'error' => ['description' => 'Bad key', 'code' => '']]);
    }

    public function testDriverIsSavedAndUnknownDriverFallsBackToJson(): void
    {
        $db = $this->database();
        $settings = $this->settings($db);
        $settings->save(1, ['enabled' => true, 'driver' => 'smsfly', 'token' => 'k']);
        $this->assertSame('smsfly', $settings->get(1)['driver']);
        $settings->save(1, ['enabled' => true, 'driver' => 'evil']);
        $this->assertSame('json', $settings->get(1)['driver']);
    }

    private function settings(Connection $db): SmsSettings
    {
        return new SmsSettings($db, new SecretVault('unit-test-secret-0123456789abcdef0123456789'));
    }

    private function service(Connection $db, \ArrayObject $sent, bool $failing, bool $envEnabled = false): SmsService
    {
        $sender = new class ($sent, $failing) implements NotificationSenderInterface {
            public function __construct(private \ArrayObject $sent, private bool $failing)
            {
            }

            public function channel(): NotificationChannel
            {
                return NotificationChannel::Sms;
            }

            public function send(NotificationMessage $message, string $recipient): void
            {
                if ($this->failing) {
                    throw new \RuntimeException('gateway down');
                }
                $this->sent[] = $recipient . '|' . $message->text . '|' . (int) !empty($message->context['flash']);
            }
        };

        return new SmsService($db, $this->settings($db), new NotificationDispatcher([$sender]), new StorefrontUiTranslator(dirname(__DIR__, 2)), $envEnabled);
    }

    private function database(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_sms_settings (store_id INTEGER PRIMARY KEY, enabled INTEGER, driver TEXT, endpoint TEXT, token_enc TEXT, sender TEXT, flash_supported INTEGER, auto_placed INTEGER, auto_shipped INTEGER, auto_ready INTEGER, auto_cancelled INTEGER, tpl_placed TEXT, tpl_shipped TEXT, tpl_ready TEXT, tpl_cancelled TEXT, updated_at TEXT)');
        $db->executeStatement('CREATE TABLE mc_sms_log (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER, order_id INTEGER, recipient TEXT, mode TEXT, event TEXT, body TEXT, segments INTEGER, flash INTEGER, status TEXT, error TEXT, admin_id INTEGER, created_at TEXT)');
        $db->executeStatement('CREATE TABLE mc_sales_order (id INTEGER PRIMARY KEY, store_id INTEGER, order_number TEXT, customer_phone TEXT, customer_name TEXT, total_minor INTEGER, currency TEXT, locale TEXT)');
        $db->executeStatement('CREATE TABLE mc_fulfillment (id INTEGER PRIMARY KEY, order_id INTEGER, tracking_number TEXT)');

        return $db;
    }
}
