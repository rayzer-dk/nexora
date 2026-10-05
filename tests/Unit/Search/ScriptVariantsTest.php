<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Search;

use Commerce\Modules\Search\Application\SqlSearchIndex;
use PHPUnit\Framework\TestCase;

final class ScriptVariantsTest extends TestCase
{
    public function testWrongKeyboardLayoutIsSwappedBack(): void
    {
        self::assertContains('samsung', SqlSearchIndex::scriptVariants('ыфьыгтп'));
        self::assertContains('ыфьыгтп', SqlSearchIndex::scriptVariants('samsung'));
    }

    public function testOtherAlphabetSpellingIsOffered(): void
    {
        self::assertContains('navushnyky', SqlSearchIndex::scriptVariants('навушники'));
        self::assertContains('самсунг', SqlSearchIndex::scriptVariants('samsung'));
    }

    public function testMixedAndNumericWordsAreLeftAlone(): void
    {
        self::assertSame([], SqlSearchIndex::scriptVariants('galaxy s24'));
        self::assertSame([], SqlSearchIndex::scriptVariants('s24'));
    }
}
