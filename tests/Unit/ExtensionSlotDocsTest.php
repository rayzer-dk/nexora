<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionPoint;
use PHPUnit\Framework\TestCase;

final class ExtensionSlotDocsTest extends TestCase
{
    public function testEverySlotIsDocumentedAndNothingElseIs(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/EXTENSION_SLOTS.md');
        preg_match_all('/^\| `([a-z_.]+)` \|/m', $doc, $matches);

        $documented = $matches[1];
        $actual = ExtensionPoint::values();
        sort($documented);
        sort($actual);

        self::assertSame($actual, $documented, 'docs/EXTENSION_SLOTS.md and ExtensionPoint must list the same slots.');
    }
}
