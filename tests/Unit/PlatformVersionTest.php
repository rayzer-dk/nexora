<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Platform\PlatformVersion;
use PHPUnit\Framework\TestCase;

final class PlatformVersionTest extends TestCase
{
    public function testVersionContractIsCoherent(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', PlatformVersion::VERSION);
        self::assertSame('2.0', PlatformVersion::EXTENSION_API);
        self::assertGreaterThanOrEqual(45, PlatformVersion::DATABASE_SCHEMA);
        self::assertSame('production', PlatformVersion::CHANNEL);
    }
}
