<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Modules\Catalog\Application\SkuGenerator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SkuGeneratorTest extends TestCase
{
    public function testNextCodeFollowsTheHighestUsedNumber(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_product_variant (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT)');
        foreach (['GTR-001', 'GTR-007', 'GTR-abc', 'OTHER-100'] as $sku) {
            $db->insert('mc_product_variant', ['sku' => $sku]);
        }
        $generator = new SkuGenerator($db, new SystemSettingStore($db));
        self::assertSame('GTR-008', $generator->next('GTR-###'));
        self::assertSame('NEW-0001', $generator->next('NEW-####'));
        self::assertSame('', $generator->next(''));
        self::assertSame('GTR-###', SkuGenerator::normalize(' GTR-### <> '));
    }
}
