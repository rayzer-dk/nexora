<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Runtime\DeferredWorkSignal;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Admin\Http\PrroAdminController;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Prro\Application\FiscalizationService;
use Commerce\Modules\Prro\Application\PrroSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The manual "send the receipt" action must only reach receipts of the store the administrator works in. */
final class PrroReceiptSendScopeTest extends TestCase
{
    private Connection $db;
    private Session $session;

    private function controller(): PrroAdminController
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_store (id INTEGER PRIMARY KEY, status TEXT, default_locale TEXT, default_currency TEXT)');
        $db->executeStatement('CREATE TABLE mc_market (id INTEGER PRIMARY KEY, store_id INTEGER, status TEXT, default_locale TEXT, default_currency TEXT)');
        $db->executeStatement('CREATE TABLE mc_store_locale (store_id INTEGER, locale_code TEXT, enabled INTEGER)');
        $db->executeStatement('CREATE TABLE mc_store_currency (store_id INTEGER, currency_code TEXT, enabled INTEGER)');
        $db->executeStatement('CREATE TABLE mc_sales_order (id INTEGER PRIMARY KEY, store_id INTEGER, order_number TEXT, customer_email TEXT)');
        $db->executeStatement('CREATE TABLE mc_fiscal_receipt (id INTEGER PRIMARY KEY, order_id INTEGER, status TEXT, receipt_url TEXT)');
        $db->executeStatement('CREATE TABLE mc_notification_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id BLOB, dedupe_key TEXT, channel TEXT, notification_type TEXT, recipient TEXT, payload TEXT, status TEXT, available_at TEXT, attempts INTEGER, created_at TEXT)');
        foreach ([1, 2] as $store) {
            $db->insert('mc_store', ['id' => $store, 'status' => 'active', 'default_locale' => 'uk-UA', 'default_currency' => 'UAH']);
            $db->insert('mc_market', ['id' => $store, 'store_id' => $store, 'status' => 'active', 'default_locale' => 'uk-UA', 'default_currency' => 'UAH']);
            $db->insert('mc_store_locale', ['store_id' => $store, 'locale_code' => 'uk-UA', 'enabled' => 1]);
            $db->insert('mc_store_currency', ['store_id' => $store, 'currency_code' => 'UAH', 'enabled' => 1]);
        }
        // Receipt 10 belongs to an order of store 1, receipt 20 to an order of store 2.
        $db->insert('mc_sales_order', ['id' => 1, 'store_id' => 1, 'order_number' => 'A-1', 'customer_email' => 'own@example.test']);
        $db->insert('mc_sales_order', ['id' => 2, 'store_id' => 2, 'order_number' => 'B-1', 'customer_email' => 'foreign@example.test']);
        $db->insert('mc_fiscal_receipt', ['id' => 10, 'order_id' => 1, 'status' => 'done', 'receipt_url' => 'https://receipt.example.test/10']);
        $db->insert('mc_fiscal_receipt', ['id' => 20, 'order_id' => 2, 'status' => 'done', 'receipt_url' => 'https://receipt.example.test/20']);
        $this->db = $db;

        $unused = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $controller = new PrroAdminController(
            new AdminContextResolver($db),
            $unused(PrroSettings::class),
            $unused(FiscalizationService::class),
            $db,
            new NullLogger(),
            new NotificationOutbox($db, new PublicIdFactory(), new DeferredWorkSignal()),
        );
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $this->session = new Session(new MockArraySessionStorage());
        $stack = new RequestStack();
        $current = Request::create('/admin/system/prro');
        $current->setSession($this->session);
        $stack->push($current);
        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('request_stack', $stack);
        $controller->setContainer($container);

        return $controller;
    }

    private function send(PrroAdminController $controller, int $receiptId, int $storeId): void
    {
        $request = Request::create('/admin/system/prro/receipt/' . $receiptId . '/send', 'POST', ['_csrf_token' => 'ok']);
        $request->setSession($this->session);
        $this->session->set('admin_context.store_id', $storeId);
        $controller->send($receiptId, $request);
    }

    public function testAReceiptOfAnotherStoreIsNotSentAndItsEmailIsNotShown(): void
    {
        $controller = $this->controller();
        $this->send($controller, 20, 1);

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_notification_outbox'), 'nothing is e-mailed for a receipt of store 2');
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayNotHasKey('success', $flashes);
        self::assertNotEmpty($flashes['error'] ?? []);
        self::assertStringNotContainsString('foreign@example.test', json_encode($flashes, JSON_THROW_ON_ERROR));
    }

    public function testAReceiptOfTheOwnStoreIsStillSent(): void
    {
        $controller = $this->controller();
        $this->send($controller, 10, 1);

        self::assertSame(['own@example.test'], array_map('strval', $this->db->fetchFirstColumn('SELECT recipient FROM mc_notification_outbox')));
        self::assertNotEmpty($this->session->getFlashBag()->get('success'));
    }
}
