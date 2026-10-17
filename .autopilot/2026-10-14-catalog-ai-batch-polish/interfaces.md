# Interfaces — read first

Repo: `C:\Users\Владимир\Documents\Codex\nexora_live` (Windows, Git Bash + PowerShell; PHP `C:/laragon/bin/php/php-8.4.26-Win32-vs17-x64/php.exe`). Code map: `notes.md` in this folder — read the sections your ticket names instead of exploring.

## Project rules (binding)

- Symfony 8 / PHP 8.4, `declare(strict_types=1)`, `final` classes, constructor DI (autowired via `config/services.yaml`). Match the surrounding style of each file (some files are one-line-per-block Twig; keep it).
- Every admin route → add to `src/Modules/Admin/Authorization/AdminPermissionSubscriber.php` route→permission map (else `bin/admin-access-check.php` fails). Destructive forms/buttons → `data-confirm` (`bin/admin-confirm-check.php`).
- CSRF: `isCsrfTokenValid('<id>', (string) $request->request->get('_token'))`; JSON endpoints answer `{ok:bool, error?:string, message?:string}`; 403 CSRF/permission, 422 validation.
- i18n: no hard-coded UI text. Twig `ui_text('key')`, PHP `CanonicalUiText::get('key', [...])`. New keys in **both** `resources/translations/uk-UA/admin.php` and `en-US/admin.php` (storefront keys: all 6 locales in `storefront.php`). JS keys must start with `admin.ai.`, `js_`, `vue.`, `builder_` or `admin.seo.` (that is what `_shell.html.twig:138` exports to `window.MC_I18N`). Ukrainian UI says «ШІ», not «ІІ».
- Admin UI hints: `{% import '@storefront/admin/_help.html.twig' as help %}` → `help.hint(text, link, link_label, example)` inside the `<label>` after the control (notes §6).
- Templates must not use inline event handlers (CSP); scripts need `nonce="{{ app.request.attributes.get('_csp_nonce') }}"`.
- Migrations: `migrations/VersionYYYYMMDDHHMMSS.php`, newest existing is `Version20261231230000` — use `Version20270101000000`+ (increment per ticket: T02 none, T06 `Version20270101010000`, T07 `Version20270101020000` if needed). `down()` drops only what `up()` created. MySQL 8.4 + utf8mb4.
- Frontend: `assets/admin/admin-runtime.js` (AI/translation helpers) is imported by `assets/storefront/admin-runtime.js` (big admin runtime: quick price/status, tabs, toast). After JS/CSS edits run `npm run build:production` (public/build is NOT in git). `npm run typecheck` and `npx eslint assets` must have 0 errors.
- Tests: `php vendor/bin/phpunit` (3 known Windows env failures: WebPushCryptoTest ×2, CronSchedulerTest crontab path). Put new unit tests in `tests/Unit/`. E2E specs in `tests/e2e/` (Playwright); run against the isolated copy (see «Runtime check»).
- **Never** touch `.env`, `.env.local`, the user's DB `nexora_dev`, or Laragon/MariaDB services.
- Договорились (from PROGRESS.md, word for word): «Логотип платформы — присланный владельцем (public/assets/branding)»; «VIES — только ЕС; Украина объясняется, не "чинится"»; «Витрина: название бренда удалено — не показывать».

## Runtime check (isolated copy)

- Isolated MySQL 8.4 on `127.0.0.1:3307` (db `nexora_audit`, root, no password) and a copy of the app at `C:/Users/18EE~1/AppData/Local/Temp/nxapp` served on `http://127.0.0.1:8010` (PHP built-in server, already running). Admin credentials: `C:/Users/18EE~1/AppData/Local/Temp/nxapp/AUDIT_ADMIN.txt` (never print them).
- To test your change there: copy changed dirs with `robocopy <repo>\<dir> C:\Users\18EE~1\AppData\Local\Temp\nxapp\<dir> /MIR` for `src themes resources assets migrations public\build config`, delete `C:/Users/18EE~1/AppData/Local/Temp/nxapp/var/cache/prod-3.97.23` (or the current version dir), run migrations there: `cd C:/Users/18EE~1/AppData/Local/Temp/nxapp && php bin/console doctrine:migrations:migrate -n`.
- E2E: `E2E_BASE_URL=http://127.0.0.1:8010 E2E_ADMIN_EMAIL=… E2E_ADMIN_PASSWORD=… npx playwright test -c .claude/pw-audit.config.ts <spec>` (read the creds file into env vars without echoing). Browser channel is system Edge.

