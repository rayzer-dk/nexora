# Trusted provider contracts — Extension API 2.0

Signed `trusted_release` extensions can register selected in-process providers through `TrustedExtensionContext`. This is the supported way to add payment, shipping, product-page provider logic or an AI text provider without editing Symfony configuration or Core files.

The extension must declare the matching capability in `manifest.json` and must implement the public interface from the installed Extension API.

Supported provider capabilities:

- `provider.payment` -> `Commerce\Modules\Payment\Contract\PaymentProviderInterface`
- `provider.shipping` -> `Commerce\Modules\Shipping\Contract\DeliveryProviderInterface`
- `provider.product_block` -> `Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface`
- `provider.ai` -> `Commerce\Modules\Ai\Contract\TextGenerationProviderInterface` (a language model; it appears in the AI provider list of the admin)
- `provider.translation` -> `Commerce\Modules\Ai\Contract\TranslationProviderInterface` (a machine-translation service such as Google Cloud Translation or DeepL; it appears in the provider list of every "Translate" button, next to the AI providers, and receives the text itself instead of a prompt)

- `provider.exchange_rate` -> `Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface` (an exchange-rate publisher; its code appears in the rate-source choice of every currency; codes `[a-z0-9_]{2,20}`, built-in codes are kept)
- `provider.search` -> `Commerce\Modules\Search\Contract\SearchCandidateProviderInterface` (a search engine; asked before the built-in one, return null to let the next engine or the SQL search answer)
- `provider.notification_sender` -> `Commerce\Modules\Notification\Contract\NotificationSenderInterface` (replaces the built-in sender of the channel it returns from `channel()`, for example `NotificationChannel::Sms`)
- `provider.feed` -> `Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface` (a feed format or marketplace; it gets the canonical product rows and returns the file; codes `[a-z0-9_]{2,30}`, built-in codes are kept)

These four are registered with `$context->provide('<capability>', $service)`; the object must implement the contract of the capability or `provide()` throws.

Registration from the trusted entrypoint:

    public function boot(TrustedExtensionContext $context): void
    {
        $context->paymentProvider(new MyPaymentProvider(...));
        $context->deliveryProvider(new MyDeliveryProvider(...));
        $context->productBlockProvider(new MyProductBlockProvider(...));
        $context->aiProvider(new MyAiProvider(...));
        $context->translationProvider(new GoogleTranslateProvider(...));
        $context->provide('provider.feed', new HotlineFeed(...));
    }

Only declare and register the providers your module actually needs. Duplicate provider codes are rejected. A module cannot register a provider capability it did not declare in its manifest.

Provider contracts are versioned by the Nexora Extension API. A module should never reach into registry internals or edit `services.yaml`.


Developer inspection commands:

```bash
php bin/console commerce:extension:list-points
php bin/console commerce:extension:list-events
php bin/console commerce:extension:list-contracts
```

## Example: Google Translate as a module

The built-in translation buttons use the AI providers configured in Admin → System → AI. A module adds another engine without touching them:

1. `manifest.json`: `"execution": "trusted_release"`, `"capabilities": ["provider.translation"]`, a `settings_schema` with a `secret` field `api_key`, and the signed entrypoint.
2. The provider class implements `TranslationProviderInterface`: `code()` returns `google_translate` (a-z, 0-9, `_`; `openai`, `gemini`, `anthropic` are reserved), `label()` returns `Google Translate`, `enabled()` is true when the key is set, and `translate($text, $sourceLocale, $targetLocale)` calls the service (Google accepts `format=html`, which keeps tags and attributes) and returns the translated text.
3. `boot()` registers it with `$context->translationProvider(...)`.

After the module is activated, the provider appears in the provider list on the product, category, page, form, blog and navigation translation panels and on the translation page; "Translate all languages" and per-field buttons use it like any other provider. Calls count against the daily AI limit and are written to the AI usage log under the provider code.
