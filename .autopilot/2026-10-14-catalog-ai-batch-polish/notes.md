# notes.md: Nexora Commerce 3.97.23 codebase map for the spec

Root: `C:\Users\Владимир\Documents\Codex\nexora_live`. Paths below are relative to it.

Two things to know first:
- Several features the spec may ask for already exist: the category product count, status switches on both lists, inline price edit, hint icons, the colour menu and the brand-name switch. Sections 1, 2 and 4 to 6 say exactly what is missing.
- There are two files named `admin-runtime.js`:
  - `assets/admin/admin-runtime.js` (389 lines) holds the AI helpers, translation editor, campaigns and ajax-delete.
  - `assets/storefront/admin-runtime.js` is the big admin runtime. It holds quick price, status and order, tabs, tips, toast and the image queue. It imports the former on its first line (`import '../admin/admin-runtime.js'`).
  - Admin i18n keys reach JS through `window.MC_I18N`. `_shell.html.twig:138` emits `ui_catalog_json(['js_','vue.','builder_','admin.ai.','admin.seo.'])`, so any new JS key must start with one of those prefixes.

---

## 1. ADMIN CATEGORY LIST

**Route and controller**
- Route `admin_catalog_categories`, `GET /admin/catalog/categories`.
- Controller: `src/Modules/Admin/Http/CatalogAdminPageController.php:95-106`, method `categories()`.
- With no `?search`, it renders a **tree** via `DbalCatalogAdminQuery::categoryTree($storeId,$locale)` and `is_tree=true`.
- With a search term, it renders a **flat paged list** (25 per page) via `DbalCatalogAdminQuery::categories($storeId,$locale,$page,25,$search)`.
- Template variables: `result{items,total,page,limit}`, `search`, `is_tree`, `category_path_in_url`, `description_position`.

**Template**
- `themes/default/templates/admin/catalog/categories.html.twig`, 10 lines. It is a single-line-per-block Twig file, and the table is on line 8.
- Columns: Category (thumb, name, `public_id`), Parent, Products (`{{ item.product_count|default('—') }}`), Status, Order, Actions.
- Row attributes: `data-cat-row data-cat-id data-cat-node data-cat-parent data-cat-depth`. `draggable="true"` is set when it is a tree and `admin_can('catalog.manage')`.
- Table attributes: `data-category-tree data-move-url data-move-token data-move-failed`.
- The status cell shows the badge `admin-badge is-success|muted`, followed by this switch:

```twig
<label class="admin-switch admin-quick-status" data-quick-status data-url="{{ path('admin_catalog_category_quick_status',{publicId}) }}" data-token="{{ csrf_token('admin_quick_status') }}" data-on="active" data-off="inactive" data-label-on=".." data-label-off=".." data-off-tone="muted"><input type="checkbox" role="switch" ...></label>
```
- Order cell: `<span class="admin-quick-order" data-quick-order data-url data-token="{{ csrf_token('admin_category_quick_order') }}"><input type="number" data-quick-order-input data-original>`.
- Delete form: `admin_catalog_category_delete`, with `data-confirm`.

**Query: `src/Modules/Catalog/Infrastructure/DbalCatalogAdminQuery.php`**
- `categoryTree()` is at lines 289-335. It selects `c.id,c.public_id,c.parent_id,c.status,c.sort_order,ct.name`, plus `(SELECT COUNT(*) FROM mc_product_category pc WHERE pc.category_id=c.id) AS product_count`, plus `image_key` from `mc_category_image` and `mc_media_asset`.
- It joins `mc_store_category sc` and `mc_category_translation ct` (store and locale), orders by `c.sort_order,c.id`, and limits to 2000.
- It then adds `depth`, `parent_name` and `children` in PHP, with a depth cap of 12.
- **`product_count` counts only products attached directly to the category, not its subtree.**
- `categories()` (flat, lines 271-281) selects `c.id,public_id,parent_id,status,sort_order,ct.name,image_key` and **has no `product_count`**. In search mode the column therefore shows `—`.
- The product filter uses `categories($storeId,$locale,1,100,'')['items']` (line 92 of the controller), so it caps at 100 categories.

**Tables (`resources/database/mysql/001_core_schema.sql`)**
- `mc_category` (line 105): `id, public_id BINARY(16), store_id, parent_id NULL, status VARCHAR(32) 'active'|'inactive', sort_order, created_at, updated_at`.
- `mc_category_translation` (line 122): `category_id, store_id, locale`, with `name, h1, description, description_bottom, meta_title, meta_description, slug`. Migration `Version20261204130000` added `is_fallback`.
- `mc_product_category` (line 139):
  - Columns: `product_id, category_id, is_primary TINYINT, sort_order`.
  - Primary key `(product_id, category_id)`, plus index `idx_product_category_category_sort (category_id, sort_order, product_id)`.
  - Foreign keys use `ON DELETE CASCADE`.
- Also involved: `mc_store_category (store_id, category_id, status)` and `mc_category_image (category_id, asset_id)`.
- A recursive CTE over the subtree already exists in `src/Modules/Search/EventSubscriber/CatalogSearchIndexSubscriber.php:50`. `CategoryTreeIndex::scope($categoryId,$storeId)` returns the branch ids; it is used in `DbalStorefrontCatalogQuery`.

**Other category routes** (all in `CatalogAdminPageController`)
- `admin_catalog_category_url_settings` (POST, CSRF id `admin_category_url_settings`).
- `admin_catalog_category_quick_status` (POST `/admin/catalog/categories/{publicId}/quick-status`, CSRF `admin_quick_status`; it calls `CategoryWriter::setStatus`).
- `admin_catalog_category_quick_order` (CSRF `admin_category_quick_order`).
- `admin_catalog_category_move` (CSRF `admin_category_move`).
- `admin_catalog_category_new`, `admin_catalog_category_edit` and `admin_catalog_category_delete`.

---

## 2. ADMIN PRODUCTS LIST

**Route and controller**
- Route `admin_catalog_products`, `GET /admin/catalog/products`, `CatalogAdminPageController::products()` at lines 62-93.
- Template: `themes/default/templates/admin/catalog/products.html.twig` (26 lines, one block per line).
- Query: `DbalCatalogAdminQuery::products($storeId,$marketId,$locale,$page,25,$search,$sort,$dir,$filters)` at lines 60-123.
- Query behaviour:
  - Filters are `status[]` (`draft|published|archived`), `category[]` and `brand[]`. They are parsed with `AdminFilterValues::list`.
  - Sort keys are `name, sku, status, price, type, updated`.
  - Search uses `mc_search_document` through the search index, plus LIKE on name, sku, gtin, mpn and brand.
  - Each row carries `id, public_id, status, product_type, brand_id, name, sku, gtin, brand_name, amount_minor, currency, image_key` and `quality{image,seo,gtin,content}`.
  - The row is the product's **first variant** (`v.sort_order=0`).
  - **Stock is not selected, so the list shows no stock column.**
- Columns are set by the `columns[]` query parameter. The allowed set is `['name','sku','status','price','type','quality']`.
- Columns persist in `mc_admin_saved_view (store_id, admin_id, entity_type='products', name, filters_json, columns_json, is_default)`.
  - Save route: `admin_catalog_saved_view_save`, CSRF field `_csrf_token`, id `admin_saved_view_save`.
  - Delete route: `admin_catalog_saved_view_delete`.
- Column header attribute is `data-sort-key`.

### 2a. Bulk actions
- The form wraps the whole table and is shown only when `admin_can('catalog.bulk')`:

```twig
<form method="post" action="{{ path('admin_commerce_products_bulk') }}"><input type="hidden" name="_csrf_token" value="{{ csrf_token('products_bulk') }}">
<div class="admin-bulk-actions"><select name="action" required>
  options: "" | publish | draft | archive | duplicate </select>
<button type="submit" class="admin-button" data-confirm="...">
<button type="submit" formaction="{{ path('admin_catalog_products_bulk_edit') }}" ...>
```
- Row checkboxes are `<input type="checkbox" name="product_ids[]" value="{{public_id}}" data-row-check>`. The select-all box is `data-check-all`, wired in `assets/admin/admin-runtime.js:3-4`.
- Handler: `src/Modules/Admin/Http/CommerceOperationsAdminController.php:279-293`, `bulkProducts()`, route `admin_commerce_products_bulk`, POST `/admin/commerce/products/bulk`.
  - CSRF id `products_bulk`, field `_csrf_token`.
  - `publish|draft|archive` map to `published|draft|archived` and call `Commerce\Modules\Bulk\Application\ProductBulkActionService::setStatus($storeId,$marketId,$locale,$ids,$status)`.
    - Cap: 200 ids. It loops `ProductBulkEditor`-style: `ProductEditQueryInterface::productForEdit` then `ProductWriter::update(UpdateProductCommand…)`.
    - It returns `{requested,updated,failed,errors[]}`, and the controller turns that into flashes.
  - `duplicate` calls `CatalogMaintenanceService::duplicateProductDraft` (max 50 ids). It flashes the translation key `admin.catalog.bulk.duplicated` with `count` and `failed`.
  - A missing or unknown action flashes an error and redirects to `admin_catalog_products`.
