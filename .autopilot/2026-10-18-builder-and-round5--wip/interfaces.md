# Interfaces — read first

Repo: `C:\Users\Владимир\Documents\Codex\nexora_live` (Windows, Git Bash + PowerShell; PHP `C:/laragon/bin/php/php-8.4.26-Win32-vs17-x64/php.exe`). Code map: `notes.md` in this folder — read the sections your ticket names instead of exploring.

## Project rules (binding)

- `AGENTS.md` of the repo is binding (source quality, Quality Fixer, Rector dry-run only). No redundant guards, no catch-and-ignore, no duplicate implementations, no pointless tests, no comments that retell the code. Comments only for a non-obvious *why*. Typed PHPDoc for PHPStan is fine.
- Symfony 8 / PHP 8.4, `declare(strict_types=1)`, `final` classes, constructor DI (autowired via `config/services.yaml`). Match the style of the file you edit (some Twig files are one line per block — keep it).
- Every admin route → `src/Modules/Admin/Authorization/AdminPermissionSubscriber.php` route→permission map (else `bin/admin-access-check.php` fails). Destructive buttons → `data-confirm` (`bin/admin-confirm-check.php`).
- CSRF: `isCsrfTokenValid('<id>', …)`; JSON endpoints answer `{ok:bool, error?:string, message?:string}`; 403 CSRF/permission, 409 conflict, 422 validation.
- i18n: no hard-coded UI text. Twig `ui_text('key')`, PHP `CanonicalUiText::get('key', [...])`. New admin keys in **both** `resources/translations/uk-UA/admin.php` and `en-US/admin.php`; storefront keys in all 6 locales of `storefront.php`. JS keys must start with `admin.ai.`, `js_`, `vue.`, `builder_` or `admin.seo.` (exported by `_shell.html.twig` to `window.MC_I18N`). Ukrainian UI says «ШІ».
- Admin hints: `{% import '@storefront/admin/_help.html.twig' as help %}` → `help.hint(text, link, link_label, example)`; concrete text (what / example / where to get), no filler.
- No inline event handlers (CSP); scripts need `nonce="{{ app.request.attributes.get('_csp_nonce') }}"`.
- Migrations: `migrations/VersionYYYYMMDDHHMMSS.php`; newest existing `Version20270101010000`. This build: `Version20270101030000` (layout presence, T03), `Version20270101040000` (404 user agent, T07). `down()` drops only what `up()` created. MySQL 8.4 + utf8mb4.
- Frontend: admin runtime `assets/storefront/admin-runtime.js`, builder `assets/admin/features/builder.js` (lazy, vite entry `adminBuilder`). After JS/CSS edits `npm run build:production` (public/build not in git). `npm run typecheck` 0 errors, `npx eslint assets` 0 errors.
- Checks: `php vendor/bin/phpunit` (3 known Windows failures: WebPushCryptoTest ×2, CronSchedulerTest), `composer quality:php` (PHPStan, PHPCS, encoding, source guard — must pass), `php bin/admin-access-check.php`, `php bin/i18n-template-check.php`, `php bin/full-release-static-check.php`. One test file: `php vendor/bin/phpunit tests/Unit/X.php`.
- **Never** touch `.env`, `.env.local`, the DB `nexora_dev`, Laragon/MariaDB services. Never commit (the orchestrator commits).
- Договорились (from PROGRESS.md, word for word): «Логотип платформы — присланный владельцем (public/assets/branding).»; «VIES — только ЕС; Украина объясняется, не «чинится».»; «Витрина: название бренда удалено — не показывать.»; «Нигде не должно быть «нейрослопа»: каждое утверждение проверено по коду, подсказки конкретные.»; «Версия 4.0.0; модули объявляют совместимость «^3.x || ^4.0».»

## Shared files (several tickets in flight write them)

