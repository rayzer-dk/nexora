<?php

declare(strict_types=1);

namespace Commerce\Core\Deployment;

use RuntimeException;

final readonly class FailureInjector
{
    public function __construct(private ?FailurePoint $point = null)
    {
    }

    public function hit(FailurePoint $point): void
    {
        if ($this->point === $point) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a97b54cfebf6') . $point->value . '.');
        }
    }
}
