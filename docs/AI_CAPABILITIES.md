# Nexora AI capabilities

AI is optional. The store stays fully functional when AI is disabled or no provider is configured. AI never changes prices or stock, places orders or contacts customers. Results in forms are drafts that a person saves; bulk descriptions and SEO meta wait on the review page until a person applies them. The one exception is batch translation: a batch that an administrator starts writes the translations straight into the catalogue (see below).

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
| `translate_fields` | Translation page of a product, batch translation | all short fields of one entity and language in one JSON request, keys kept; fields over 6,000 characters go through `translate` in parts |
| `reply` | Reviews and questions, assistant on the AI page | reply draft |

## How results reach the form

- The visual editor follows its form field: a draft or a translation shows up in it at once (also in HTML source view) and is kept when the person edits it afterwards.
- "Translate all languages" and "Translate" of one language ask before replacing a translation that already exists; "Cancel" fills only the untranslated fields. A field that still holds a copy of the main-language text counts as untranslated.
- The language of the source panel is sent with every translation, so neither the model nor a translation service has to guess it.

- Every AI helper shows a preview of each generated field (300 characters, "Show in full"), the field it was written into and "Show field" (opens the tab of the form, scrolls to the field and highlights it for 2 s). "Inserted" is reported only for fields that were really written; when the field is not found the preview gets a "Copy" button, and a field the model left empty is named. The photo card and the draft write only into their own product form.

## Batch translation

Catalogue → AI batch translation. Choose what to translate (products, categories, brands), the target languages, the provider and the mode: "untranslated only" (no translation, an empty field or a copy of the main-language text; the default) or "translate everything again" (asks first). The source is the main language of the store.

- The batch is a queue in the database (`mc_ai_batch`, `mc_ai_batch_job`): one job per entity × language. It is processed by the scheduler task `ai_batch` (every 5 minutes, up to 20 jobs and 50 seconds per run; command `commerce:ai:batch --limit=20 --max-seconds=50`) and by "Process now" on the page, so no browser tab has to stay open.
- One job is one `translate_fields` request (plus parts for long fields). Results are written as real translations of the entity.
- Progress per language: done, failed, left. Pause, resume, cancel; a failed job is retried up to 3 times, then keeps its error and can be sent again with "Retry failed". Starting the same batch again does not add jobs that are still waiting.
- When the daily limit is used up the batch shows "Paused until tomorrow (limit)" and continues by itself the next day.

## Glossary

System → AI assistant → glossary. Each term is either "do not translate" or "fixed translation" with a value per language. It applies to every translation: single fields, "Translate all languages", batch translation and translation services from extensions.

- "Do not translate" terms are replaced with markers (`⟦T1⟧`, …) before the text is sent and put back afterwards; a result that lost a marker is refused as an error instead of being saved.
- Fixed translations are added to the model's instruction.

## Bulk AI on selected products

Products list → select products → bulk action "AI: descriptions" (short and full description), "AI: SEO meta" (meta title and description) or "AI: translate selected". The selection (up to 500 products) opens the batch page, where the mode, the provider and, for translation, the languages are chosen.

- Descriptions and SEO meta are generated by the same queue and go to "For review": before and after for each field. Nothing reaches the product until "Apply" or "Apply all"; "Reject" drops the result; an applied result can be undone.
- "Empty fields only" is the default mode; overwriting existing texts has to be chosen on the batch page.
- Generation needs a text model; a translation-only service is refused for it.

## Limits and reliability

- Long texts are translated in parts of about 6,000 characters cut after paragraphs, headings, lists or sentences, and joined back in order; nothing is cut off. Each part is one request and counts against the daily limit; a text is refused before the first request when its parts do not fit into what is left of the day.
- Any language with an ISO code can be a target (French, Spanish, Portuguese (Brazil), …); unknown codes are refused.
- Room for long answers: Claude is asked for up to 16,000 tokens; OpenAI reasoning models (gpt-5, o3, o4) run with low reasoning effort. An answer cut off by the provider is reported as such ("the text is too long for the model") instead of "unexpected format". A Gemini answer blocked by its safety filter says so.
- Provider errors are shown with the provider's own status and message (for example `HTTP 401: invalid x-api-key`); the key is never part of a message.
- Requests wait up to 120 seconds; an admin request that runs several model calls (an article, a long text) extends its own PHP time limit accordingly.
- Customer and catalogue text is passed to the model as quoted data and can not close the data block; the reply must be one JSON object; script, style, iframe, object and embed tags are removed from every answer.
- Every call lands in the usage log (admin, task, provider, model, characters in and out, status) and counts against the per-store daily limit (200 by default).