## Boundaries decided in the spec

### Catalog — stock (T02)
`Commerce\Modules\Catalog\Application\ProductStockService` (new)
- `setStock(int $storeId, int $marketId, string $productPublicId, string $quantity): array{ok:bool, previous:array<int,string>, quantity:string, error?:string}` — single-variant products only (variant_count 1); quantity validated `^\d{1,9}(\.\d{1,6})?$` (comma→dot); writes `mc_stock_level` of the market's top-priority location (`mc_market_inventory_location ORDER BY priority ASC, location_id ASC LIMIT 1`) for the variant's inventory item; `row_version=row_version+1, updated_at=UTC_TIMESTAMP(6)`. Returns previous per `stock_level.id`.
- `zeroStock(int $storeId, int $marketId, list<string> $productPublicIds): array{updated:int, skipped:int, previous:array<int,string>}` — all active variants; products without inventory item → skipped; max 500 ids.
- `restore(array<int,string> $previous): int` — sets `stocked_quantity` back by `stock_level.id`, scoped to rows that still exist.
- Dispatch the existing product-updated domain event the same way `ProductWriter::update` does (source `stock`) so search/feeds refresh.
- Undo: `AdminUndoService::remember($storeId,$adminId,'stock',$label,['previous'=>$previous])`; `UndoAdminController` gains kind `stock` → `ProductStockService::restore`.
- Products list query (`DbalCatalogAdminQuery::products`) adds `stock_total` (sum of stocked−reserved? **No: stocked_quantity**, same as the edit form) over active variants at the market's top location, `variant_count`, and `manage_inventory` of the first variant.

### Catalog — category counts (T01)
- `DbalCatalogAdminQuery::categoryTree()` and `categories()` both return `product_count` (direct) and `product_count_total` (distinct products in the category and all descendants within the same store). One recursive CTE per request, not per row.

### AI insertion feedback (T03) — JS only + `_ai_box`/`_ai_photo` markup
- Helper in `assets/admin/admin-runtime.js`: `insertAiFields(scope: Element, mapping: Array<{selector, value, label}>): {written: Array<{field, label}>, missing: string[], empty: string[]}` and `showAiResult(box, result, fields)` rendering `[data-ai-result]` inside the box; `revealField(field)` opens the containing tab (`[data-product-tab]` → click `.admin-tab[data-tab-target=…]`, or generic `[data-tabs] > [data-tab]`), scrolls into view and adds class `is-ai-flash` for 2 s. Scope = the closest `form` of the box, falling back to `document`.
- Labels: the visible label text of the field (`field.closest('label')` first text / `span`), fallback the field name.

