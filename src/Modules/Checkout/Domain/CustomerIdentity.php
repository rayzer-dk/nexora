<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

use InvalidArgumentException;

final readonly class CustomerIdentity
{
    public function __construct(
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $customerId = null,
        public ?string $identityProvider = null,
        public ?string $externalSubject = null,
    ) {
        foreach (['name' => $this->name, 'phone' => $this->phone, 'email' => $this->email] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.7535d0719793'), ucfirst($field)));
            }
        }
    }

    public function satisfies(CheckoutRequirements $requirements): bool
    {
        if ($requirements->requiresName && !$this->hasValue($this->name)) {
            return false;
        }
        if ($requirements->requiresPhone && !$this->hasValue($this->phone)) {
            return false;
        }
        if ($requirements->requiresEmail && !$this->hasValue($this->email)) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    public function missingFields(CheckoutRequirements $requirements): array
    {
        $missing = [];
        if ($requirements->requiresName && !$this->hasValue($this->name)) {
            $missing[] = 'name';
        }
        if ($requirements->requiresPhone && !$this->hasValue($this->phone)) {
            $missing[] = 'phone';
        }
        if ($requirements->requiresEmail && !$this->hasValue($this->email)) {
            $missing[] = 'email';
        }

        return $missing;
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
