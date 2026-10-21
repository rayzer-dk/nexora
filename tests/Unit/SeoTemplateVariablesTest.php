<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Seo\Application\SeoTemplateService;
use PHPUnit\Framework\TestCase;

final class SeoTemplateVariablesTest extends TestCase
{
    public function testArticleAndPageTemplatesOfferOnlyNameAndStore(): void
    {
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('article'));
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('page'));
        self::assertSame(['name', 'store'], SeoTemplateService::variablesFor('category'));
        self::assertSame(['name', 'store', 'price', 'brand', 'category', 'sku'], SeoTemplateService::variablesFor('product'));
    }
}
