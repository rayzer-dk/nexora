# 01 — Списки каталога: количество товаров, остатки, статус-переключатели

**Требования:** R02, R03, R04, R05
**Зависит от:** —
**Зона:** `themes/default/templates/admin/catalog/products.html.twig` · `themes/default/templates/admin/catalog/categories.html.twig` · `src/Modules/Catalog/Application/ProductStockService.php` · `src/Modules/Catalog/Infrastructure/DbalCatalogAdminQuery.php` · `src/Modules/Admin/Http/{ProductWorkspaceAdminController,CommerceOperationsAdminController,UndoAdminController,CatalogAdminPageController}.php` · `src/Modules/Admin/Authorization/AdminPermissionSubscriber.php` · `assets/storefront/admin-runtime.js` (quick price/status section) · `assets/admin/admin-runtime.css` (quick-* rules) · `tests/Unit/ProductStockServiceTest.php` · `tests/e2e/catalog-lists.spec.ts`
**Волна:** 1
**Ревью:** да — меняет остатки (данные) и отмену
**Модель:** сильная

## Что должно заработать

В списке категорий у каждой категории видно, сколько в ней товаров вместе с подкатегориями, и это число ведёт в список товаров с фильтром. В списке товаров есть колонка «Залишок»: остаток правится прямо в строке, как цена; в массовых действиях — «Обнулити залишок» с подтверждением и отменой. Колонка «Статус» в обоих списках — только переключатель, без текстовой метки.

## Из брифа, дословно

> «в категории товаров в списке не хватает количества товара. в масовые действия так же добавь обнулить остаток типа нажимаю и остатки выбраных товаров 0. так же сделай быстрое редактирование остатка так же как цена. Столбик Статус можно только переключатели без текста для экономии места.»

## Разделы спецификации

Истории 1–11; `interfaces.md` → «Catalog — stock (T02)» и «Catalog — category counts (T01)» (оба — этот таск); `notes.md` §1, §2.

## Критерии приёмки

- [ ] Дерево категорий и режим поиска показывают число товаров с подкатегориями (без дублей), подсказка с числом «у самій категорії»; число — ссылка `/admin/catalog/products?category[]=<id>`
- [ ] Колонка «Залишок» в списке товаров (в наборе колонок, по умолчанию видна); поле для товара с одним вариантом; Enter/blur сохраняет, Esc отменяет; неверный ввод (буквы, отрицательное) не сохраняется и подсвечивается; тост об успехе/ошибке
- [ ] Товар с несколькими вариантами показывает сумму и ссылку «Варіанти (N)», без поля
- [ ] Масова дія «Обнулити залишок»: подтверждение с количеством, все варианты выбранных товаров → 0 на складе рынка, товары без складской позиции пропущены и посчитаны; flash с итогом и «Скасувати», отмена возвращает прежние остатки
- [ ] Быстрое изменение остатка тоже попадает в отмену
- [ ] Колонка «Статус» в товарах и категориях — только переключатель (состояние в `title`/`aria-label`), архивный товар — иконка с подсказкой; колонка узкая
- [ ] Новые маршруты в `AdminPermissionSubscriber`; `bin/admin-access-check.php`, `bin/admin-confirm-check.php`, `bin/i18n-template-check.php` — OK
- [ ] Юнит-тест `ProductStockService` (set, zero, restore, skip без складской позиции) и e2e-спек на изолированной копии проходят; `npm run typecheck`, eslint — 0 ошибок
