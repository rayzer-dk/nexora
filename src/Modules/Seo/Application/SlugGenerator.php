<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Seo\Domain\SlugMode;
use InvalidArgumentException;
use Transliterator;

final readonly class SlugGenerator
{
    public function __construct(private UkrainianTransliterator $ukrainian = new UkrainianTransliterator())
    {
    }

    public function generate(string $label, string $locale = 'uk-UA', SlugMode $mode = SlugMode::TransliterateAscii): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.72dc58047e35'));
        }

        $language = strtolower(explode('-', $locale, 2)[0]);
        $value = $language === 'uk' ? $this->ukrainian->transliterate($label) : $this->latinize($label);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return $this->finish($value);
    }

    public function normalizeManual(string $slug, string $locale = 'uk-UA', SlugMode $mode = SlugMode::TransliterateAscii): string
    {
        $slug = trim($slug);
        $slug = preg_replace('/\.(?:html?|php)$/i', '', trim($slug, '/')) ?? $slug;
        return $this->generate(str_replace(['/', '_'], [' ', '-'], $slug), $locale, $mode);
    }

    private function latinize(string $value): string
    {
        if (class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                return $transliterator->transliterate($value);
            }
        }

        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted === false ? '' : $converted;
    }

    private function finish(string $value): string
    {
        $value = trim(preg_replace('/-+/', '-', $value) ?? '', '-');
        if ($value === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bafe77bd1147'));
        }

        if (strlen($value) > 180) {
            $value = rtrim(substr($value, 0, 180), '-');
        }

        return $value;
    }
}
