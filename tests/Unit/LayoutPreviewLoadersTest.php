<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Appearance\Builder\LayoutPreviewToken;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Appearance\Builder\LayoutSchemaValidator;
use Commerce\Modules\Appearance\Builder\SafeLayoutProvider;
use Commerce\Modules\Checkout\Application\CheckoutLayoutService;
use Commerce\Modules\ProductPage\Application\BuilderProductBlockProvider;
use Commerce\Modules\ProductPage\Application\BuiltinProductBlockProvider;
use Commerce\Modules\ProductPage\Application\ProductBlockRegistry;
use Commerce\Modules\ProductPage\Application\ProductPageLayoutLoader;
use Commerce\Modules\Storefront\Application\CartLayoutService;
use Commerce\Modules\Storefront\Application\CategoryLayoutService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The builder preview (signed token) shows the draft of category, cart, product and checkout; a shopper sees only the published layout. */
final class LayoutPreviewLoadersTest extends TestCase
{
    private const STORE = 3;

    private Connection $db;

    private RequestStack $requests;

    private LayoutPreviewToken $token;

    private ExtensionContributionRegistry $extensions;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_layout_revision (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INT, layout_type TEXT, status TEXT, schema_version INT, payload TEXT, created_by INT NULL, created_at TEXT, published_at TEXT NULL)');
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY, code TEXT, version TEXT, install_path TEXT, manifest_json TEXT, status TEXT)');
        $manifest = ['code' => 'acme.promo', 'blocks' => [
            ['id' => 'extension.acme_promo.category_card', 'surface' => 'category', 'label_key' => 'x'],
            ['id' => 'extension.acme_promo.cart_card', 'surface' => 'cart', 'label_key' => 'x'],
        ]];
        $this->db->insert('mc_extension_installation', ['id' => 1, 'code' => 'acme.promo', 'version' => '1.0.0', 'install_path' => sys_get_temp_dir(), 'manifest_json' => json_encode($manifest), 'status' => 'active']);
        $this->requests = new RequestStack();
        $this->token = new LayoutPreviewToken('test-secret');
        $this->extensions = new ExtensionContributionRegistry($this->db);
    }

    /** @return iterable<string,array{string,list<array<string,mixed>>,list<array<string,mixed>>,callable(self):mixed,mixed,mixed}> */
    public static function pages(): iterable
    {
        yield 'category' => ['category',
            [self::block('category_toolbar', false)], [self::block('category_recommended', false)],
            static fn(self $t): array => array_intersect_key($t->category()['show'], ['toolbar' => 0, 'recommended' => 0]),
            ['toolbar' => false, 'recommended' => true], ['toolbar' => true, 'recommended' => false],
        ];
        yield 'cart' => ['cart',
            [self::block('cart_saved', false)], [self::block('cart_recent', false)],
            static fn(self $t): array => $t->cart()['below'],
            ['recent'], ['saved'],
        ];
        yield 'checkout' => ['checkout',
            [self::block('checkout_comment')], [self::block('checkout_coupon')],
            static fn(self $t): array => array_intersect_key($t->checkout()['requirements'], ['show_comment' => 0, 'show_coupon' => 0]),
            ['show_comment' => true, 'show_coupon' => false], ['show_comment' => false, 'show_coupon' => true],
        ];
        yield 'product' => ['product',
            [self::block('rich_text', true, ['title' => 'Опубліковано'])], [self::block('rich_text', true, ['title' => 'Чернетка'])],
            static fn(self $t): array => array_map(static fn($b): string => (string) ($b->settings['title'] ?? ''), $t->product()->blocks),
            ['Опубліковано'], ['Чернетка'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $published
     * @param list<array<string,mixed>> $draft
     * @param callable(self):mixed $observe
     */
    #[DataProvider('pages')]
    public function testDraftOnlyUnderAValidPreviewToken(string $type, array $published, array $draft, callable $observe, mixed $shopperSees, mixed $previewSees): void
    {
        $store = $this->store();
        $store->publish(self::STORE, $type, ['schema_version' => 1, 'blocks' => $published]);
        $store->saveDraft(self::STORE, $type, ['schema_version' => 1, 'blocks' => $draft]);

        $this->visit('');
        self::assertSame($shopperSees, $observe($this), 'Without a token the shopper sees the published layout.');
        $this->visit($this->token->issue(self::STORE, $type));
        self::assertSame($previewSees, $observe($this), 'The preview token of this store and page type shows the draft.');
        $this->visit($this->token->issue(self::STORE + 1, $type));
        self::assertSame($shopperSees, $observe($this), 'A token of another store does not open the draft.');
        $this->visit($this->token->issue(self::STORE, $type === 'cart' ? 'category' : 'cart'));
        self::assertSame($shopperSees, $observe($this), 'A token of another page type does not open the draft.');
    }

    public function testModuleBlockKeepsItsPlaceAmongCategorySections(): void
    {
        $promo = ['id' => 'promo', 'component' => 'extension.acme_promo.category_card', 'enabled' => true, 'props' => ['title' => 'Знижки'], 'style' => [], 'visibility' => []];
        $off = ['id' => 'off', 'component' => 'extension.acme_promo.category_card', 'enabled' => false, 'props' => [], 'style' => [], 'visibility' => []];
        $wrongPage = ['id' => 'cartcard', 'component' => 'extension.acme_promo.cart_card', 'enabled' => true, 'props' => [], 'style' => [], 'visibility' => []];
        $this->store()->publish(self::STORE, 'category', ['schema_version' => 1, 'blocks' => [self::block('category_heading'), self::block('category_toolbar'), $promo, $off, self::block('category_grid'), $wrongPage]]);
        $this->visit('');

        $layout = $this->category();

        self::assertSame(['subcategories', 'toolbar', 'extension:promo', 'grid', 'recommended'], $layout['main']);
        self::assertSame(['extension:promo'], array_keys($layout['extensions']));
        self::assertSame('Знижки', $layout['extensions']['extension:promo']['props']['title']);
    }

    public function testModuleBlockKeepsItsPlaceAmongCartSections(): void
    {
        $promo = ['id' => 'promo', 'component' => 'extension.acme_promo.cart_card', 'enabled' => true, 'props' => [], 'style' => [], 'visibility' => []];
        $this->store()->publish(self::STORE, 'cart', ['schema_version' => 1, 'blocks' => [self::block('cart_heading'), self::block('cart_lines'), self::block('cart_saved'), $promo, self::block('cart_recent')]]);
        $this->visit('');

        $layout = $this->cart();

        self::assertSame(['saved', 'extension:promo', 'recent'], $layout['below']);
        self::assertSame(['extension:promo'], array_keys($layout['extensions']));
    }

    /** @return array<string,mixed> */
    public function category(): array
    {
        return (new CategoryLayoutService($this->store(), $this->extensions))->active(self::STORE);
    }

    /** @return array<string,mixed> */
    public function cart(): array
    {
        return (new CartLayoutService($this->store(), $this->extensions))->active(self::STORE);
    }

    /** @return array<string,mixed> */
    public function checkout(): array
    {
        return (new CheckoutLayoutService($this->store(), $this->extensions))->active(self::STORE);
    }

    public function product(): \Commerce\Modules\ProductPage\Domain\ProductPageLayout
    {
        $registry = new ProductBlockRegistry([new BuiltinProductBlockProvider(), new BuilderProductBlockProvider()]);

        return (new ProductPageLayoutLoader(dirname(__DIR__, 2) . '/config/product_page/default_layout.json', $registry, $this->store(), $this->extensions))->loadForStore(self::STORE);
    }

    private function store(): LayoutRevisionStore
    {
        return new LayoutRevisionStore($this->db, new LayoutSchemaValidator($this->extensions), new SafeLayoutProvider(), $this->token, $this->requests);
    }

    private function visit(string $token): void
    {
        while ($this->requests->pop() !== null) {
        }
        $this->requests->push(new Request($token === '' ? [] : ['_layout_preview' => $token]));
    }

    /** @param array<string,mixed> $props @return array<string,mixed> */
    private static function block(string $component, bool $enabled = true, array $props = []): array
    {
        return ['id' => $component, 'component' => $component, 'enabled' => $enabled, 'props' => $props, 'style' => [], 'visibility' => []];
    }
}