- Permissions: `AdminPermissionSubscriber.php:74` maps the routes `admin_commerce_products_bulk` and `admin_catalog_products_bulk_edit` to `AdminPermissionCatalog::CATALOG_BULK` (`'catalog.bulk'`).
  - There is no "delete" bulk action.
  - There is no undo for status changes. There is no per-action audit beyond the generic "admin-activity-audit" capability.
- Bulk table editor: route `admin_catalog_products_bulk_edit`, GET and POST `/admin/catalog/products/bulk-edit`, in `ProductWorkspaceAdminController.php:19-33`.
  - Template `admin/catalog/bulk_edit.html.twig`. Editable fields per row: `rows[publicId][name|sku|price|stock|status]`.
  - The save marker is `bulk_editor_save`, CSRF id `products_bulk_editor`, field `_csrf_token`.
  - It calls `Commerce\Modules\Catalog\Application\ProductBulkEditor::apply` (max 100 rows in the view). That returns `{ok, failed[], previous[]}`.
  - **Undo exists for this path**:
    - `AdminUndoService::remember($storeId,$adminId,'product_bulk',$label,['rows'=>$previous])`.
    - It is restored by `UndoAdminController`, route `admin_undo` `POST /admin/undo/{id}`, CSRF id `admin_undo`. That controller calls `ProductBulkEditor::apply` with the stored rows and requires `catalog.bulk`.
    - Undo rows: `src/Modules/Admin/Undo/AdminUndoService.php` (`remember`, `latest`, `claim`, `purgeExpired`, TTL constant).
    - The label key is `admin.undo.product_bulk`.

### 2b. Inline quick price edit (full mechanism)
- **Markup** (products.html.twig line 21), shown only with `admin_can('catalog.manage')`:

```twig
<span class="admin-quick-price" data-quick-price data-url="{{ path('admin_catalog_product_quick_price',{publicId:item.public_id}) }}" data-token="{{ csrf_token('admin_quick_price') }}">
  <input type="text" inputmode="decimal" form="quick-price-none" value="{{ amount_minor/100|number_format(2,'.','') }}" data-quick-price-input data-original="{{ same }}"><small>{{ item.currency }}</small></span>
```
- **JS**: `assets/storefront/admin-runtime.js`, `initQuickPrice()` at lines 593-629.
  - The save runs on `change` and on Enter. Escape resets the value.
  - The regex is `/^\d{1,9}(\.\d{1,2})?$/` and a comma is replaced by a dot.
  - Invalid input calls `input.setCustomValidity(t('js_price_invalid')); reportValidity()`.
  - It POSTs `URLSearchParams{_token,price}` to `data-url` with `Accept: application/json`.
  - On success it sets `input.value` and `dataset.original` from `data.price`, toggles the `is-saved` class for 1500 ms, and calls `toast(t('js_price_saved'),'success',2000)`.
  - On failure it restores the original value and shows `toast(t('js_price_failed'),'error',4000)`.
  - The `is-saving` class is added while busy.
- **Endpoint**: route `admin_catalog_product_quick_price`, `POST /admin/catalog/products/{publicId}/quick-price`, in `ProductWorkspaceAdminController.php:62-73`.
  - CSRF id `admin_quick_price`, field `_token`.
  - It validates `/^\d{1,9}(?:\.\d{1,2})?$/D` after replacing a comma with a dot.
  - It calls `ProductBulkEditor::apply($storeId,$marketId,$locale,$currency,[$publicId=>['price'=>$raw]])`. Under the hood that does `ProductWriter::update(UpdateProductCommand)` with all other fields re-fed from `productForEdit`, so the update also rewrites stock and categories as they are.
  - It records undo with `AdminUndoService` (kind `product_bulk`).
  - Responses: `{ok:true,price:"1249.90"}`, or 403 `{ok:false}`, or 422 `{ok:false,error:'format'|'save'}`.
- **i18n keys** in `resources/translations/{en-US,uk-UA,…}/storefront.php` around lines 1250-1262: `js_price_saved`, `js_price_failed`, `js_price_invalid`, `js_status_saved`, `js_status_failed`, `js_status_published`, `js_status_draft`, `js_order_saved`, `js_order_failed`, `js_order_invalid`.
- **CSS**: `assets/admin/admin-runtime.css:1702-1706` (`.admin-quick-price`, `.is-saving`, `.is-saved`).
- The row-click-to-open handler skips the quick controls: `skip = '… [data-quick-status], [data-quick-order], [data-quick-price] …'` at `assets/storefront/admin-runtime.js:1302`.

### 2c. Status column
- The badge is `admin-badge is-success|warning|muted` (published, draft, archived). The title text comes from the keys `admin.catalog.products.opublikovano|chernetka|arkhiv`.
- The toggle is **already present**: `<label class="admin-switch admin-quick-status" data-quick-status data-url="{{ path('admin_catalog_product_quick_status',{publicId}) }}" data-token="{{ csrf_token('admin_quick_status') }}"><input type="checkbox" role="switch">`.
  - It is shown for `catalog.manage` and `status != 'archived'`.
  - Defaults when `data-on`/`data-off` are absent are `published` and `draft`.
  - Optional attributes are `data-label-on`, `data-label-off`, `data-on-tone` and `data-off-tone`.
- JS: `initQuickStatus()`, `assets/storefront/admin-runtime.js:731-759`.
  - It POSTs `{_token,status}`.
  - It updates the sibling `.admin-badge` (class and text) and toasts `js_status_saved`.
  - On error it reverts `input.checked` and toasts `js_status_failed`.
- Endpoint: `admin_catalog_product_quick_status`, `ProductWorkspaceAdminController.php:49-59`.
  - It accepts `published|draft` only, calls `ProductBulkActionService::setStatus`, and returns `{ok,status}`.
  - CSRF id `admin_quick_status` (shared with the category switch).
- Switch CSS (the "switch component"): `assets/admin/admin-runtime.css:305-329`, class `.admin-switch` (`> input` with `appearance:none`, an `::after` knob, and `:checked` using `--mc-color-primary-fill`). It is also used in forms as `<label class="admin-switch admin-form-wide"><input type=checkbox role=switch name=…>`.

### 2d. Stock
- **In the list**: not displayed.
- **Edit and bulk-edit forms**: field `stock_quantity` (product form, `product_form.html.twig` line 32). Bulk editor: `rows[id][stock]`.
- **Read**: `DbalCatalogAdminQuery::productForEdit` line 131, and `variantsForEdit` line 183, both use this subquery:

```sql
(SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id ORDER BY mil.priority ASC, sl.location_id ASC LIMIT 1) AS stock_quantity
```
- **Tables** (`resources/database/mysql/003_commerce_model_v3.sql`):
  - `mc_inventory_location (id, public_id, store_id, code, name, status)`.
  - `mc_market_inventory_location (market_id, location_id, priority)`.
  - `mc_inventory_item (id, public_id, sku UNIQUE, unit_code, requires_shipping)`.
  - `mc_variant_inventory_item (variant_id, inventory_item_id, required_quantity DECIMAL(18,6))`.
  - `mc_stock_level (id, inventory_item_id, location_id, stocked_quantity, reserved_quantity, incoming_quantity, safety_stock DECIMAL(18,6), row_version BIGINT, updated_at; UNIQUE(inventory_item_id,location_id))`.
  - `mc_inventory_reservation (inventory_item_id, location_id, cart_id, order_id, idempotency_key, quantity, status, expires_at, released_at)`.
  - The legacy `mc_inventory` was dropped.
  - Also `mc_product_variant (manage_inventory, allow_backorder, sort_order, …)`.
