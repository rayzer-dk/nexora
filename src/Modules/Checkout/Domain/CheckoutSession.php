<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

use DomainException;

final class CheckoutSession
{
    private CheckoutStatus $status = CheckoutStatus::Started;
    private ?CustomerIdentity $identity = null;
    private ?FulfillmentDestination $destination = null;
    private ?string $paymentMethod = null;

    public function __construct(
        private readonly CheckoutRequirements $requirements = new CheckoutRequirements(true, true, true, false),
    ) {
    }

    public function identify(CustomerIdentity $identity): void
    {
        $this->assertNotCompleted();

        if (!$identity->satisfies($this->requirements)) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f502a7c18948') . implode(', ', $identity->missingFields($this->requirements)) . '.');
        }

        $this->identity = $identity;
        $this->status = $this->requirements->requiresFulfillment
            ? CheckoutStatus::IdentityReady
            : CheckoutStatus::FulfillmentReady;
    }

    public function chooseFulfillment(FulfillmentDestination $destination): void
    {
        $this->assertNotCompleted();
        if (!$this->requirements->requiresFulfillment) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.717f9079c544'));
        }
        if ($this->identity === null) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.42449c2a59a1'));
        }

        $this->destination = $destination;
        $this->status = CheckoutStatus::FulfillmentReady;
    }


    public function choosePayment(string $paymentMethod): void
    {
        $this->assertNotCompleted();
        if ($this->identity === null) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2fa0461e75a9'));
        }
        if ($this->requirements->requiresFulfillment && $this->destination === null) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8527be82b6ae'));
        }
        if (trim($paymentMethod) === '') {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fd38499d9df0'));
        }

        $this->paymentMethod = $paymentMethod;
        $this->status = CheckoutStatus::PaymentReady;
    }

    public function complete(): void
    {
        if ($this->identity === null || $this->paymentMethod === null) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9123f0d0a335'));
        }
        if ($this->requirements->requiresFulfillment && $this->destination === null) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b399f3522359'));
        }

        $this->status = CheckoutStatus::Completed;
    }

    public function status(): CheckoutStatus
    {
        return $this->status;
    }

    public function requirements(): CheckoutRequirements
    {
        return $this->requirements;
    }

    public function identity(): ?CustomerIdentity
    {
        return $this->identity;
    }

    public function destination(): ?FulfillmentDestination
    {
        return $this->destination;
    }

    public function paymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    private function assertNotCompleted(): void
    {
        if ($this->status === CheckoutStatus::Completed) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a69d67af37c4'));
        }
    }
}
