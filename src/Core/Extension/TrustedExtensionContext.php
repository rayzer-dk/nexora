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
    /** @param list<string> $declaredRoutes @param list<string> $declaredEvents @param list<string> $declaredCapabilities @param array<string,array{interval:int,label:string,description:string}> $declaredTasks */
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
        private array $declaredTasks = [],
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

    /** Registers the handler of a task declared in the manifest `scheduled_tasks`; the handler may return a short status line. */
    public function scheduledTask(string $task, callable $handler): void
    {
        if (!isset($this->declaredTasks[$task])) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.task_undeclared') . $task);
        }
        $this->runtime->registerTask($this->code, $task, $this->declaredTasks[$task], $handler);
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
