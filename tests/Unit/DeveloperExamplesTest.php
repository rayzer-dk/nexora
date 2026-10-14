<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\Extension\Console\ExtensionKeygenCommand;
use Commerce\Core\Extension\Console\ExtensionPackCommand;
use Commerce\Core\Extension\Console\ExtensionSignCommand;
use Commerce\Core\Extension\Console\ExtensionTestCommand;
use Commerce\Core\Extension\Console\ExtensionValidateCommand;
use Commerce\Core\Extension\ExtensionPackageValidator;
use Commerce\Core\Extension\ExtensionServiceRegistry;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Core\Extension\TrustedExtensionContext;
use Commerce\Core\Extension\TrustedExtensionRuntimeRegistry;
use Commerce\Core\Extension\TrustedExtensionSignatureVerifier;
use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Admin\Help\HelpCatalog;
use Commerce\Modules\Ai\Application\AiProviderRegistry;
use Commerce\Modules\Ai\Application\TranslationProviderRegistry;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\ProductPage\Application\ProductBlockRegistry;
use Commerce\Modules\Shipping\Application\DeliveryProviderRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four modules of docs/examples/extensions and the developer guide are promises to third-party developers:
 * every example must still pack, validate and work through the public extension contracts of this release.
 */
final class DeveloperExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../docs/examples/extensions';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nexora-dev-examples-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    /** @return iterable<string,array{string}> */
    public static function declarativeExamples(): iterable
    {
        yield 'settings' => ['hello-settings'];
        yield 'product block' => ['product-badge'];
        yield 'remote integration' => ['remote-crm'];
    }

    #[DataProvider('declarativeExamples')]
    public function testDeclarativeExamplePacksValidatesAndPassesTheSdkChecks(string $example): void
    {
        $zip = $this->pack($example);

        $validate = new CommandTester(new ExtensionValidateCommand($this->validator($this->tmp)));
        self::assertSame(0, $validate->execute(['archive' => $zip]), $validate->getDisplay());
        self::assertStringContainsString('VALID', $validate->getDisplay());

        $sdk = new CommandTester(new ExtensionTestCommand($this->validator($this->tmp)));
        self::assertSame(0, $sdk->execute(['archive' => $zip]), $sdk->getDisplay());
        self::assertStringNotContainsString('FAIL', $sdk->getDisplay());
    }

    public function testTrustedExampleValidatesOnlyAfterItIsSignedWithTheRegisteredPublisherKey(): void
    {
        $zip = $this->pack('trusted-order-hook');
        $project = $this->tmp . '/project';
        mkdir($project . '/config/extensions', 0700, true);
        mkdir($project . '/var/config', 0700, true);
        file_put_contents($project . '/config/extensions/trusted-publishers.json', '{"schema_version": 2, "publishers": {}}');

        $keyFile = $this->tmp . '/example.docs.2026.key';
        $keygen = new CommandTester(new ExtensionKeygenCommand());
        self::assertSame(0, $keygen->execute(['key-id' => 'example.docs.2026', '--out' => $keyFile]));
        self::assertSame(1, preg_match('/(\{"schema_version".*\})/', $keygen->getDisplay(), $publisherLine));
        file_put_contents($project . '/var/config/trusted-publishers.json', $publisherLine[1]);

        $validate = new CommandTester(new ExtensionValidateCommand($this->validator($project)));
        self::assertSame(1, $validate->execute(['archive' => $zip]), 'An unsigned trusted package must be rejected.');

        $sign = new CommandTester(new ExtensionSignCommand());
        self::assertSame(0, $sign->execute(['archive' => $zip, 'secret-key' => $keyFile]), $sign->getDisplay());

        self::assertSame(0, $validate->execute(['archive' => $zip]), $validate->getDisplay());
        self::assertStringContainsString('Execution: trusted_release', $validate->getDisplay());
    }

    public function testTrustedExampleLogsOrdersRunsItsTaskAndServesItsAdminPage(): void
    {
        $dir = self::EXAMPLES . '/trusted-order-hook';
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        require_once $dir . '/src/OrderLog.php';
        require_once $dir . '/src/Entrypoint.php';
        $installPath = $this->tmp . '/var/extensions/installed/example.order_hook/1.0.0';
        mkdir($installPath, 0700, true);

        $runtime = new TrustedExtensionRuntimeRegistry();
        $tasks = [];
        foreach ($manifest['scheduled_tasks'] as $task) {
            $tasks[$task['code']] = ['interval' => $task['interval'], 'label' => $task['label'], 'description' => $task['description']];
        }
        $entrypoint = $manifest['entrypoint'];
        (new $entrypoint())->boot(new TrustedExtensionContext(
            $manifest['code'],
            $manifest['version'],
            $installPath,
            $runtime,
            new PaymentProviderRegistry([]),
            new DeliveryProviderRegistry([]),
            new ProductBlockRegistry([]),
            new AiProviderRegistry([]),
            new TranslationProviderRegistry(),
            new ExtensionServiceRegistry(),
            array_column($manifest['routes'], 'name'),
            $manifest['events'],
            $manifest['capabilities'],
            $tasks,
        ));

        $order = new StoredDomainEvent(1, 'a96fe60b-0a19-4a20-8ed6-da1b8ecfe1f5', 'commerce.order.placed', 1, 'order', 'NX-1001',
            ['order_number' => 'NX-1001', 'store_id' => 1, 'market_id' => 1, 'currency' => 'UAH', 'total_minor' => 123450], [], new DateTimeImmutable());
        $runtime->dispatchEvent($order);
        $runtime->dispatchEvent($order); // the outbox may deliver the same event twice

        $digest = $runtime->tasks()['extension.example_order_hook.digest'] ?? null;
        self::assertIsArray($digest);
        self::assertSame(3600, $digest['interval']);
        self::assertSame('New orders: 1 (UAH 1234.50)', ($digest['handler'])());
        self::assertSame('New orders: 0', ($digest['handler'])());

        $page = $runtime->routeHandler('extension.example_order_hook.log');
        self::assertIsCallable($page);
        $response = $page(Request::create('/admin/extensions/example_order_hook/log'), $manifest['routes'][0]);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('NX-1001', (string) $response->getContent());
        self::assertStringContainsString('UAH 1234.50', (string) $response->getContent());
        self::assertCount(1, file($this->tmp . '/var/extension-data/example.order_hook/orders.jsonl') ?: []);
    }

    public function testWebhookReceiverAcceptsOnlyFreshSignedDeliveriesAndEachEventOnce(): void
    {
        require_once self::EXAMPLES . '/remote-crm-receiver/receiver.php';
        $secret = 'test-secret-' . bin2hex(random_bytes(8));
        $body = json_encode([
            'id' => '0f8e9a52-3c4b-4d3e-9a37-8a1d2c3b4e5f', 'type' => 'commerce.order.placed', 'version' => 1,
            'aggregate' => ['type' => 'order', 'id' => 'NX-1001'],
            'data' => ['order_number' => 'NX-1001', 'store_id' => 1, 'market_id' => 1, 'currency' => 'UAH', 'total_minor' => 123450],
            'occurred_at' => '2026-10-14T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
        $now = 1_800_000_000;
        // Signed the way ApiWebhookDeliveryWorker signs every delivery.
        $headers = ['X-Nexora-Timestamp' => (string) $now, 'X-Nexora-Signature' => 'sha256=' . hash_hmac('sha256', $now . '.' . $body, $secret), 'X-Nexora-Event' => 'commerce.order.placed'];
        $log = $this->tmp . '/orders.jsonl';

        self::assertSame([401, 'bad signature'], \handleNexoraWebhook($secret, $headers, str_replace('123450', '1', $body), $log, $now));
        self::assertSame([401, 'bad signature'], \handleNexoraWebhook($secret, $headers, $body, $log, $now + 301));
        self::assertSame([200, 'ok'], \handleNexoraWebhook($secret, $headers, $body, $log, $now + 5));
        self::assertSame([200, 'duplicate'], \handleNexoraWebhook($secret, $headers, $body, $log, $now + 6));

        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $lines);
        self::assertSame(['order_number' => 'NX-1001', 'currency' => 'UAH', 'total_minor' => 123450, 'event_id' => '0f8e9a52-3c4b-4d3e-9a37-8a1d2c3b4e5f'], json_decode($lines[0], true));
    }

    public function testGuideIsServedInDeveloperHelpAndNothingItPointsToIsMissing(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = new HelpCatalog($root);
        $doc = $catalog->find('developer-modules');
        self::assertNotNull($doc);
        self::assertContains(['slug' => 'developer-modules', 'title' => $doc['title'], 'group' => 'developers'], $catalog->all());

        foreach (['docs/DEVELOPER_MODULES_UK.md', 'docs/examples/extensions/README.md'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            preg_match_all('/\]\(([^)\s]+)\)/u', $source, $links);
            foreach ($links[1] as $link) {
                if (str_starts_with($link, 'https://')) {
                    continue;
                }
                self::assertNotNull($catalog->urlForFile(basename($link)), $file . ' links to a document the help does not serve: ' . $link);
                self::assertFileExists(dirname($root . '/' . $file) . '/' . $link, $file . ' has a broken relative link: ' . $link);
            }
            preg_match_all('/`((?:docs|src|themes|config|resources|tests)\/[A-Za-z0-9_.\/-]+)`/u', $source, $paths);
            foreach (array_unique($paths[1]) as $path) {
                self::assertFileExists($root . '/' . rtrim($path, '/'), $file . ' mentions a path that does not exist: ' . $path);
            }
        }
    }

    private function validator(string $projectDir): ExtensionPackageValidator
    {
        return new ExtensionPackageValidator(new ExtensionSettingsSchemaValidator(), new OutboundUrlPolicy(), new TrustedExtensionSignatureVerifier($projectDir));
    }

    private function pack(string $example): string
    {
        $zip = $this->tmp . '/' . $example . '.zip';
        $pack = new CommandTester(new ExtensionPackCommand($this->validator($this->tmp)));
        self::assertSame(0, $pack->execute(['directory' => self::EXAMPLES . '/' . $example, '--output' => $zip]), $pack->getDisplay());
        self::assertFileExists($zip);

        return $zip;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
