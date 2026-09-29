<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Twig\ColorContrastTwigExtension;
use PHPUnit\Framework\TestCase;

final class ColorContrastTwigExtensionTest extends TestCase
{
    public function testPicksReadableTextColour(): void
    {
        $x = new ColorContrastTwigExtension();
        self::assertSame('#ffffff', $x->contrastColor('#0b63f6'));
        self::assertSame('#ffffff', $x->contrastColor('#000'));
        self::assertSame('#0b1220', $x->contrastColor('#ffd400'));
        self::assertSame('#0b1220', $x->contrastColor('#ffffff'));
        self::assertSame('#ffffff', $x->contrastColor('not-a-colour'));
        self::assertSame('#ffffff', $x->contrastColor(null));
    }
}