`resources/translations/*/admin.php`, `resources/translations/*/storefront.php` and `src/Modules/Admin/Authorization/AdminPermissionSubscriber.php` are not in any ticket's zone: every ticket may **add** its own keys / route entries there with the Edit tool (fresh read before each edit), never change or remove lines it did not add. The orchestrator commits these files with the ticket that lands; keys of a ticket still in flight may ride along — harmless, they are only unused until it lands.

## Runtime check (isolated copy)

- MySQL 8.4 on `127.0.0.1:3307` (db `nexora_audit`, root, no password); app copy `C:/Users/18EE~1/AppData/Local/Temp/nxapp` on `http://127.0.0.1:8010` (PHP built-in server). Admin credentials: `C:/Users/18EE~1/AppData/Local/Temp/nxapp/AUDIT_ADMIN.txt` (lines `admin: …`, `password: …`; read into env vars, never print). If the servers are down (`netstat -ano | grep -E ":3307 |:8010 "`), say so in BLOCKERS — do not start services of the user's PC.
- Sync: `robocopy <repo>\<dir> C:\Users\18EE~1\AppData\Local\Temp\nxapp\<dir> /MIR` for the dirs you changed among `src themes resources assets migrations public\build config vendor`; then delete `C:/Users/18EE~1/AppData/Local/Temp/nxapp/var/cache/*`; migrations there: `cd C:/Users/18EE~1/AppData/Local/Temp/nxapp && php bin/console doctrine:migrations:migrate -n`. First request after clearing the cache takes ~1 min — warm with one request first.
- E2E: `E2E_BASE_URL=http://127.0.0.1:8010 E2E_ADMIN_EMAIL=… E2E_ADMIN_PASSWORD=… npx playwright test -c .claude/pw-audit.config.ts <spec>` (system Edge). Screenshots go to the scratchpad, never into the repo.

## Границы, решённые в спецификации

### Builder component catalog (T01)
`Commerce\Modules\Appearance\Builder\BuilderComponentCatalog` (new, final):
- `fieldsFor(string $layoutType, string $component): ?list<array{key:string,type:string,label_key:string,group:string,default?:mixed,options?:list<array{value:string,label_key:string}>,min?:int|float,max?:int|float,step?:int|float,prop:string}>` — `prop` is the path in the block (`props.title`, `style.padding`, …) so the inspector and `LayoutSchemaValidator` agree. `null` = no description (story 4).
- `components(string $layoutType): list<array{component:string,label_key:string,fields:list<…>}>` — built-in components the type may use, in the order of the add-block list. Reuses `LayoutSchemaValidator::allowedForType` (one source of the allowed set).
- Field types: `text, textarea, url, number, select, boolean, color, image, multilingual_text, multilingual_textarea` (same names as `ExtensionSettingsSchemaValidator`).
- Common fields (id, visibility desktop/tablet/mobile, style background/color/padding/margin/text_align) are one shared group added to every component.
- `LayoutSchemaValidator` validates field values by these descriptions where a description exists (url, number range, color hex, select option); unknown keys keep today's generic rules.

### Extension block fields (T01)
- `ExtensionContributionRegistry::blockFields(string $component): ?list<…>` (new) — parses the module's `blocks/*.schema.json` (`settings_schema` of the block) with `ExtensionSettingsSchemaValidator`, returns the same field shape with `prop = props.<key>`; a broken schema returns `null` and writes a lifecycle event `block_schema_invalid` (story 7).
- Builder page gets one JSON: `data-builder-fields='{"<component>": [fields…]}'` for built-in + extension components of the type, plus `data-builder-locales='[{code,name}]'` (enabled store languages, default first — same list as module settings).

### Inspector (T01, `assets/admin/features/builder.js`)
- `renderInspector()` renders from `fields[b.component]`; no per-component hard-coded groups remain. Values read/written through the existing `setPath(block, prop, value)`; multilingual values are `{locale: text}` objects in `props`.
- Storefront: `extension_component.html.twig` (product, checkout) and a new shared partial for home/category/cart read `block.settings.<key>`; a multilingual value shows the storefront locale, falling back to the default locale.

