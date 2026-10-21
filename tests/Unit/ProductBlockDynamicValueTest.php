<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ProductBlockDynamicValueTest extends TestCase
{
    private const PRODUCT = [
        'name' => 'Kettle <b>',
        'brand' => 'Acme',
        'sku' => 'K-1',
        'gtin' => '4006381333931',
        'mpn' => 'MPN-7',
        'price' => '1 299 ₴',
        'availability_label' => 'In stock',
    ];

    private function render(string $source, bool $cart): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/themes/default/templates', 'storefront');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('site_capabilities', static fn (): array => ['features' => ['cart' => $cart]]));

        return $twig->createTemplate("{% import '@storefront/product/blocks/_dynamic_value.html.twig' as dyn %}{{ dyn.value(block, product) }}")
            ->render(['block' => ['settings' => ['data_source' => $source]], 'product' => self::PRODUCT]);
    }

    /** @return iterable<string,array{string,string}> */
    public static function sources(): iterable
    {
        yield 'name is escaped' => ['product.name', 'Kettle &lt;b&gt;'];
        yield 'brand' => ['product.brand', 'Acme'];
        yield 'sku' => ['product.sku', 'K-1'];
        yield 'gtin' => ['product.gtin', '4006381333931'];
        yield 'mpn' => ['product.mpn', 'MPN-7'];
        yield 'price' => ['product.price', '1 299 ₴'];
        yield 'no source' => ['', ''];
        yield 'unknown source' => ['product.secret', ''];
    }

    #[DataProvider('sources')]
    public function testEachSourceGivesItsOwnValue(string $source, string $expected): void
    {
        self::assertSame($expected, $this->render($source, true));
    }

    public function testAvailabilityIsShownOnlyWhileTheCartIsOn(): void
    {
        self::assertSame('In stock', $this->render('product.availability', true));
        self::assertSame('', $this->render('product.availability', false));
    }
}