- **Writers**:
  - `Commerce\Modules\Catalog\Application\ProductWriter::update` (about lines 240-290):
    - `UPDATE mc_stock_level SET stocked_quantity, row_version+1, updated_at WHERE inventory_item_id, location_id` for the **market's first location only** and for physical products only.
    - It also does `UPDATE mc_inventory_item SET sku, unit_code`.
  - `ProductWriter::create` inserts `mc_stock_level` (around line 92).
  - Switching a product to physical creates the item and a zero row (lines 124-170).
  - `ProductVariantService::create/update/delete` (`src/Modules/Catalog/Application/ProductVariantService.php`) does the same per variant. The product form posts `variant[<id>][sku|stock…]`.
  - `UpdateProductCommand` carries `stockQuantity` as a string with six decimals.
  - Reservations and settlement: `Inventory/Infrastructure/DbalInventoryReservationService.php`; also `Order/Application/CheckoutOrderService.php`, `Payment/Application/PaymentLifecycleService.php`, `Supplier/Application/SupplierService.php` and `ImportExport/Application/CatalogCsvService.php`.
- **There is no stock-movement or history log table**: grep for movement, history, log or adjust in `src/`, `resources/database` and `migrations` found none. There is only `row_version` on `mc_stock_level`.
- Products with variants: the list shows only the first variant (`v.sort_order=0`), so its price, sku and stock stand in for the product.

---

## 3. AI ASSIST UI

**Backend** (`src/Modules/Ai/Http/AiAdminController.php`)
- `admin_ai_task`, `POST /admin/api/ai/task`, CSRF id `admin_ai`. It takes `task`, `provider` and `fields[...]`, and returns `{ok,fields:{…}}`.
- `admin_ai_product_from_photo`, `POST /admin/api/ai/product-from-photo`, multipart, same CSRF.
- `admin_ai_product_draft`, `POST /admin/api/ai/product-draft`, CSRF id `admin_ai_product`. It returns `{ok,short_description,description}`.
- Permission: `AdminPermissionSubscriber.php:119` maps `admin_ai_product_draft` and `admin_ai_task` to `catalog.manage`.
- `src/Modules/Ai/Application/AiTaskService.php:22-29` `TASKS` (output fields in order):
  - `product_draft` (in `name`; out `short_description`, `description`).
  - `product_from_photo` (out `name`, `brand`, `short_description`, `description`, `attributes`).
  - `category_text` (in `name`; out `top`, `bottom`).
  - `seo_meta` (in `title`; out `meta_title`, `meta_description`).
  - `translate` (in `text`, `target`; out `text`; chunked by `TranslationChunker`, 6000 characters).
  - `reply` (in `message`; out `reply`).
- Twig function `ai_providers()` is in `src/Modules/Ai/Twig/AiTwigExtension.php`. Items have `code`, `label` and `translation_only`.

**Usage list**

