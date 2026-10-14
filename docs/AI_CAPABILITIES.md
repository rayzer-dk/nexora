# Nexora AI capabilities

AI is optional. The store stays fully functional when AI is disabled or no provider is configured. Every result is a draft that a person reviews: AI never saves or publishes content, changes prices or stock, places orders or contacts customers by itself.

## Providers

Built in: OpenAI (Responses API), Gemini (generateContent) and Claude (Messages API). Keys are typed on System → AI assistant, stored encrypted and never shown again; values from `.env` apply until a key is saved in the admin. "Refresh models" asks the provider for its current model list; a built-in list is used until then.

Signed trusted extensions may add a text provider (`provider.ai`, `TextGenerationProviderInterface`) or a machine-translation service such as DeepL or Google Cloud Translation (`provider.translation`, `TranslationProviderInterface`). A translation service appears next to the AI providers on every "Translate" button.

## Tasks

| Task | Where | Result |
| --- | --- | --- |
| `product_draft` | Product form, "Prepare description" | short teaser + HTML description, put into the visual editor |
| `product_from_photo` | Product form and quick product, "Card from a photo" | name, brand (empty when not visible), texts, characteristics |
| `category_text` | Category form | intro + SEO text, both put into the category text; the current text is used as the source |
| `seo_meta` | Product, page, blog article | meta title and description |
| `translate` | Translations of products and categories, pages, blog, forms, menu labels | translated HTML with tags kept |
| `reply` | Reviews and questions, assistant on the AI page | reply draft |

## How results reach the form

- The visual editor follows its form field: a draft or a translation shows up in it at once (also in HTML source view) and is kept when the person edits it afterwards.
- "Translate all languages" and "Translate" of one language ask before replacing a translation that already exists; "Cancel" fills only the untranslated fields. A field that still holds a copy of the main-language text counts as untranslated.
- The language of the source panel is sent with every translation, so neither the model nor a translation service has to guess it.

## Limits and reliability

- Long texts are translated in parts of about 6,000 characters cut after paragraphs, headings, lists or sentences, and joined back in order; nothing is cut off. Each part is one request and counts against the daily limit; a text is refused before the first request when its parts do not fit into what is left of the day.
- Any language with an ISO code can be a target (French, Spanish, Portuguese (Brazil), …); unknown codes are refused.
- Room for long answers: Claude is asked for up to 16,000 tokens; OpenAI reasoning models (gpt-5, o3, o4) run with low reasoning effort. An answer cut off by the provider is reported as such ("the text is too long for the model") instead of "unexpected format". A Gemini answer blocked by its safety filter says so.
- Provider errors are shown with the provider's own status and message (for example `HTTP 401: invalid x-api-key`); the key is never part of a message.
- Requests wait up to 120 seconds; an admin request that runs several model calls (an article, a long text) extends its own PHP time limit accordingly.
- Customer and catalogue text is passed to the model as quoted data and can not close the data block; the reply must be one JSON object; script, style, iframe, object and embed tags are removed from every answer.
- Every call lands in the usage log (admin, task, provider, model, characters in and out, status) and counts against the per-store daily limit (200 by default).