### AI — fields translation, glossary, batch (T06)
- `AiTaskService::translateFields(int $storeId, string $admin, string $provider, array<string,string> $fields, string $target, string $source = ''): array{fields:array<string,string>, remaining:int}` — one model call for all short fields (JSON in/out, keys preserved, empty input fields omitted), fields longer than 6000 chars go through the existing chunked `translate` path. Extension `TranslationProviderInterface` providers: per field. Applies the glossary. Counts each model call against the daily limit; refuses up front when the calls do not fit.
- New task code `translate_fields` accepted by `POST /admin/api/ai/task` with `fields[json]` = JSON object, `fields[target]`, `fields[source]`; response `fields` = translated object.
- `Commerce\Modules\Ai\Application\AiGlossary` (new): `terms(int $storeId): list<array{id:int, term:string, mode:'keep'|'fixed', translations:array<string,string>}>`, `save(int $storeId, array $rows): void`, `protect(int $storeId, string $text, string $targetLocale): array{0:string, 1:array<string,string>}` (keep-terms → `⟦T1⟧`…), `restore(string $text, array $map): string` (throws `\DomainException` `admin.ai.glossary_broken` if a marker is missing), `promptHint(int $storeId, string $targetLocale): string` (fixed translations for the LLM prompt). Table `mc_ai_glossary(id, store_id, term VARCHAR(190), mode VARCHAR(8), translations_json JSON, created_at, updated_at, UNIQUE(store_id, term))`.
- `Commerce\Modules\Ai\Application\AiBatchService` (new): tables `mc_ai_batch(id, public_id CHAR(36), store_id, kind 'translate'|'generate', options_json, status 'running'|'paused'|'limit'|'done'|'cancelled', created_by, created_at, updated_at)` and `mc_ai_batch_job(id, batch_id FK CASCADE, store_id, entity_type 'product'|'category'|'brand', entity_id, locale VARCHAR(16), task VARCHAR(32), status 'pending'|'done'|'failed'|'skipped'|'review'|'applied'|'rejected', attempts, error VARCHAR(255), result_json JSON NULL, previous_json JSON NULL, processed_at, KEY(batch_id,status), KEY(store_id,status,id))`.
  - `start(int $storeId, string $admin, array{kind:string, entity_types:list<string>, locales:list<string>, mode:'missing'|'overwrite', provider:string, task?:string, product_ids?:list<string>}): string` (public id; no duplicate pending job for the same entity×locale×task in an active batch).
  - `process(int $limit, float $budgetSeconds, ?int $storeId = null): array{done:int, failed:int, pending:int, paused_for_limit:bool}` — advisory lock `nexora_ai_batch`; translate jobs write via `CatalogTranslationService::saveProduct/saveCategory` (+ brand equivalent) with `is_fallback=0`; generate jobs store `result_json` and go `review`.
  - `progress(int $storeId): list<array{batch, per_locale: array<string, array{done, failed, pending, skipped}>, totals}>`; `pause/resume/cancel(string $publicId)`; `retryFailed(string $publicId)`; `review(int $storeId, int $page): list`; `apply(int $storeId, list<int> $jobIds): int` (writes fields, remembers previous for undo kind `ai_review`), `reject(...)`.
  - "Untranslated" = no translation row, or `is_fallback=1`, or a required field empty, or field equals the default-language value.
- Console `commerce:ai:batch --limit=20 --max-seconds=50`; scheduled task code `ai_batch` (interval 300, group `catalog`) in `ScheduledTaskRegistry::core()` with `scheduler.label.ai_batch`/`scheduler.description.ai_batch` keys.
- Admin `Commerce\Modules\Ai\Http\AiBatchAdminController`: `GET /admin/catalog/ai-batch` (`admin_ai_batch`), `POST /admin/catalog/ai-batch/start` (`admin_ai_batch_start`), `POST …/run` (`admin_ai_batch_run`, JSON progress, `process(10, 25.0, storeId)`), `POST …/{publicId}/pause|resume|cancel|retry`, `GET …/review` (`admin_ai_batch_review`), `POST …/review/apply|reject`. Permission `catalog.manage` (start/apply) — same as AI task routes. CSRF id `admin_ai_batch`.
- Sidebar: «Пакетний переклад ШІ» under Catalog in `_shell.html.twig` (key `ai_batch`), shown only with `catalog.manage`.

### Bulk AI (T07)
- Product list bulk `<select name="action">` gains `ai_descriptions`, `ai_seo` → `CommerceOperationsAdminController::bulkProducts` calls `AiBatchService::start(['kind'=>'generate','task'=>'product_draft'|'seo_meta','product_ids'=>…,'mode'=>'missing'|'overwrite'])` and redirects to `admin_ai_batch` with a flash.

### Brand / logo (T05)
- `StorefrontPresentationSettings`: `brand.logo_height` int 32–96, default 48; Twig exposes it; CSS var `--mc-logo-height` on the header.
- Visible-name rule (one Twig variable `storefront_brand_visible_name`, computed once in `base.html.twig`): `''` when `show_title=='0'`, or when `brand.title` is empty **and** a logo is set; else `brand.title ?: store name`. All visible renderings (header strong + mobile subtitle wrapper, footer, landing, forum) use it; SEO/alt/aria keep `storefront_brand_name`.

### Colour picker (T04)
- New Vue SFC `assets/admin/components/ColorPicker.vue` props `{modelValue?: string, popular: string[], recent: string[], labels…}` emits `pick(hex)` and `clear()`; used by `RichTextEditor.vue` for both text and background. Recent storage stays `mc_editor_recent_colors` (max 10 kept per kind).

