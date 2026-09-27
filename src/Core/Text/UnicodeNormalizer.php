<?php

declare(strict_types=1);

namespace Commerce\Core\Text;

use InvalidArgumentException;
use Normalizer;

final class UnicodeNormalizer
{
    public function normalize(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e1cc6fa06eda'));
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.928bca9f5ec7'));
        }

        return $normalized;
    }
}