### Undo / redo (T02, builder.js only)
- `history = {past: Layout[], future: Layout[]}`, max 100; `commit(label)` pushes a deep copy before a change; text input coalesces while the field keeps focus or within 1 s. Buttons `[data-builder-undo]`, `[data-builder-redo]` in the builder toolbar; keys Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z outside text inputs. Undo/redo calls the same `sync()` as any change (so it autosaves in T03).

### Draft autosave, live preview, concurrent editing (T03)
- `LayoutRevisionStore`: `current(int $storeId, string $type): array{draft_id:?int, published_id:?int, version:int, saved_by:?int, saved_at:?string}` — `version` = max revision id of the type; `saveDraft(…, ?int $baseVersion = null, bool $force = false)` and `publish(…, ?int $baseVersion = null, bool $force = false)` throw `Commerce\Modules\Appearance\Builder\LayoutConflictException(int $currentVersion, ?int $savedBy, string $savedAt)` when `$baseVersion !== null && !$force && $baseVersion !== current version` (checked inside the transaction with `SELECT … FOR UPDATE` on MySQL). `rollback(…, ?int $baseVersion = null, bool $force = false)` the same. Existing callers without a base keep today's behaviour.
- Routes (CSRF `layout_builder_<type>`, permission like `admin_appearance_builder`):
  - `POST /admin/appearance/builder/{type}/draft.json` `admin_appearance_builder_draft_json` — body `layout_json`, `base_version`, `force=0|1` → `{ok:true, version, saved_at}` | 409 `{ok:false, error:'conflict', version, saved_by_name, saved_at}` | 422 `{ok:false, error:'invalid', message}`.
  - `POST /admin/appearance/builder/{type}/publish.json` `admin_appearance_builder_publish_json` — same contract.
  - `POST /admin/appearance/builder/{type}/presence.json` `admin_appearance_builder_presence` — records the caller, answers `{ok:true, others:[{name, since}]}` (others seen ≤ 90 s ago).
- Table `mc_layout_presence(store_id, layout_type VARCHAR(32), admin_user_id, first_seen_at DATETIME(6), last_seen_at DATETIME(6), PRIMARY KEY(store_id, layout_type, admin_user_id))`; one row per admin; rows older than 1 day are deleted on write.
- Preview: `CategoryLayoutService`, `CartLayoutService`, `ProductPageLayoutLoader`, `CheckoutLayoutService` use `LayoutRevisionStore::active()` (draft only under a valid preview token) instead of `publishedOrNull()` for the storefront request; behaviour without a token unchanged.
- Builder page keeps the form POST as a fallback without JS; with JS, saving and publishing go through the JSON routes.

### Round 5 (T05–T07) — touch only the files the ticket names; reuse existing services listed in `notes.md` §2.

### Warehouse of a market (T08)
- One method `Commerce\Modules\Catalog\Application\MarketStockLocation::locationId(Connection $db, int $marketId): int` (0 = none) — static or a tiny service; `$db` is passed because `ProductWriter` calls it inside its transaction.

## Test seams
- Unit: `BuilderComponentCatalog` (every allowed component of every type has a description or is listed as generic), `ExtensionContributionRegistry::blockFields` (valid / broken schema), `LayoutRevisionStore` conflict and force (SQLite; `FOR UPDATE` only on MySQL), presence expiry, `LayoutSchemaValidator` field checks, 404 UA bot flag, `MarketStockLocation`, `ExtensionPackageManager::activate` carry-over.
- E2E (isolated copy): `tests/e2e/builder-inspector.spec.ts` (T01), `builder-undo.spec.ts` (T02), `builder-live-preview.spec.ts` incl. two browser contexts for the conflict (T03), plus one spec per round-5 ticket.

## Built by finished tickets