| Place | Where | Input selectors | Output selectors |
|---|---|---|---|
| `_ai_box` `seo_meta`, product | `admin/catalog/product_form.html.twig:27`, end of the `data-product-tab="seo"` panel | `{title:'[name=name]', body:'[name=description]'}` | `{meta_title:'[name=meta_title]', meta_description:'[name=meta_description]'}` |
| `_ai_box` `category_text` | `admin/catalog/category_form.html.twig:15` | `{name:'[name=name]', current:'[name=description]'}` | `{top:'[name=description]', bottom:'[name=description]'}` (both go into ONE field) |
| `_ai_box` `seo_meta`, page | `admin/content/page_form.html.twig:24` | `title:[name=title]`, `body:[name=body_html]` | `meta_title`, `meta_description` |
| `_ai_box` `seo_meta`, blog | `admin/content/blog_form.html.twig:19` | same as page | same |
| `data-ai-product-draft` | `product_form.html.twig:21`, inside the general tab after `description` | the form's `[name=name]`, `sku`, `short_description`, `description`, `brand_id`, `category_ids[]:checked`, `attribute[…]` | `[name=short_description]`, `[name=description]` inside the form |
| `_ai_photo` | `product_form.html.twig:20` and `product_quick.html.twig:7` (`{quick:true}`) | file `[data-ai-photo-file]` or fallback `[data-ai-photo-source]` (the quick page's `images[]` input) | document-wide `[name=name]`, `[name=short_description]`, `[name=description]`; notes into `[data-ai-photo-notes]` |
| `data-ai-playground` | `admin/system/ai.html.twig:28` | `[data-ai-play-task]`, `[data-ai-play-input]`, `[data-ai-play-target]` | `[data-ai-play-output]` |
| reviews and questions (ids `ai-msg-q{id}`, `ai-out-q{id}`) | `admin/customer_experience/index.html.twig:17-18` | `<input type=hidden id="ai-msg-q{id}">` | `textarea#ai-out-q{id}` |

- Translation pages use `data-translate-page`, `data-translate-panel`, `data-translate-field`, `data-translate-one`, `data-translate-all`, `data-translate-from` and `data-save-all`. They are in `admin/catalog/translations.html.twig`, `content/form_edit.html.twig` and `appearance/navigation.html.twig`.

**`_ai_box.html.twig` markup**:
- It renders `<div class="admin-ai-helper [is-compact]" data-ai-box data-ai-token="{{ csrf_token('admin_ai') }}">…<select data-ai-provider>…<button type="button" data-ai-task="{{task}}" data-ai-in='{json}' data-ai-out='{json}'><span data-ai-status role="status">`.
- It renders nothing when there are no non-`translation_only` providers.

**JS** (`assets/admin/admin-runtime.js`)
- Product draft: lines 6-44.
  - Input values come from `form.querySelector('[name="…"]')`.
  - Outputs are written with `field.value = value; field.dispatchEvent(new Event('input',{bubbles:true}))` at lines 34-36.
  - Then `status.textContent = t('admin.ai.draft_inserted')` at line 37.
- Generic box: lines 46-79.
  - Inputs are read with `document.querySelector(selector)?.value`.
  - The write loop is lines 64-71. Outputs sharing a selector are joined with `\n`. `const field = document.querySelector(selector); if (field) {…}` has **no else branch**.
  - Line 72 sets "inserted" unconditionally.
- Photo: lines 82-117. The local `set(selector,value)` helper at lines 99-102 silently skips a missing field.
  - Line 110 is the unconditional `draft_inserted`.
  - Brand and attributes only go into the notes `<pre>`, not into form fields.
- Playground: lines 120-147 (writes `output.value`).
- Translate editor: lines 150-304.
- **Cases where the status says "inserted" but nothing was written**:
  - A selector that matches no element (typo, element not on the page) is skipped without any signal at lines 69-71 and 99-102. The status is set anyway at 72 and 110.
  - `data.fields[name]` that is an empty string is skipped (line 66), yet the status is still "inserted".
  - `product-draft` writes only when `value` is truthy (line 35) and still reports success.
  - `_ai_photo` and the photo `set()` use global `document.querySelector('[name=name]')`, which returns the **first** match on the page. On the product edit page, a different `[name=name]` earlier in the DOM would receive the value (for example the option or addon create forms; verify).
  - `product_quick.html.twig` has no `data-ai-product-draft` or `_ai_box`, only photo.
  - Category form: `top` and `bottom` both target `[name=description]`. The category editor's single field is `description`, and it holds `description ~ description_bottom` concatenated at render time (see the template `category_form.html.twig`).
  - `category_form` has a single `[name=description]`, but `CatalogAdminPageController` line 318 also reads `description_bottom`. The form no longer has that field.
  - The SEO box on the product form is **inside the same `data-product-tab="seo"` panel as its outputs**, so it is visible. The product-draft and photo boxes live in the `general` tab, while photo and draft outputs (`name`, `short_description`, `description`) are also in `general`.
  - Hidden-tab risk: a box in one tab whose selector points into another tab still writes (it uses `value` on a `hidden` panel), but the user sees no change. The SEO box output stays valid because the panel is in the DOM.
- Rich-text sync: `assets/admin/main.ts:5-50`.
  - `textarea[data-rich-editor]` is hidden (`field.hidden=true`) and a Vue `RichTextEditor` is mounted after it.
  - The textarea stays the source of truth. A programmatic `field.value=…` plus `dispatchEvent(new Event('input',{bubbles:true}))` updates the visual editor (listener at lines 28-30).
  - Without the `input` event the visual editor keeps showing the old text. This was fixed in HEAD commit `2aaa34d2`.

**Admin tabs mechanism** (`assets/storefront/admin-runtime.js`)
1. Generic `data-tabs` (`initTabs`, lines 1328-1406):
   - Root `<div data-tabs="name" [data-tabs-flex]>`. The panels are direct children carrying `data-tab="id"`, with optional `data-tab-icon`, `data-tab-label` and `data-tab-count`.
   - Buttons are `.admin-tab[data-tab-target=id]`; the active panel is shown through the `hidden` attribute.
   - The active tab is stored in `sessionStorage` key `mc_tab_<pathname>_<tabsName>`.
   - `localStorage['mc_admin_tabs']` is `'all'` or `'tabs'`. It is the "show all sections" toggle (`.admin-tab--all`, only for `data-tabs-flex`).
   - A hash that targets an element inside a hidden panel opens that panel (lines 1395-1402).
   - `initAutoTabs` (1285-1295) wraps 4 or more direct `section.admin-panel` children in `main.admin-content` into `data-tabs="auto"`. Opt out with `data-no-auto-tabs` on main.
   - CSS: `assets/admin/admin-runtime.css:1572` `[data-tabs] > [data-tab][hidden]{display:none}`.
2. Product form tabs (`initProductTabs`, lines 1408-1464):
   - `form#product-form[data-product-tabs='{"general":"…","sales":…,"media":…,"details":…,"files":…,"seo":…}']`. The panels are `section[data-product-tab="id"]`.
   - The session key is `mc_product_tab_<pathname>`.
   - On an `invalid` event it activates the tab that holds the invalid field (line 1450).
   - On `hashchange` and at start it activates the tab containing `document.getElementById(hash)` (`fromHash`). That is the only built-in way to "open the tab containing an element".
   - There is no exported function. A spec that needs to reveal an element programmatically can either set `location.hash='#<element id>'` (works only for elements with an `id` inside a `[data-product-tab]` panel), or dispatch `invalid` on the element, or click `.admin-tab[data-tab-target=<id>]` found through `el.closest('[data-product-tab]').dataset.productTab`.
   - CSS: `admin-runtime.css:1672` `[data-product-tab][hidden]{display:none}`.
3. Storefront product page tabs (`assets/storefront/product-page.js:526-585`, `[data-tab]`, `[data-tab-panel]`) are unrelated.

---

## 4. RICH TEXT EDITOR COLOUR MENU (already implemented)

`assets/admin/components/RichTextEditor.vue` (517 lines)
- Constants: `POPULAR` at line 223, a **24-colour** list from `#000000` to `#ff6d00`.
- `hexDraft` ref at line 224. `hexOk` (line 225) accepts `#?` followed by 3, 6 or 8 hex digits. `normHex` (226-230) lowercases and expands the 3-digit form.
- `toggleColorMenu(kind:'text'|'bg')` at lines 231-234. It seeds `hexDraft` from `textStyle('color')` or `textStyle('backgroundColor')`, then toggles `colorMenu`.
- `applyColor(value)` at lines 235-242:
  - `editor.chain().focus().setColor(color)` for text or `.setBackgroundColor(color)` for background.
  - Then `rememberColor(kind,color)` and `colorMenu.value=''`.
- `submitHex` at line 243, `onNativeColor` (244, for `<input type=color>`), `clearColors` (245: `unsetColor` and `unsetBackgroundColor`).
- `colorMenu = ref<'' | 'text' | 'bg' | 'emoji'>('')` at line 304. The same ref drives the emoji menu.
- `closeMenus` (mousedown listener) closes the menus when the target is outside `.rich-editor__menu-host`.
- Recent colours:
  - Storage key `RECENT_KEY = 'mc_editor_recent_colors'` (line 314), stored in `localStorage` as `{text:[],bg:[]}`.
  - Entries are validated with `/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/`. Max 12 per kind. `recentColors = reactive(loadRecent())`.
  - `rememberColor` at lines 323-327 prepends and de-duplicates.
- Template (lines ~360-380):
  - Buttons `.rich-editor__colorbtn` (icons `Palette` and `PaintBucket`) with `aria-expanded`.
  - Panel `<div class="rich-editor__colorpanel" role="group">`, with `<strong>` as the title.
  - `.rich-editor__swatchgrid` holds `button.rich-editor__swatch` per `POPULAR` colour.
  - Hex row `.rich-editor__hexrow` has `.rich-editor__hexpreview`, `input.rich-editor__hex[maxlength=9][placeholder="#RRGGBB"]` (Enter submits) and `button.rich-editor__hexok`.
  - Native picker is `label.rich-editor__nativepick > input[type=color]`.
  - The recent-colour section is another `.rich-editor__swatchgrid`, shown with `v-if` when the list is non-empty.
  - i18n through `tr('text_color'|'bg_color'|'color_hex'|'color_pick'|'apply'…)`.
- CSS: `assets/admin/rich-editor.css` lines 126-139:
  - `.rich-editor__colorgroup` (126), `.rich-editor__colorbtn` (127-128), `.rich-editor__colorbar` (129).
  - `.rich-editor__colorpanel` (130, absolute, `top: calc(100% + var(--mc-space-1))`, width 15.5rem).
  - `.rich-editor__swatchgrid` (131, 8 columns, gap 4px).
  - `.rich-editor__colorpanel .rich-editor__swatch` (132, `aspect-ratio:1`).
  - `.rich-editor__hexpreview` (134, checkerboard background), `.rich-editor__hex` (135), `.rich-editor__hexok` (136), `.rich-editor__nativepick input[type="color"]` (139).
  - Older leftover swatch rules at lines 74-117 (`.rich-editor__color`, `.rich-editor__swatches`, `.rich-editor__swatch`) are for the previous compact UI.
- Last touched in `df36e632` and `2aaa34d2`.

---

## 5. STORE NAME / LOGO ON THE STOREFRONT

**Where the header renders it: `themes/default/templates/base.html.twig`** (there is no `partials/` directory)
- Line 6:

```twig
{% set storefront_brand_name = presentation.brand.title ?: store_identity.name ?: store_name|default(ui_text('store')) %}
```
  - `presentation = storefront_presentation()`, `store_identity = storefront_identity()`.
- Header at line 102:

```twig
<a class="reference-brand" href="/" aria-label="{{ storefront_brand_name }}{% if brand.subtitle %} {{ subtitle }}{% endif %} — {{ ui_text('home') }}">
 {% if brand.logo %}<img class="reference-brand__logo" src="{{ brand.logo }}" alt="{{ storefront_brand_name }}">
 {% elseif brand.icon %}<img class="reference-brand__icon" …>
 {% else %}<span class="reference-brand__bag">…shopping-bag icon…</span>{% endif %}
 <span>{% if presentation.brand.show_title|default('1') != '0' %}<strong>{{ storefront_brand_name }}</strong>{% endif %}<small>{{ presentation.brand.subtitle }}</small></span></a>
```
- The `show_title` switch from commit 7eebddba hides only the visible `<strong>`. It does **not** hide:
  - The subtitle `<small>` (shown at the mobile breakpoint only, `assets/storefront/parts/chrome.css:228`).
  - The `aria-label`.
  - The logo `alt`.
- With no logo and no icon, the shopping-bag placeholder shows even when the title is hidden.

**The setting**
- Key `brand.show_title` (`'0'` or `'1'`), stored in the appearance settings.
- `src/Modules/Appearance/Infrastructure/StorefrontPresentationSettings.php`: default `'show_title'=>'1'` at line 128, normalised at line 229. Other `brand` keys: `title`, `subtitle`, `logo`, `icon`, `favicon`, `pwa`.
- Saved in `src/Modules/Admin/Http/AppearanceAdminController.php:46` from the POST field `brand_show_title`.
- Admin template `themes/default/templates/admin/appearance/storefront.html.twig` line 20 (tab `data-tab="header"`):
  - `_self.ltext('brand_title', ui_text('admin.appearance.storefront.nazva_brendu'), raw.brand.title, store_locales, 120)` and the same for `brand_subtitle`.
  - `_self.media_field('brand_logo', settings.brand.logo, ui_text('admin.appearance.storefront.logo'), ui_text('admin.appearance.storefront.logo_help'))`, then `brand_icon` and `brand_favicon`.
  - `<label class="admin-check-inline"><input type="checkbox" name="brand_show_title" value="1" …><span>{{ ui_text('admin.appearance.brand_show_title') }}<small>{{ ui_text('admin.appearance.brand_show_title.help') }}</small></span></label>`.
- `media_field` macro (line 1 of the template): `div.admin-media-field > label (label text, hidden input name=…[data-media-value], preview img[data-media-preview] or .admin-media-placeholder, <small>{{help}}</small>)`, plus buttons `data-media-choose="<name>"` and `data-media-clear="<name>"`.
  - Existing hint text: `admin.appearance.storefront.logo_help` = "The main horizontal or combined logo for the header and footer." (`resources/translations/en-US/admin.php:1936`).
  - There is **no size or aspect-ratio recommendation** in the hint.
  - `brand_show_title.help` is at line 2266 of the same file.

**Logo sizing CSS**
- `assets/storefront/storefront.css:273`: `.reference-brand__logo, .brand__logo { width:auto; max-height: var(--mc-control-height-md); max-width: 180px; object-fit: contain }`.
- `.reference-brand__icon` is at line 274. `.reference-brand strong` has ellipsis and nowrap (line 272).
- Landing page: `assets/storefront/parts/landing.css:8`, `.lp-brand img { max-height:34px; width:auto }`.
- Footer uses `.brand__logo` through `components/site_footer.html.twig:31`.

**Where "Nexora" can still appear**
- Name resolution: `presentation.brand.title` → `storefront_identity().name` (which is `StorefrontContext::storeName` = `mc_store.name`, from `src/Modules/Appearance/Twig/StorefrontPresentationExtension.php:170-181`) → `ui_text('store')` ("Store"). It is set at install by `InstallationSeeder.php:46` from the installer's store name.
- Footer: `components/site_footer.html.twig:31` shows the brand name in `<strong>` **regardless of `show_title`**, and `<small>` with the subtitle or `modern_ecommerce`. Line 53 is the copyright with `{brand}`. The footer tagline falls back to `ui_text('platform_tagline')`, "Fast, clear and secure commerce platform…". `landing.html.twig:8,50,52` also show the name.
- `<title>`: base.html.twig line 14, `seo_head.title ?: page_title ?: store_name ?: ui_text('store')`. Home uses `page_title = $context->storeName` (`src/Modules/Storefront/HomeController.php:53,136`). `og:site_name` (line 21) uses `storefront_brand_name`.
- JSON-LD Organization: `src/Modules/Seo/StructuredData/OrganizationCommerceBuilder.php:10`, `name = $merchant['name']`. It is built from `HomeController` (`homeStructuredData`) and `InformationPageController`.
- `manifest.webmanifest`: `src/Modules/Storefront/PwaController.php:26-62`.
  - `name` = `brand.title` or `$context->storeName`. It ignores `show_title`.
  - Icons fall back to `/assets/branding/nexora-mark-192.png`, `-512.png` and `-maskable-512.png` when no `brand.icon` is set.
- Static assets `/assets/branding/favicon-32.png`, `favicon-180.png` are used when no favicon is set (base.html.twig:23).
- Defaults that still say Nexora:
  - `src/Core/Store/StoreIdentitySettings.php:239`, `'name'=>'Nexora Commerce'`, used in `get()` when the DB read fails.
  - `themes/default/templates/email/base.html.twig:14`, `{{ store_name|default('Nexora Commerce') }}`.
  - `src/Modules/Admin/Security/AdminPasswordResetService.php:61` (`'store_name'=>'Nexora Commerce'`).
  - `NotificationTemplateAdminController.php:58` and `Quality/Application/DailyDigestService.php:88` (fallback `'Nexora'`).
  - `resources/pwa/sw.js` has a comment only.
- Admin chrome intentionally says Nexora (`_shell.html.twig:9,21`, `auth_base.html.twig:14`, `mfa_challenge.html.twig:7,14`, dashboard.html.twig:9, admin.php translation keys).

---

## 6. HINTS

- Macro file: `themes/default/templates/admin/_help.html.twig`. Import with `{% import '@storefront/admin/_help.html.twig' as help %}`. Macros:
  - `help.hint(text, link=null, link_label=null, example=null)` renders an info icon beside the field name. Markup: `<span class="admin-tip admin-tip--hint" data-admin-tip><button class="admin-tip__button" type="button" aria-label="{{ ui_text('admin.help.label') }}" aria-expanded="false">{{ ui_icon('info',14) }}</button><span class="admin-tip__pop" role="tooltip">TEXT [Example: <span class="admin-hint__example">…</span>] [link.admin-hint__link]</span></span>`.
  - `help.tip(text, link, link_label)` is the same without the `--hint` modifier, for tight places such as toolbars and table headers.
  - `help.field(label, hint, link, link_label)` renders `<span class="admin-field-label">label + tip</span>`.
  - `link_html` only allows `https://`, `http://` or `/`, opens in a new tab, and the default label is `admin.help.where_to_get`.
- **Usage**: put the macro **inside `<label>`** after the control, for example:

```twig
<label>{{ ui_text('admin.cw.phone') }}<input type="tel" name="phone" …>{{ help.hint(ui_text('admin.help.cw.phone'), null, null, '+380…') }}</label>
```
  - Pass already-translated text.
  - Used in `appearance/contact_widget.html.twig`, `appearance/navigation.html.twig`, `system/store.html.twig` (7 uses), `system/ai.html.twig`, `captcha`, `cron`, `extension_settings`, `fraud`, `indexnow`, `push`, `tracking`, `commerce/feeds`, `commerce/notifications` and `shipping/methods`.
  - `appearance/storefront.html.twig` and the product, category and list pages do **not** import `_help` yet.
- CSS: `assets/admin/parts/system.css:178-204`.
  - `.admin-hint` is a plain muted block line.
  - `.admin-tip`, `.admin-tip__button`, `.admin-tip__pop` (hover, focus-visible or `.is-open` shows it).
  - `label:has(> .admin-tip--hint){display:flex;flex-wrap:wrap}` places the icon next to the label text (`order:1`) and the control on its own line (`order:2; flex:1 1 100%`).
- JS: `assets/storefront/admin-runtime.js` around lines 1144-1160. `[data-admin-tip]` toggles `.is-open` on button click and closes the others.
- Translation key prefixes: `admin.help.*` (`admin.help.label`, `admin.help.where_to_get`, `admin.help.example`, `admin.help.recommended`, and per-field keys such as `admin.help.cw.phone`, `admin.help.tracking.ga4_link`), in `resources/translations/<locale>/admin.php`. Locales: `uk-UA`, `en-US`, `ru-RU`, `de-DE`, `pl-PL`, `da-DK`.
- Older "?" tooltips (`<details class="seo-tip">`): `_tip.html.twig` (keys `admin.tip.<key>.1..3`) and `_seo_tip.html.twig` (`admin.seo.rules.*`).
- Help pages: `src/Modules/Admin/Http/AdminHelpController.php`.
  - `admin_help` `GET /admin/help` and `admin_help_doc` `GET /admin/help/{slug}`.
  - Only files listed in `src/Modules/Admin/Help/HelpCatalog.php` `DOCS` are served. Slugs: install, update-and-restore, language-packs, sms, home-builder, backup-feeds-sitemap, security, extensibility, modules-quickstart, modules-lifecycle, developer-guide (`docs/DEVELOPER_GUIDE_UK.md`), sdk, slots, settings-schema, providers, examples (`docs/examples/extensions/README.md`).
  - Groups: `system`, `modules`, `developers`.
  - Rendered by `MarkdownLite` (`src/Modules/Admin/Help/MarkdownLite.php`, constructor takes a callable for rewriting links to `.md` basenames).
  - Templates: `admin/help/index.html.twig` and `admin/help/doc.html.twig`.
- A hint added to `docs/*.md` shows up in help only if the file is registered in `HelpCatalog::DOCS`.

---

## 7. BACKGROUND JOBS

**Cron**
- `commerce:cron:run` is `src/Core/Scheduler/Console/CronRunCommand.php`. Options are `--task=<code>` and `--force`. It is intended for one server cron entry every 5 minutes.
- `src/Core/Scheduler/CronRunner.php`:
  - `run(string $source ['cli','web','pseudo','manual'], ?string $task, bool $force, float $budgetSeconds, ?Application, ?callable $progress)`.
  - MySQL advisory lock `GET_LOCK('nexora_commerce_cron_runner',0)`, and `RELEASE_LOCK` in `finally`.
  - For each task: due check by `next_due_at`, per-task state upsert, run via the console `Application` (`ArrayInput` with `command` + `args`) or a closure `handler`.
  - It records the tick with `SystemSettingStore` key `cron.last_run`.
- State table `mc_scheduled_task_state(task_code PK, last_started_at, last_finished_at, last_status, last_message VARCHAR(1000), last_duration_ms, next_due_at, run_count, fail_count)`, migration `Version20260924233000`.
- Triggers:
  - Server cron through the CLI.
  - Web cron `GET|POST|HEAD /cron/{token}` (`CronWebController`, route `cron_web_run`, token `[a-f0-9]{40}`).
  - `PseudoCronSubscriber` on `KernelEvents::TERMINATE`: 60 s tick, 20 s budget, stamp file, skipped when a real cron ran within 600 s.
  - The admin button `admin_system_cron_run`.
  - Admin page `admin_system_cron`, GET `/admin/system/cron` (`CronAdminController`).
- Registry: `src/Core/Scheduler/ScheduledTaskRegistry.php`.
  - `all()` merges `core()` with `fromExtensions()`.
  - Entry shape (`core()`): `'code' => ['label'=>…,'command'=>'commerce:…','args'=>['--limit'=>200],'interval'=>seconds,'group'=>'critical|integration|marketing|catalog|maintenance|integrations','description'=>…]`.
  - Existing codes: events, notifications, integrations, webhooks, unpaid_orders, campaigns, marketing_automations, feeds, facets, search_index, currency_prices, indexnow, **import_images**, retention, quality, daily_digest, link_check, prro, marketplace_sync, supplier_sync, media_warm, media_gc, queue_purge.
- **To register a core periodic job**: add an entry to `core()` and write a `#[AsCommand(name:'commerce:…')]` `Command` with a `--limit` or `--max-seconds` option. Note that labels use `CanonicalUiText::get('scheduler.label.<code>')` and `scheduler.description.<code>`, but `import_images` shows plain English strings, which is allowed.
- Extension tasks: `TrustedExtensionRuntimeRegistry::registerTask($extensionCode,$task,['interval'=>int,'label'=>…,'description'=>…],callable $handler)` at line 38. The code becomes `extension.<code_with_underscores>.<task>` and the group is `extensions`. The manifest has a `scheduled_tasks` field.
- Other workers: `commerce:work` (events plus notifications, `src/Core/Runtime/Console/DeferredWorkCommand.php`), `commerce:notifications:work`, `commerce:integration:work`, `commerce:webhooks:work`, `commerce:campaigns:enqueue`, `commerce:marketing:automations`, and the queue health commands `commerce:queues:status` and `commerce:queues:purge`.
- **Messenger**: `symfony/messenger` is required, but `framework.yaml` only has `messenger: default_bus: messenger.bus.default`. There are no handlers (`AsMessageHandler` and `MessageBusInterface` appear nowhere in `src`) and no transports. All async work is DB-queue plus cron.

**"Job with progress" pattern to copy: the import image queue**
- Table `mc_import_image_queue` (migration `migrations/Version20261231140000.php`): `id, store_id, product_id FK CASCADE, url VARCHAR(1500), alt_text, role, sort_order, status 'pending|done|failed', attempts SMALLINT, error VARCHAR(255), created_at, processed_at`, with `KEY idx_import_image_status(status,id)`.
- Service `src/Modules/Migration/Application/ImportImageQueue.php`: `enqueue()`, `counts()` (`{pending,done,failed}`), `process($limit,$budgetSeconds)` (a bounded loop, `MAX_ATTEMPTS=3`), `retryFailed()`.
- CLI `commerce:import:images --limit=40` (`src/Modules/Migration/Console/ImportImagesCommand.php`), scheduled as `import_images` (300 s interval).
- Admin endpoint `MigrationAdminController::images_run`, route `admin_system_migration_images_run`, POST, CSRF id `migration_images`, field `_csrf_token`. It runs `process(15,25.0)` and returns `{ok:true,done,failed,pending,…counts}`.
- UI: `admin/system/migration.html.twig:47-51`.
  - `<section data-image-queue data-url data-token>` containing `<progress class="onboarding-bar" data-image-queue-bar max= value=>`, `<p data-image-queue-text data-template="…%done% %pending% %failed%">` and `<button data-image-queue-run>`.
  - JS: `assets/storefront/admin-runtime.js:3034+` (the "poll while pending" loop).
- Another progress shape: `mc_migration_run (public_id CHAR(36), source_code, status, entity_type, cursor_value, processed_count, issue_count, last_error, options_json JSON, created_at, updated_at, completed_at)`, migration `Version20260924060000`. It is resumable.
- Other queues: `mc_integration_sync_queue` (same migration), `mc_notification_outbox` (status, attempts, locked_at, lock_token, available_at), `mc_outbox_event`.

---

## 8. TRANSLATIONS DATA

`src/Modules/Catalog/Application/CatalogTranslationService.php` (`final readonly`)
- Constants:
  - `PRODUCT_FIELDS = ['name'=>255,'h1'=>255,'short_description'=>1000,'description'=>100000,'meta_title'=>255,'meta_description'=>500]`.
  - `CATEGORY_FIELDS = ['name'=>255,'h1'=>255,'description'=>100000,'description_bottom'=>100000,'meta_title'=>255,'meta_description'=>500]`.
  - `RICH = ['description','description_bottom']` (HTML sanitizer `html_sanitizer.sanitizer.commerce.rich_text`; the other fields go through `strip_tags`).
- Methods:
  - `locales($storeId)` returns `list<{code,name,is_default}>` from `mc_store_locale JOIN mc_locale WHERE enabled=1 ORDER BY sort_order`.
  - `defaultLocale()` reads `mc_store.default_locale`.
  - `productTexts()` and `categoryTexts()` return `locale => field => text` (only locales that already have a row).
  - `missingForProduct($storeId,$productId)` returns the locales without a name or description.
  - `saveProduct($storeId,$productId,$locale,$input)` and `saveCategory(...)`.
    - They assert the locale is enabled, require a non-empty `name` (`InvalidArgumentException('name_required')`), do an upsert, and **set `is_fallback=0`**.
    - They call `SeoUrlManager::ensureForCreatedEntity` and apply an optional `slug`. A product save publishes `PRODUCT_UPDATED` with `source=translation`.
  - `addresses()` (SEO paths per locale), `product($storeId,$publicId)` and `category(...)`.
- Fallback rows: `src/Modules/Localization/Application/TranslationFallbackFiller.php` (`fillProduct`, `fillCategory`, `backfillLocale($storeId,$target)`, called from `ProductWriter`, `CategoryWriter`, `LocalizationAdminController`).
  - It copies default-locale text into other enabled locales and marks them `is_fallback=1` (column added to `mc_product_translation`, `mc_category_translation` and `mc_brand_translation` by migration `Version20261204130000`).
  - A fallback row holds a copy of the source text, which is why `translateJobs` in JS treats "value equals the source" as untranslated.
- Enabled locales (other lists):
  - `DbalCatalogAdminQuery::storeLocales($storeId)` (`mc_store_locale.enabled=1`).
  - `StorefrontPresentationExtension::locales()`.
  - The admin context switcher (`admin_context_switcher()`, `AdminContextResolver`; the locale list is `context_switcher.locales`).
  - Tables: `mc_store_locale(store_id, locale_code, enabled, is_default, sort_order)` and `mc_locale(code, name, native_name, enabled)`.
- Controller `src/Modules/Admin/Http/CatalogTranslationAdminController.php`:
  - `admin_catalog_product_translations` GET `/admin/catalog/products/{publicId}/translations`.
  - `admin_catalog_category_translations` GET `/admin/catalog/categories/{publicId}/translations`.
  - `admin_catalog_product_translation_save` POST `/admin/catalog/products/{publicId}/translations/{locale}`.
  - `admin_catalog_category_translation_save` POST `/admin/catalog/categories/{publicId}/translations/{locale}`.
  - Locale requirement regex `[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?`. The `guard()` method checks CSRF.
  - XHR (`isXmlHttpRequest`) answers `{ok,message}` JSON, with 422 on `InvalidArgumentException`. Error keys are `admin.translations.error.<code>` and the success key is `admin.translations.saved`.
  - Template `admin/catalog/translations.html.twig`: `data-translate-page data-lang-scope data-ai-token`, with per-locale `section[data-translate-panel data-locale data-source(for default)]`. Fields have `data-translate-field`, and each language panel contains a `form[data-ajax-save]`. `?auto=1` auto-clicks "translate all".
  - Field labels use the keys `admin.translations.field.<field>`.
- AI translation call (in `assets/admin/admin-runtime.js` `translateText`): POST `/admin/api/ai/task` with `task=translate`, `fields[text]`, `fields[target]`, `fields[source]`, and `provider`. `AiTaskService` supports the languages `uk, ru, en, da, de, pl` (`LANGUAGES` const). Chunking is `TranslationChunker::split($text,6000)`. There is also `TranslationProviderRegistry` (provider with `translation_only`).
- Other translated entities (tables):
  - `mc_brand_translation` (has `is_fallback`).
  - `mc_attribute_translation (attribute_id, locale, name, unit_label)`.
  - `mc_content_translation` (information pages).
  - `mc_product_option_translation`, `mc_product_option_value_translation`.
  - `mc_blog_category_translation`, `mc_navigation_item_translation`.
  - `mc_measurement_unit_translation`, `mc_legal_document_translation`.
  - `mc_product_compliance_translation`.
  - `mc_store_contact_translation`.
  - Appearance and Storefront texts are stored per locale in the presentation JSON (`ltext()` macro, `store_locales`).
  - Forms (`content/form_edit.html.twig`, fields `translations[<locale>][…]`) and navigation (`appearance/navigation.html.twig`) already use the `data-translate-*` AI flow.

---

## 9. EXTENSION / MODULE DEVELOPER DOCS

**Docs (all in `docs/`)**

| File | Summary | Runnable code? |
|---|---|---|
| `EXTENSION_SDK.md` (130 lines) | SDK 1.1, Extension API 2.0, three models (`declarative`, `remote_app`, `trusted_release`), manifest, tasks, events | 4 code fences, mostly JSON and commands |
| `DEVELOPER_GUIDE.md` (220 lines, EN) | Supported starting point for third parties; choose model, manifest, settings, build | 4 fences |
| `DEVELOPER_GUIDE_UK.md` (105 lines) | Ukrainian guide (the one served in `/admin/help/developer-guide`) | 2 fences |
| `QUICKSTART_MODULE.md` (52 lines, UK) | 10-minute first module: scaffold, edit, pack, install, disable/update/rollback | commands inline (indented, no fences) |
| `EXTENSIBILITY.md` (50, UK) | What a module can change; types | none |
| `EXTENSION_LIFECYCLE.md` (13) | validate, stage, migrate, publish assets, activate; versions immutable; rollback | no |
| `EXTENSION_PROVIDER_CONTRACTS.md` (55) | Trusted provider contracts (payment, shipping, product page) via `TrustedExtensionContext` | 2 fences (PHP) |
| `EXTENSION_SETTINGS_SCHEMA.md` (17) | Settings schema v2, field types (`text, textarea, number, integer, boolean, select, url, email, secret, color, range, multilingual_text, multilingual_textarea`); meta-schema `extensions/settings.schema.json` | no |
| `EXTENSION_SLOTS.md` (46) | UI slots (`ExtensionPoint` enum; `commerce:extension:list-points`; guarded by `ExtensionSlotDocsTest`) | table |
| `MODULES.md` (13), `SYSTEM_MODULES.md` (9) | Built-in and protected system modules | no |
| `API_V1.md` (16) | Public REST `/api/v1` read-only endpoints | list |
| `EVENT_BUS.md` (11) | Transactional outbox `mc_outbox_event`; contracts in `resources/event-contracts/v1/` | no |
| `BONUS_EXTENSIONS_AND_THEMES.md` (19) | Describes `bonus/` | no |
| `COMMERCIAL_EXTENSIONS.md` (19) | `commercial.model`: free, one_time, subscription, external | 2 fences (JSON) |
| `INTEGRATIONS.md` (17), `ARCHITECTURE.md` (19), `WEB_PLATFORM.md`, `STOREFRONT_COMPONENTS.md` | Background | no |

**Examples**
- `docs/examples/extensions/` has `README.md`, `hello-settings/` (`manifest.json`, `settings.schema.json`, `translations/`), `product-badge/` (`blocks/`, `manifest.json`, `translations/`), `remote-crm/` and `trusted-order-hook/` (README only).
- `bonus/extensions/src/{nexora.product_trust, nexora.remote_crm_starter, nexora.store_health}` and `bonus/extensions/packages/*.zip` (`Product_Trust_Badge_v1.0.1`, `Remote_CRM_Connector_Starter_v1.0.0`, `Store_Health_Endpoint_v1.0.0`). `store_health` is a signed `trusted_release` example with PHP. `bonus/themes/{src,packages}` has the Atelier, Market and Soft themes.
- `docs/DEVELOPER_GUIDE*.md` and the README in `docs/examples/extensions/` are the only docs with copy-paste manifests.

**Console commands** (`src/Core/Extension/Console/`), all `php bin/console …`
- `commerce:extension:scaffold <code> --name=… --preset=basic|product-block|remote-integration|theme|trusted-route` writes to `var/extension-scaffold/<code>/` with `manifest.json`, `settings.schema.json`, `translations/{uk-UA,en-US}/messages.json` and `README.md`. The code format is `vendor.name` (lowercase, digits, `_`).
- `commerce:extension:pack <dir> --output=<zip>` (also validates), `commerce:extension:validate <zip>`, `commerce:extension:test <zip>`.
- `commerce:extension:keygen` and `commerce:extension:sign` (Ed25519, trusted packages).
- `commerce:extension:list-points`, `list-events` and `list-contracts`.

**Manifest**
- File `manifest.json` at the package root, schema `extensions/manifest.schema.json`.
- **Required**: `code, name, version, type, core, extension_api, permissions`.
- Optional properties: `api, php, data_policy, hot_disable, dependencies, scheduled_tasks, events, ui_slots, regions, isolation, execution, capabilities, settings_schema, remote, publisher, license, signature, healthcheck, migrations, uninstall_policy, localization, entrypoint, autoload, blocks, routes, pages, assets, slot_contributions, commercial`.
- Scaffold defaults: `type = 'app' | 'trusted-module' | 'theme'`, `core = '^<PlatformVersion::VERSION>'`, `extension_api = '2.0'`, `execution = declarative|remote_app|trusted_release`, `isolation = 'contract_only'`, `data_policy = 'retain_on_uninstall'`, and `localization{default_locale,locales,translations_path}`.
- A `trusted_release` package also needs `publisher{name,key_id}`, `signature{algorithm:'ed25519',key_id,file:'SIGNATURE.ed25519'}`, `autoload.psr4`, `entrypoint` and `migrations`. A bundled key id wins (`TrustedExtensionSignatureVerifier`). Trusted publishers: `config/extensions/trusted-publishers.json`.
- Admin install: Admin, then System, then Extensions (`/admin/system/extensions`).

---

## 10. RELEASE PACKAGING + VERSION

**Occurrences of `3.97.23` outside `vendor`, `node_modules`, `var` and `public/build`**
- `src/Core/Platform/PlatformVersion.php:9`, `public const VERSION = '3.97.23'`. It is the single PHP source of truth, and `tools/package-production.sh` reads it.
- `resources/platform/release.json:3` (`"version"`; also `database_schema "88"`, `schema_version 88`, `extension_api "2.0"`).
- `release-manifest.json:3` (nested `version`; `built_at_utc`, `schema 88`).
- `package.json:4` and `package-lock.json:3 and :9`.
- `config/theme/default.json:3`.
- `src/Modules/Demo/Application/DemoSeeder.php:255` (`'version' => '3.97.23'`).
- `README.md:1`, `README_EN.md:1`, `INSTALL_UK.txt:1` (headline).
- `CHANGELOG.md:3` ("## Unreleased (next after 3.97.23)") and `:65` ("## 3.97.23 — 2026-10-10").
- `docs/PRODUCT_GAP_ANALYSIS.md:3,37` (prose only).
- `tools/verify-packaged-release.php` requires `release.json.version == release-manifest.version == package.json.version`, and `schema` consistent across the files.
- `SHA256SUMS.txt` at the root is a checked-in file listing hashes (it even contains `.git/` entries). Do not regard it as a reliable artifact.
- `composer.json` has no version field (checked: no hit).

**Build tooling**
- `tools/build-production.sh`: the full pipeline. It installs Composer without dev, runs `npm ci`, typecheck, Vite production build, asset budgets and compression, the gates, and then calls `package-production.sh`. It is documented in `docs/PRODUCTION_BUILD.md`. Output: `build/Nexora_Commerce_vX.Y.Z_PRODUCTION.zip`.
- `tools/package-production.sh`: `VERSION` comes from `PlatformVersion::VERSION`, `OUT_DIR=build/production-$VERSION`, `ZIP=build/Nexora_Commerce_v${VERSION}_PRODUCTION.zip`.
  - It aborts when `vendor/phpunit` exists (dev packages).
  - It runs, in order: `bin/dependency-integrity-check.php`, `release-check.php`, `release-contract-check.php --production`, `system-completeness-check.php`, `full-release-static-check.php`, `i18n-critical-journey-check.php`, `i18n-runtime-hardcode-audit.php --strict`, `twig-syntax-check.php themes/default/templates`, `admin-access-check.php`, `admin-confirm-check.php`, `commerce-analytics-check.php`, `license-audit.php`, `catalog-scale-audit.php`, `production-critical-check.php`, `security-audit.php`, `security-regression-check.php`, `commerce-operations-check.php`, `commerce-ux-check.php`, `backup-feed-seo-check.php` and `demo-showcase-check.php`.
  - It rsyncs the tree (excluding `.git`, `node_modules`, `build`, `tests`, `phpunit.xml.dist`, `.github`, `.claude`, `var/*`, `.env`, `.env.local`, and so on), and runs `composer dump-autoload --no-dev --optimize --classmap-authoritative`.
  - It creates the empty `var/` skeleton with `.gitkeep`, and runs the leak checks.
  - Then `php tools/create-release-manifest.php "$OUT_DIR"` and `php tools/verify-packaged-release.php "$OUT_DIR"`.
  - Then `find . -type f ! -name SHA256SUMS.txt | sort | xargs sha256sum > SHA256SUMS.txt`, then `zip -qr "$ZIP" .`, then `sha256sum "$ZIP"`.
- `tools/create-release-manifest.php <dir>` writes `release-manifest.json` (`product, version, channel, built_at_utc, php, schema, contains_vendor, contains_compiled_assets, installer 'public/setup.php', document_root 'public/'`) from `PlatformVersion` and `resources/platform/release.json`.
- Also: `tools/prepare-locks.sh`, `certify-production.sh`, `bootstrap-production-online.sh`, `hosting-compat-check.sh`, `check-asset-budgets.mjs`, `precompress-assets.mjs` and `check-php86-readiness.php`.
- Composer scripts (`composer.json:95-121`): `test` (phpunit), `lint:php`, `audit:*`, `analyse` and so on. There is no release script. The npm scripts include `build:production` and `qa`.
- Windows note: the scripts are bash and need rsync, zip, sha256sum, composer, php and npm. AGENTS.md says 3 phpunit failures on Windows are environmental.
- **Bump procedure**, derived from the verify script:
  - Edit `PlatformVersion::VERSION`.
  - Edit `resources/platform/release.json` `version`.
  - Edit `package.json` and `package-lock.json` (two places).
  - Edit `config/theme/default.json`.
  - Edit `release-manifest.json`.
  - Edit `DemoSeeder.php:255`.
  - Edit the README headers, `INSTALL_UK.txt` and `CHANGELOG.md` (heading).
  - Rename the "Unreleased" section in `CHANGELOG.md`.
- A user-side skill `nexora-release-check` exists in the environment, but I did not invoke it.

---

## 11. ADMIN SIDEBAR MENU

- Defined in **Twig**, not a PHP registry: `themes/default/templates/admin/catalog/_shell.html.twig`, lines 21-58 (`<nav class="admin-nav" data-command-source>`).
- Items are rendered through the macro `nav.link(current, key, href, icon, label)` (defined around line 123 of the same file). It picks a tone (blue, green or other) based on the href prefix and marks the item active when `key == section`.
- Each template picks its section by calling `{{ shell.start(title, 'products') }}` (the second argument is the section key).
- Group labels are `<span class="admin-nav__label">`. The groups are Catalog, Sales, Marketing, Content and System.
- Each item is wrapped in `{% if admin_can('<permission>') %}`. Permissions include `dashboard.view`, `analytics.view`, `catalog.view`, `search.view`, `orders.view`, `customers.view`, `b2b.view`, `marketing.view`, `feeds.view`, `notifications.view`, `rewards.view`, `content.view`, `forum.view`, `appearance.view`, `media.view`, `system.settings`, `extensions.manage`, `integrations.manage`, `system.update`, `system.cron.view`, `system.audit.view`, `developer_tools.view`, `admin_users.manage`.
- 91 links match the pattern `nav.link(section, '<key>', '/admin…'` (some lines are very long and share several links).
- Enumerate every URL by running `grep -o "nav.link(section, '[a-z_]*', '/admin[^']*'" themes/default/templates/admin/catalog/_shell.html.twig`.
- Core catalog items:
  - Products `/admin/catalog/products` (key `products`).
  - Categories `/admin/catalog/categories` (key `categories`).
  - Suppliers `/admin/catalog/suppliers`.
  - Brands/attributes `/admin/catalog/metadata`.
  - Fields `/admin/catalog/fields`.
  - Units `/admin/catalog/units`.
  - Search `/admin/catalog/search`.
  - Merchandising `/admin/catalog/merchandising`.
  - Badges `/admin/catalog/badges`.
  - Import/export `/admin/commerce/import-export`.
- Footer links (outside the permission blocks): `/admin/account/security` (key `security`) and logout `POST /admin/logout` (CSRF id `logout`).
- The Ctrl+K quick-jump palette reads the same nav through `data-command-source` on the `<nav>`.
- The sidebar scroll position is stored in `sessionStorage` key `mc_admin_nav_scroll`.
- `context_sections` (around line 87) lists the sections for which the topbar store, market and language switcher is shown. Add the key of any new section page there if it needs the switcher.
- Extension-contributed menu items go through the manifest `ui_slots` and `slot_contributions`; see `admin/extension/slot.html.twig` and `docs/EXTENSION_SLOTS.md`.
- The admin UI itself is also translated in the keys `admin_*` (for example `admin_products`, `admin_categories`, `admin_system`) in `resources/translations/<locale>/admin.php` and `core.php`.

---

## Cross-cutting facts useful for the spec

- Permissions live in `src/Modules/Admin/Authorization/AdminPermissionCatalog.php` (`CATALOG_BULK='catalog.bulk'`, and so on) and `AdminPermissionSubscriber.php` (route name to permission). **Every new admin route must be added there**, otherwise `bin/admin-access-check.php` fails. `bin/admin-confirm-check.php` presumably checks that destructive forms have `data-confirm`.
- CSRF convention: `isCsrfTokenValid('<id>', (string)$request->request->get('_token'|'_csrf_token'))`, with `csrf_token('<id>')` in Twig. Quick endpoints answer JSON `{ok:bool,error?:'format'|'save'}` with 403 for CSRF and 422 for validation.
- i18n: Twig uses `ui_text('key')` and PHP uses `CanonicalUiText::get('key',['param'=>…])`. Translation files are `resources/translations/<locale>/{admin,storefront,core,runtime,commerce,api,installer,emergency}.php`. Locales are `uk-UA, en-US, ru-RU, de-DE, pl-PL, da-DK`. Checks: `bin/i18n-template-check.php`, `bin/i18n-runtime-hardcode-audit.php --strict`, `bin/i18n-critical-journey-check.php`.
- Frontend build: Vite entry `assets/admin/main.ts`, loaded in `_shell.html.twig` through `vite_asset(...)`. The admin CSS is `assets/admin/admin-runtime.css` plus `assets/admin/parts/*.css` and `rich-editor.css`. `public/build` is not in git, so after JS or CSS edits run `npm run build:production` (or `npm run dev`).
- Working-tree state: `.claude/`, `.env`, `AGENTS.md`, `CLAUDE.md` and `.autopilot/2026-10-14-catalog-ai-batch-polish--wip/` are untracked or modified. HEAD is `2aaa34d2`. The "Unreleased" CHANGELOG section is the place for the next entry.
