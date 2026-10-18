<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Runtime\DeferredWorkSignal;
use Commerce\Modules\Customer\Application\CustomerInquiryService;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CustomerInquiryCheaperMailTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_store (id INTEGER PRIMARY KEY, default_locale TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_store_profile (store_id INTEGER, email TEXT, privacy_contact TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_product (id INTEGER PRIMARY KEY, public_id BLOB)');
        $this->db->executeStatement('CREATE TABLE mc_store_product (store_id INTEGER, product_id INTEGER)');
        $this->db->executeStatement('CREATE TABLE mc_product_translation (product_id INTEGER, store_id INTEGER, locale TEXT, name TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_customer_inquiry (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id BLOB, store_id INTEGER, product_id INTEGER, inquiry_type TEXT, status TEXT, customer_name TEXT, email TEXT, phone TEXT, message TEXT, source_url TEXT, admin_note TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_notification_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id BLOB, dedupe_key TEXT, channel TEXT, notification_type TEXT, recipient TEXT, payload TEXT, status TEXT, available_at TEXT, attempts INTEGER, created_at TEXT)');
        $this->db->insert('mc_store', ['id' => 1, 'default_locale' => 'uk-UA']);
        $this->db->insert('mc_store_profile', ['store_id' => 1, 'email' => 'manager@example.test', 'privacy_contact' => '']);
    }

    public function testCheaperInquiryMailsManagerWithProductInSubjectAndLinkOnItsOwnLine(): void
    {
        $productId = Uuid::v7();
        $this->db->insert('mc_product', ['id' => 1, 'public_id' => $productId->toBinary()]);
        $this->db->insert('mc_store_product', ['store_id' => 1, 'product_id' => 1]);
        $this->db->insert('mc_product_translation', ['product_id' => 1, 'store_id' => 1, 'locale' => 'uk-UA', 'name' => 'Чайник Test']);

        $service = new CustomerInquiryService($this->db, new PublicIdFactory(), new NotificationOutbox($this->db, new PublicIdFactory(), new DeferredWorkSignal()));
        $service->create(1, [
            'inquiry_type' => 'cheaper',
            'name' => 'Іван',
            'phone' => '+380501112233',
            'message' => "Чайник Test · 1200\nПосилання: https://rival.example.test/kettle\nЦіна: 990",
        ], $productId->toRfc4122());

        $payload = json_decode((string) $this->db->fetchOne("SELECT payload FROM mc_notification_outbox WHERE recipient = 'manager@example.test'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Знайшли дешевше: Чайник Test', $payload['subject']);
        self::assertContains('Посилання: https://rival.example.test/kettle', explode("\n", $payload['text']));
        self::assertContains('Ціна: 990', explode("\n", $payload['text']));
    }
}