## Test seams
- Unit: `ProductStockService` (SQLite-compatible SQL where possible, or mock Connection), `AiGlossary::protect/restore`, `AiTaskService::translateFields` (stub provider like `AiTaskServiceTest`), `AiBatchService` selection of untranslated + limit pause (SQLite in-memory schema in the test).
- E2E: one spec per surface ticket (T01–T05, T06/T07) in `tests/e2e/`.

## Built by finished tickets

### T01 (catalog lists)
- `ProductStockService::setStock(int $storeId, int $marketId, string $publicId, string $quantity): {ok, previous: array<int,string>, quantity, error?: format|not_found|variants|no_location|no_inventory}` (quantity in display form "7.5"); `zeroStock(int $storeId, int $marketId, list<string> $ids): {updated, skipped, previous}` (max 500); `restore(array $previous, ?int $storeId = null, ?int $marketId = null): int`; static `display(?string): string`. Location = market's first **active** warehouse (as ProductWriter).
- `DbalCatalogAdminQuery::productCounts(int $storeId, ?list<int> $ids = null): array<int,{direct,total}>` (one recursive CTE); `categoryBranch(int $storeId, list<int>): list<int>`; `categories(..., bool $withCounts = false)`; `products()` rows add `variant_count, has_inventory, manage_inventory, stock_quantity, stock_total`; sort key `stock`. Product-list category filter now includes subcategories.
- Route `admin_catalog_product_quick_stock` POST `/admin/catalog/products/{publicId}/quick-stock` (CSRF `admin_quick_stock`, field `stock`) → `{ok, stock}` | 422 `{ok:false, error}`. Bulk action value `zero_stock`. Undo kind `stock` payload `{previous}`. Products list column key `stock`.
- Status column: switch only (no `.admin-badge` next to it); state in `title`/`aria-label`.

### T02 (AI result visible)
- `assets/admin/admin-runtime.js` module-local helpers (not exported): `insertAiFields(scope, mapping[{selector,value,label}]) → {written[{field,label,value,selector}], missing, empty}`; `showAiResult(box, result, fields[{label,value,selector?}])`; `revealField(field)`. DOM hooks `[data-ai-result]`, `[data-ai-result-item]`, `[data-ai-result-text]`, `[data-ai-result-more]`, `[data-ai-result-where]`, `[data-ai-reveal-field]`, `[data-ai-copy]`; CSS `.admin-ai-result`, `.is-ai-flash`, status `.is-error`. Keys `admin.ai.{inserted_in,show_field,show_full,show_less,copy,copied,copy_failed,insert_failed,empty_field,empty_answer,photo_notes}`. Each AI helper reads status/provider/token from its own `.admin-ai-helper`.

### T03 (colour picker)
- `assets/admin/components/ColorPicker.vue` props `{modelValue?: string, fallback?: string, popular: string[], recent: string[], labels: ColorPickerLabels{title,spectrum,hue,hex,apply,invalid,popular,recent,recentEmpty,none}}`; emits `pick('#rrggbb')`, `clear()`, `close()`. Recent storage `mc_editor_recent_colors` `{text:[],bg:[]}`, max 10 per kind; 10 popular colours. Keys `vue.components.richtexteditor.{color_spectrum,color_hue,color_invalid,popular_colors,recent_colors_empty,color_none}`; `color_pick` now unused. Old `<input type=color>`, `.rich-editor__color`, `.rich-editor__tiny` removed.

### T11 (extension core)
- `TrustedExtensionContext::settings(): array<string,mixed>` (all fields, defaults applied, secrets decrypted, read live) and `::setting(string $key, mixed $default = null): mixed`; both via `ExtensionSettingsManager::activeSettings(string $code)`.
- `ExtensionSettingsManager::carryOver(int $installationId): int` — called by `ExtensionPackageManager` on activating an upgrade (not downgrade/rollback, only when the target has no values); revalidates against the new schema, invalid → default, dropped keys kept.
- `multilingual_text`/`multilingual_textarea` settings stored as `{locale: text}`, one input per enabled store language.
- Events `commerce.order.cancelled`, `commerce.order.completed`, `commerce.payment.status_changed` carry `store_id`; `ApiWebhookEventSubscriber` falls back to `mc_sales_order.store_id` for legacy events. `resources/event-contracts/v1/*.schema.json` list `store_id` as required (documentation only).
- `commerce:extension:list-events` prints the 10 published events; `--all` adds 21 reserved names marked «(reserved: …)».
- NOT built (story 58): extension block schema fields in the builder inspector — needs a schema-driven `renderInspector` in `assets/admin/features/builder.js`; moved to the separate builder build by the user.

