<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\Payment\Domain\PaymentMethod;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $providers = [];

    public function __construct(iterable $providers, private readonly ?\Commerce\Modules\Checkout\Application\CheckoutMethodSettings $custom = null)
    {
        foreach ($providers as $provider) {
            if ($provider instanceof PaymentProviderInterface) {
                $this->register($provider);
            }
        }
    }

    public function register(PaymentProviderInterface $provider): void
    {
        $code = trim($provider->method()->code);
        if ($code === '' || isset($this->providers[$code])) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.payment_provider_duplicate') . $code);
        }
        $this->providers[$code] = $provider;
    }

    /** @return list<PaymentMethod> */
    public function enabledMethods(): array
    {
        $methods = [];
        foreach ($this->providers as $provider) {
            if ($provider->enabled()) $methods[] = $provider->method();
        }
        foreach ($this->custom?->customCodes('payment') ?? [] as $code) {
            $methods[] = $this->customMethod($code);
        }
        return $methods;
    }

    public function require(string $code): PaymentProviderInterface
    {
        $provider = $this->providers[$code] ?? null;
        if ($provider === null && $this->custom !== null && in_array($code, $this->custom->customCodes('payment'), true)) {
            $method = $this->customMethod($code);
            return new class($method) implements PaymentProviderInterface {
                public function __construct(private readonly PaymentMethod $method) {}
                public function method(): PaymentMethod { return $this->method; }
                public function enabled(): bool { return true; }
            };
        }
        if (!$provider || !$provider->enabled()) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentproviderregistry.obranyi_sposib_oplaty_nedostupnyi'));
        return $provider;
    }

    /** Offline method added by the shop owner: the order waits for payment until it is marked as paid in the admin. */
    private function customMethod(string $code): PaymentMethod
    {
        return new PaymentMethod($code, $this->custom?->customName($code, 'uk') ?? $code, false, false);
    }
}
