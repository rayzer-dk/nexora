<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency)
    {
        if ($minor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.45058bb0b102'));
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.974d7b308d39'));
        }
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cbc091f2e3ef'));
        }
        return new self($this->minor + $other->minor, $this->currency);
    }
}
