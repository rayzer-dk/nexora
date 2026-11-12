<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionPackageValidator;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Core\Extension\TrustedExtensionContext;
use Commerce\Core\Extension\TrustedExtensionRuntimeRegistry;
use Commerce\Core\Extension\TrustedExtensionSignatureVerifier;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Security\OutboundUrlPolicy;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ExtensionScheduledTaskTest extends TestCase
{
    private ?string $archive = null;

    protected function tearDown(): void
    {
        if ($this->archive !== null) {
            @unlink($this->archive);
        }
    }

    public function testDeclaredTaskIsRegisteredUnderTheExtensionNamespace(): void
    {
        $runtime = new TrustedExtensionRuntimeRegistry();
        $this->context($runtime)->scheduledTask('sync', static fn (): string => 'synced');

        $tasks = $runtime->tasks();
        self::assertSame(['extension.vendor_crm.sync'], array_keys($tasks));
        self::assertSame(3600, $tasks['extension.vendor_crm.sync']['interval']);
        self::assertSame('synced', ($tasks['extension.vendor_crm.sync']['handler'])());
    }

    public function testUndeclaredTaskIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->context(new TrustedExtensionRuntimeRegistry())->scheduledTask('other', static fn (): null => null);
    }

    public function testTheSameTaskCannotBeRegisteredTwice(): void
    {
        $runtime = new TrustedExtensionRuntimeRegistry();
        $context = $this->context($runtime);
        $context->scheduledTask('sync', static fn (): null => null);
        $this->expectException(LogicException::class);
        $context->scheduledTask('sync', static fn (): null => null);
    }

    public function testOnlyTrustedExtensionsMayDeclareScheduledTasks(): void
    {
        $validator = new ExtensionPackageValidator(new ExtensionSettingsSchemaValidator(), new OutboundUrlPolicy(), new TrustedExtensionSignatureVerifier(sys_get_temp_dir()));
        $this->expectException(RuntimeException::class);
        $validator->inspect($this->declarativePackageWithTask(), false);
    }

    private function context(TrustedExtensionRuntimeRegistry $runtime): TrustedExtensionContext
    {
        return new TrustedExtensionContext(
            'vendor.crm',
            '1.0.0',
            sys_get_temp_dir(),
            $runtime,
            new \Commerce\Core\Extension\ExtensionProviderSinks([
                new \Commerce\Modules\Payment\Application\PaymentProviderExtensionSink(new \Commerce\Modules\Payment\Application\PaymentProviderRegistry([])),
                new \Commerce\Modules\Shipping\Application\DeliveryProviderExtensionSink(new \Commerce\Modules\Shipping\Application\DeliveryProviderRegistry([])),
                new \Commerce\Modules\ProductPage\Application\ProductBlockExtensionSink(new \Commerce\Modules\ProductPage\Application\ProductBlockRegistry([])),
                new \Commerce\Modules\Ai\Application\AiProviderExtensionSink(new \Commerce\Modules\Ai\Application\AiProviderRegistry([])),
                new \Commerce\Modules\Ai\Application\TranslationProviderExtensionSink(new \Commerce\Modules\Ai\Application\TranslationProviderRegistry()),
            ]),
            new \Commerce\Core\Extension\ExtensionServiceRegistry(),
            [],
            [],
            [],
            ['sync' => ['interval' => 3600, 'label' => 'Sync', 'description' => '']],
        );
    }

    private function declarativePackageWithTask(): string
    {
        $manifest = [
            'code' => 'acme.test_theme', 'name' => 'Test', 'version' => '1.0.0', 'type' => 'theme', 'core' => '^3.4.8 || ^4.0',
            'extension_api' => PlatformVersion::EXTENSION_API, 'permissions' => [], 'execution' => 'declarative',
            'scheduled_tasks' => [['code' => 'sync', 'interval' => 3600, 'label' => 'Sync']],
        ];
        $path = tempnam(sys_get_temp_dir(), 'nexora-ext-');
        self::assertNotFalse($path);
        $this->archive = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->addFromString('theme.css', 'a{color:red}');
        $zip->close();

        return $path;
    }
}
