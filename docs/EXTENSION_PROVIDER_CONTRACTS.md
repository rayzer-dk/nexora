# Trusted provider contracts — Extension API 2.0

Signed `trusted_release` extensions can register selected in-process providers through `TrustedExtensionContext`. This is the supported way to add payment, shipping, product-page provider logic or an AI text provider without editing Symfony configuration or Core files.

The extension must declare the matching capability in `manifest.json` and must implement the public interface from the installed Extension API.

Supported provider capabilities:

- `provider.payment` -> `Commerce\Modules\Payment\Contract\PaymentProviderInterface`
- `provider.shipping` -> `Commerce\Modules\Shipping\Contract\DeliveryProviderInterface`
- `provider.product_block` -> `Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface`
- `provider.ai` -> `Commerce\Modules\Ai\Contract\TextGenerationProviderInterface`

Registration from the trusted entrypoint:

    public function boot(TrustedExtensionContext $context): void
    {
        $context->paymentProvider(new MyPaymentProvider(...));
        $context->deliveryProvider(new MyDeliveryProvider(...));
        $context->productBlockProvider(new MyProductBlockProvider(...));
        $context->aiProvider(new MyAiProvider(...));
    }

Only declare and register the providers your module actually needs. Duplicate provider codes are rejected. A module cannot register a provider capability it did not declare in its manifest.

Provider contracts are versioned by the Nexora Extension API. A module should never reach into registry internals or edit `services.yaml`.


Developer inspection commands:

```bash
php bin/console commerce:extension:list-points
php bin/console commerce:extension:list-events
php bin/console commerce:extension:list-contracts
```
