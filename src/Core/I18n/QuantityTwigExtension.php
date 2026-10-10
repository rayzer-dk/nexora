<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Quantities are stored with six decimals ("2.000000"); people read them as "2" or "1.5". */
final class QuantityTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('qty', $this->format(...))];
    }

    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $text = is_float($value) ? rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') : (string) $value;
        if (preg_match('/^-?\d+\.\d+$/', $text) === 1) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-' ? '0' : $text;
    }
}
