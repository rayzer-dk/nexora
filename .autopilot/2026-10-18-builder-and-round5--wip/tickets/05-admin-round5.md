# 05 — Админка: «Нашли дешевле?», курсы валют, шапка и кэш, маркетплейсы, ключи ИИ

**Требования:** R06, R08, R12, R15, R16, R17, R27
**Зависит от:** —
**Зона:** `themes/default/templates/admin/dashboard.html.twig` · `src/Modules/Customer/Application/CustomerInquiryService.php` · `themes/default/templates/admin/commerce/inquiries*.twig` (inquiry card) · `themes/default/templates/admin/system/localization.html.twig` · `themes/default/templates/admin/_shell.html.twig` (header only) · `themes/default/templates/admin/system/_cache_menu.html.twig` · `src/Modules/System/Http/MaintenanceAdminController.php` · `themes/default/templates/admin/system/connections.html.twig` · `themes/default/templates/admin/system/_integration_guide.html.twig` · marketplace admin templates · `themes/default/templates/admin/system/ai.html.twig` · `assets/admin/parts/*.css` (rules of these screens) · `tests/Unit/CustomerInquiry*Test.php` · `tests/e2e/admin-round5.spec.ts`
**Волна:** 2
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

«Нашли дешевле?» виден в виджете обращений, письмо менеджеру понятно, ссылка конкурента и цена видны в обращении. Курсы валют в строку. Шапка админки контрастна в обеих темах, меню кэша показывает размеры всех строк и умеет пересчитать. Подсказки маркетплейсов на всю ширину, значения переведены. У ключа ИИ — показать/скрыть, «ключ збережено», удалить.

## Из брифа, дословно

> «39 «Нашли дешевле?», 40 … убрать «Домени магазину», 43 перерасчёт цен — выравнивание, 46 контраст шапки и кнопка кэша, 47 подсказки в маркетплейсах, 57 ШІ-помічник — выбор модели»

## Разделы спецификации

Истории 19, 21, 25, 29–31, 41; `notes.md` §2 (пункты 39, 40, 43, 46, 47, 57).

## Критерии приёмки

- [ ] Тип `cheaper` подписан в виджете обращений дашборда; письмо менеджеру с темой «Знайшли дешевше: <товар>»; ссылка конкурента и цена — отдельные строки в карточке обращения (тест на сервис письма)
- [ ] Неиспользуемый ключ `admin.system.store.domeny_mahazynu` удалён из переводов
- [ ] Курсы валют / перерасчёт цен: поля в строку, «UAH» не переносится на 1440 и 390 px (снимок)
- [ ] Шапка админки: axe-контраст ≥ 4.5:1 в светлой и тёмной теме; меню кэша — размеры для всех строк, которые можно посчитать, кнопка «Перерахувати» обновляет размеры без очистки
- [ ] Подсказки на страницах подключений и маркетплейсов — на всю ширину блока (снимок 1440/390), значения настроек переведены
- [ ] Ключ ИИ: кнопка показать/скрыть, значок «ключ збережено» при сохранённом ключе, удаление ключа иконкой с подтверждением
