<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Notification\Application\NotificationTemplateService;
use Commerce\Modules\Notification\Channel\Email\EmailNotificationSender;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

final class NotificationTemplateServiceTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement("CREATE TABLE mc_store (id INTEGER PRIMARY KEY, name TEXT, default_locale TEXT, status TEXT)");
        $this->db->executeStatement("INSERT INTO mc_store VALUES (1,'Acme Shop','uk-UA','active')");
        $this->db->executeStatement('CREATE TABLE mc_notification_template (store_id INTEGER, template_code TEXT, locale TEXT, subject TEXT, body TEXT, enabled INTEGER, updated_at TEXT, PRIMARY KEY (store_id, template_code, locale))');
    }

    private function message(): NotificationMessage
    {
        return new NotificationMessage('order.created', 'Default subject', 'Default text', ['locale' => 'uk-UA', 'order_number' => 'A-100', 'customer_name' => 'Ira', 'total_minor' => 123450, 'currency' => 'UAH'], 'order_created');
    }

    private function insert(string $subject, string $body, int $enabled): void
    {
        $this->db->insert('mc_notification_template', ['store_id' => 1, 'template_code' => 'order.created', 'locale' => 'uk-UA', 'subject' => $subject, 'body' => $body, 'enabled' => $enabled, 'updated_at' => '2026-01-01']);
    }

    public function testPlaceholdersAreRenderedAndUnknownOnesKept(): void
    {
        $service = new NotificationTemplateService($this->db);
        self::assertSame('Hi Ira, A-100 / %unknown%', $service->render('Hi %customer_name%, %order_number% / %unknown%', ['customer_name' => 'Ira', 'order_number' => 'A-100']));
    }

    public function testEnabledOverrideIsResolvedWithOrderVariables(): void
    {
        $this->insert('Thanks %order_number% — %store_name%', "Hello %customer_name%\nTotal %total%", 1);
        $resolved = (new NotificationTemplateService($this->db))->resolve($this->message());
        self::assertSame('Thanks A-100 — Acme Shop', $resolved['subject'] ?? null);
        self::assertSame("Hello Ira\nTotal 1 234,50 UAH", $resolved['body'] ?? null);
    }

    public function testDisabledOrMissingOverrideKeepsBuiltInText(): void
    {
        $service = new NotificationTemplateService($this->db);
        self::assertNull($service->resolve($this->message()));
        $this->insert('x', 'y', 0);
        self::assertNull($service->resolve($this->message()));
        self::assertNull($service->resolve(new NotificationMessage('admin.test', 's', 't')));
    }

    public function testSenderUsesOverrideSubjectAndCustomBody(): void
    {
        $this->insert('Thanks %order_number%', 'Packing %order_number%', 1);
        $mailer = new class implements MailerInterface {
            public ?TemplatedEmail $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message instanceof TemplatedEmail ? $message : null;
            }
        };
        (new EmailNotificationSender($mailer, 'shop@example.test', 'Shop', new NotificationTemplateService($this->db)))->send($this->message(), 'ira@example.test');
        self::assertSame('Thanks A-100', $mailer->sent?->getSubject());
        self::assertSame('Packing A-100', $mailer->sent?->getContext()['custom_body'] ?? null);
        self::assertSame('A-100', $mailer->sent?->getContext()['order_number'] ?? null);
    }

    public function testSaveRejectsInvalidInputAndResetRemovesOverride(): void
    {
        $service = new NotificationTemplateService($this->db);
        foreach ([['unknown.code', 'S', 'B'], ['order.created', '   ', 'B'], ['order.created', 'S', '<b></b>']] as [$code, $subject, $body]) {
            try {
                $service->save(1, $code, 'uk-UA', $subject, $body, true);
                self::fail('Invalid template must be rejected: ' . $code . '/' . $subject);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->insert('S', 'B', 1);
        self::assertArrayHasKey('order.created', $service->forLocale(1, 'uk-UA'));
        $service->reset(1, 'order.created', 'uk-UA');
        self::assertSame([], $service->forLocale(1, 'uk-UA'));
    }
}
