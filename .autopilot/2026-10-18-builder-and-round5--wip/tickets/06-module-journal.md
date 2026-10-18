# 06 — Журнал и диагностика модулей

**Требования:** R14
**Зависит от:** 01
**Зона:** `themes/default/templates/admin/system/extensions.html.twig` · new `themes/default/templates/admin/system/extension_journal.html.twig`, `extension_diagnostics.html.twig` · `src/Modules/Admin/Http/SystemAdminController.php` (extensions section) or a new `ExtensionJournalAdminController` · `src/Core/Extension/ExtensionPackageManager.php` (read-only queries: lifecycle, diagnostics) · `src/Core/Extension/ExtensionMigrationRunner.php` (read-only status) · `tests/Unit/ExtensionJournal*Test.php` · `tests/e2e/module-journal.spec.ts`
**Волна:** 2
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

Журнал модулей с фильтром по модулю и типу события, подробностями и выгрузкой CSV. У каждого модуля — «Діагностика»: версия, совместимость с ядром, подпись, права, миграции, настройки, последние события.

## Из брифа, дословно

> «45 журнал установки модулей»

## Разделы спецификации

Истории 27–28; `notes.md` §2 (пункт 45).

## Критерии приёмки

- [ ] Журнал: фильтр по модулю и типу события, подробности события (действие, итог, ошибка), CSV; заголовок раздела переведён (сейчас «Extension audit trail»)
- [ ] Диагностика модуля: версия, совместимость с ядром (`CoreVersionConstraint`), подпись (trusted), объявленные права, миграции применены/нет, настройки (схема разобрана / ошибка), последние 10 событий
- [ ] Новые маршруты в карте прав (`system.settings` или права раздела модулей), CSRF у CSV не нужен (GET), выгрузка не отдаёт секретов
- [ ] Тест сервиса диагностики (модуль без схемы, с битой схемой, с непримененной миграцией)
