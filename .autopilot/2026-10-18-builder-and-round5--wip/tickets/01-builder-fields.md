# 01 — Панель свойств по описанию блока, блоки модулей

**Требования:** R01, R02
**Зависит от:** —
**Зона:** `assets/admin/features/builder.js` · `src/Modules/Appearance/Builder/**` · `src/Core/Extension/ExtensionContributionRegistry.php` · `src/Modules/Admin/Http/LayoutBuilderAdminController.php` · `themes/default/templates/admin/appearance/builder.html.twig` · `src/Modules/ProductPage/Application/ProductPageLayoutLoader.php` (extensionBuilderSettings only) · `themes/default/templates/product/blocks/extension_component.html.twig` · `themes/default/templates/checkout/blocks/extension_component.html.twig` · new `themes/default/templates/components/extension_block.html.twig` · `themes/default/templates/home.html.twig` · `themes/default/templates/category/show.html.twig` · `themes/default/templates/cart/show.html.twig` (extension block include only) · `assets/admin/admin-runtime.css` (builder rules) · `tests/Unit/BuilderComponentCatalogTest.php` · `tests/Unit/LayoutSchemaValidator*Test.php` · `tests/Unit/ExtensionContributionRegistry*Test.php` · `tests/e2e/builder-inspector.spec.ts`
**Волна:** 1
**Ревью:** да — фундамент (описание полей, которым пользуются таски 02–03, и формат блоков модулей)
**Модель:** сильная

## Что должно заработать

В конструкторе у каждого блока своя панель свойств, построенная по описанию его полей. У блока модуля — поля из схемы модуля. Значения сохраняются и доходят до витрины; блок модуля виден на главной, в категории и корзине так же, как сейчас на товаре и оформлении заказа.

## Из брифа, дословно

> «конструктор — панель свойств по схеме блока (в т.ч. блоки модулей)»

## Разделы спецификации

Истории 1–7; «Решения по реализации» (первые два пункта); `interfaces.md` → «Builder component catalog», «Extension block fields», «Inspector»; `notes.md` §1.

## Критерии приёмки

- [ ] Каждый компонент из `LayoutSchemaValidator::ALLOWED_COMPONENTS`, разрешённый типу страницы, имеет описание полей в `BuilderComponentCatalog` либо явно числится «общим»; юнит-тест обходит все пять типов
- [ ] `renderInspector` больше не содержит групп полей, зашитых по имени компонента; все поля, что были (баннер, плитки, товары, история, видео, источник данных, оформление заказа), есть и работают — макеты 4.0.0 открываются и сохраняются без потерь (тест на сохранённом макете каждого типа)
- [ ] Контролы по типу поля: text, textarea, url, number, select, boolean, color, image, multilingual_text, multilingual_textarea; неверные url/number/color подсвечиваются и не уходят на сервер, сервер повторно проверяет их
- [ ] Блок модуля показывает поля своей `settings_schema`; значения в `props`; битая схема — общие поля, предупреждение и событие `block_schema_invalid` в журнале модулей
- [ ] Блок модуля рендерится на home, category, cart общим шаблоном (заголовок/текст на языке витрины, многоязычные значения с откатом на язык по умолчанию)
- [ ] e2e `builder-inspector.spec.ts` на изолированной копии: поля баннера, поле модуля (тестовый модуль из `docs/examples` или фикстуры), сохранение и повторное открытие
