# Nexora AI capabilities — 3.5.0

AI is optional. The store remains fully functional when AI is disabled or no provider is configured.

Current built-in use case: product content drafting from product/catalog context. The admin can request a draft and review it before saving; AI does not silently publish products, change prices, alter stock, place orders or contact customers.

Built-in providers: OpenAI and Gemini. Signed trusted extensions may add another text provider through Extension API 2.0 capability `provider.ai` and `TextGenerationProviderInterface`, so Claude, Grok, a private model gateway or an on-premise model can be integrated without patching Core.

Recommended extension use cases include product/category content assistance, translation assistance, SEO draft generation, support-assistant integrations and catalog enrichment. Any action that changes commercial data should remain explicit, permission-checked and auditable.