### T04 (brand / logo)
- `brand.logo_height` int 32–96, default 48 (clamped in `StorefrontPresentationSettings::normalize`); POST field `brand_logo_height`; CSS var `--mc-logo-height` on `:root` (phones capped at 44 px, max width 260 px).
- Twig `storefront_brand_visible_name` computed once in `base.html.twig`; used by header, mobile subtitle, footer, landing, checkout footer. SEO/alt/aria/og/JSON-LD keep `storefront_brand_name`. Email header: store_name → `storefront_identity().name` → 'Nexora Commerce'.
- Keys `admin.appearance.storefront.logo_height`, `.logo_height.help`, `.logo_help` (`%height%`), uk-UA + en-US.

### T05 (AI batch translation, glossary)
- `AiTaskService::translateFields(int $storeId, string $admin, string $provider, array $fields, string $target, string $source=''): {fields, remaining}`; optional `?AiGlossary` last ctor arg. Short limit → `AiLimitException(needed, limit, used)` (extends DomainException) from `run()` and `translateFields()`.
- `POST /admin/api/ai/task` task `translate_fields`, `fields[json]`, `fields[target]`, `fields[source]` → `{ok, fields, provider, remaining}`.
- `AiGlossary` terms/save/restore/promptHint as spec; `protect(int $storeId, string $text, string $target, bool $withFixed=false)`. Glossary saved via POST `admin_system_ai_glossary`.
- `AiBatchService::start(int, string, array): string` — throws DomainException `admin.ai.batch.nothing` when nothing new; accepts kind 'generate' (task, product_ids) but `process()` handles only 'translate' jobs. `progress(int $storeId, int $limit=10)` adds `failures`; `pause/resume/cancel/retryFailed(string $publicId, ?int $storeId=null)`; helpers `providers(int)`, static `fields(string)`.
- Routes: `admin_ai_batch` GET, `admin_ai_batch_start`, `admin_ai_batch_run`, one `admin_ai_batch_action` POST `/admin/catalog/ai-batch/{publicId}/{pause|resume|cancel|retry}` (JSON for XHR). CSRF `admin_ai_batch`; permission `catalog.manage` via `admin_ai_batch*` prefix.
- Cron task `ai_batch`: `commerce:ai:batch --limit=20 --max-seconds=50`, every 300 s, group catalog. Migration `Version20270101010000` (3 tables).

### T06 (bulk AI)
- `AiBatchService::GENERATE_FIELDS` (product_draft→short_description,description; seo_meta→meta_title,meta_description); `review(storeId,page)` items `{id,batch,task,product_id,name,changes[{field,before,after}]}`; `reviewCount`, `reviewJobIds`, `applyJobs(storeId,ids): list<int>`, `apply`, `reject`, `undo`; progress per_locale/totals gain `review`. Generate refuses translation-only providers (`error_provider`); no new job while pending/review exists.
- Routes `admin_ai_batch_review` GET, `admin_ai_batch_review_apply|_reject` POST (ids[] or all=1). Bulk actions `ai_descriptions`, `ai_seo`, `ai_translate` keep selection in session (`AiBatchAdminController::SELECTION`) → `admin_ai_batch?selection=1`. Undo kind `ai_review` payload `{jobs}`.

### T07 (hints, getting started)
- Help slug `getting-started` (`/admin/help/getting-started`, first in `HelpCatalog::DOCS`), `docs/GETTING_STARTED_UK.md`; keys `admin.help.{sf,pl,cl,cf,pf,tr,ai,ab}.*` (+`.ex` examples), `admin.help.start.link` (dashboard). Hints on sortable headers moved to toolbars.
