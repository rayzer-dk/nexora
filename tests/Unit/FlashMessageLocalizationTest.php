<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Flash messages and their translation keys: no hard-coded literals, and every referenced key exists in both languages. */
final class FlashMessageLocalizationTest extends TestCase
{
    public function testNoFlashMessageIsALiteralString(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match_all('/addFlash\(\s*[^,]+,\s*(["\'])/', $code, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as $hit) {
                    $offenders[] = substr($file, strlen($this->root()) + 1) . ':' . (substr_count(substr($code, 0, $hit[1]), "\n") + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'Flash messages must come from translations (CanonicalUiText::get), not string literals.');
    }

    public function testFlashTranslationKeysExistInBothLanguages(): void
    {
        $known = [];
        foreach (['uk-UA', 'en-US'] as $locale) {
            $known[$locale] = [];
            foreach (glob($this->root() . '/resources/translations/' . $locale . '/*.php') ?: [] as $file) {
                preg_match_all("/^\\s*'([^']+)'\\s*=>/m", (string) file_get_contents($file), $keys);
                $known[$locale] = array_merge($known[$locale], array_flip($keys[1]));
            }
        }
        $missing = [];
        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match_all('/addFlash\([^;]*?(?:CanonicalUiText::get|->t)\(\'([^\']+)\'(?!\s*\.)\s*[,)]/s', $code, $m) > 0) {
                foreach ($m[1] as $key) {
                    foreach ($known as $locale => $set) {
                        if (!isset($set[$key])) {
                            $missing[] = $locale . ' ' . $key;
                        }
                    }
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)), 'Flash keys without a translation.');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
