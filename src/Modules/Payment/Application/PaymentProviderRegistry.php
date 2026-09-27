<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\Payment\Domain\PaymentMethod;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $providers = [];

    public function __construct(iterable $providers)
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
        return $methods;
    }

    public function require(string $code): PaymentProviderInterface
    {
        $provider = $this->providers[$code] ?? null;
        if (!$provider || !$provider->enabled()) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentproviderregistry.obranyi_sposib_oplaty_nedostupnyi'));
        return $provider;
    }
}
