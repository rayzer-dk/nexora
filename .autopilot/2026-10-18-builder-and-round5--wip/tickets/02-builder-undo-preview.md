# 02 — Отмена/повтор и живой предпросмотр

**Требования:** R03, R04
**Зависит от:** 01
**Зона:** `assets/admin/features/builder.js` · `themes/default/templates/admin/appearance/builder.html.twig` · `src/Modules/Admin/Http/LayoutBuilderAdminController.php` (draft.json route) · `src/Modules/Storefront/Application/{CategoryLayoutService,CartLayoutService}.php` · `src/Modules/Checkout/Application/CheckoutLayoutService.php` · `src/Modules/ProductPage/Application/ProductPageLayoutLoader.php` · `src/Modules/Appearance/Builder/LayoutRevisionStore.php` · `assets/storefront/admin-runtime.js` (initRealPreview only) · `assets/admin/admin-runtime.css` (builder rules) · `tests/Unit/*Layout*Test.php` · `tests/e2e/builder-undo.spec.ts` · `tests/e2e/builder-live-preview.spec.ts`
**Волна:** 2
**Ревью:** нет
**Модель:** сильная

## Что должно заработать

Любое действие в конструкторе отменяется и возвращается. Справа настоящая страница магазина показывает несохранённые правки через полсекунды на всех пяти типах страниц; покупатель видит только опубликованное. Схему можно включить переключателем.

## Из брифа, дословно

> «Undo/Redo, живой предпросмотр»

## Разделы спецификации

Истории 8–14; «Решения по реализации» (Undo/Redo, живой предпросмотр); `interfaces.md` → «Undo / redo», «Draft autosave, live preview, concurrent editing» (маршрут draft.json и загрузчики; проверка версий — таск 03); `notes.md` §1.6.

## Критерии приёмки

- [ ] Кнопки и Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z отменяют и возвращают добавление, удаление, дублирование, перестановку, вкл/выкл блока и правку поля; набор текста в одном поле — один шаг; до 100 шагов; кнопки неактивны, когда нечего отменять
- [ ] Черновик автосохраняется JSON-маршрутом через 500 мс после последней правки, iframe перезагружается с сохранением прокрутки; статус «Збережено о …» / «Зберігаю…» / «Не вдалося — повтор через N с» (2, 5, 15 с); уход со страницы с несохранёнными правками спрашивает
- [ ] category, cart, product, checkout показывают черновик в предпросмотре с токеном и опубликованное без токена (тесты на загрузчики)
- [ ] Переключатель «Сторінка / Схема» запоминается в браузере
- [ ] e2e: отмена/повтор; правка → iframe показывает новый заголовок без нажатия «Зберегти»; витрина без токена — старый
