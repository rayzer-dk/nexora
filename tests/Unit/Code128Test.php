<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\OrderDocument\Application\Code128;
use PHPUnit\Framework\TestCase;

final class Code128Test extends TestCase
{
    public function testTheCheckSymbolMatchesTheKnownExample(): void
    {
        // "PJJ123C" in set B: start 104, then P J J 1 2 3 C, check symbol 55 (104 + 48 + 2*42 + 3*42 + 4*17 + 5*18 + 6*19 + 7*35 = 879, 879 mod 103 = 55), stop 106.
        $symbols = Code128::symbols('PJJ123C');
        self::assertSame([104, 48, 42, 42, 17, 18, 19, 35, 55, 106], $symbols);
    }

    public function testTheSvgIsMadeOfBarsAndEscapesItsLabel(): void
    {
        $svg = Code128::svg('2045<0>"1');
        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringNotContainsString('<0>', $svg);
        self::assertGreaterThan(20, substr_count($svg, '<rect '));
    }

    public function testNonPrintableInputIsDroppedAndEmptyBecomesADash(): void
    {
        self::assertSame('AB', Code128::clean("A\x00\x7fB\u{0416}"));
        self::assertSame('-', Code128::clean(''));
        self::assertSame(40, strlen(Code128::clean(str_repeat('9', 90))));
    }
}
