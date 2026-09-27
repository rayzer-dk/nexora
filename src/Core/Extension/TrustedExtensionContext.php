<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Modules\Ai\Application\AiProviderRegistry;
use Commerce\Modules\Ai\Contract\TextGenerationProviderInterface;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\ProductPage\Application\ProductBlockRegistry;
use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;
use Commerce\Modules\Shipping\Application\DeliveryProviderRegistry;
use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;

final readonly class TrustedExtensionContext
{
    /** @param list<string> $declaredRoutes @param list<string> $declaredEvents @param list<string> $declaredCapabilities */
    public function __construct(
        public string $code,
        public string $version,
        public string $installPath,
        private TrustedExtensionRuntimeRegistry $runtime,
        private PaymentProviderRegistry $payments,
        private DeliveryProviderRegistry $shipping,
        private ProductBlockRegistry $productBlocks,
        private AiProviderRegistry $ai,
        private array $declaredRoutes = [],
        private array $declaredEvents = [],
        private array $declaredCapabilities = [],
    ) {
    }

    public function route(string $name, callable $handler): void
    {
        if (!in_array($name, $this->declaredRoutes, true)) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.route_undeclared') . $name);
        }
        $this->runtime->registerRoute($this->code, $name, $handler);
    }

    public function on(string $eventName, callable $handler): void
    {
        if (!in_array($eventName, $this->declaredEvents, true)) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.event_undeclared') . $eventName);
        }
        $this->runtime->registerEvent($this->code, $eventName, $handler);
    }

    public function paymentProvider(PaymentProviderInterface $provider): void
    {
        $this->requireCapability('provider.payment');
        $this->payments->register($provider);
    }

    public function deliveryProvider(DeliveryProviderInterface $provider): void
    {
        $this->requireCapability('provider.shipping');
        $this->shipping->register($provider);
    }

    public function productBlockProvider(ProductBlockProviderInterface $provider): void
    {
        $this->requireCapability('provider.product_block');
        $this->productBlocks->register($provider);
    }

    public function aiProvider(TextGenerationProviderInterface $provider): void
    {
        $this->requireCapability('provider.ai');
        $this->ai->register($provider);
    }

    private function requireCapability(string $capability): void
    {
        if (!in_array($capability, $this->declaredCapabilities, true)) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.capability_undeclared') . $capability);
        }
    }
}
