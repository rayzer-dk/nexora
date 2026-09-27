<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Measurement;

use InvalidArgumentException;

/**
 * Fixed-point quantity value matching DECIMAL(18,6) in the database.
 * Avoids floating-point rounding in cart, pricing and inventory rules.
 */
final readonly class Quantity
{
    public const SCALE = 6;
    public const FACTOR = 1_000_000;

    public function __construct(public int $micros)
    {
        if ($micros < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.49e3373421ba'));
        }
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5cba8d23e29d'));
        }

        $whole = (int) $m[1];
        $fraction = str_pad($m[2] ?? '', self::SCALE, '0');
        $micros = $whole * self::FACTOR + (int) $fraction;

        return new self($micros);
    }

    public static function fromMicros(int $micros): self
    {
        return new self($micros);
    }

    public function isPositive(): bool
    {
        return $this->micros > 0;
    }

    public function toDatabase(): string
    {
        $whole = intdiv($this->micros, self::FACTOR);
        $fraction = $this->micros % self::FACTOR;

        return sprintf('%d.%06d', $whole, $fraction);
    }

    public function toHuman(int $maxDecimals = self::SCALE): string
    {
        $maxDecimals = max(0, min(self::SCALE, $maxDecimals));
        $value = $this->toDatabase();
        if ($maxDecimals < self::SCALE) {
            [$whole, $fraction] = explode('.', $value, 2);
            $value = $whole . ($maxDecimals > 0 ? '.' . substr($fraction, 0, $maxDecimals) : '');
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
