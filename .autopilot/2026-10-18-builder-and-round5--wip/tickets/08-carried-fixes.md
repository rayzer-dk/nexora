# 08 — Склад рынка в одном месте, тест переноса настроек, проверка кириллицы

**Требования:** G01, G02, G03
**Зависит от:** —
**Зона:** new `src/Modules/Catalog/Application/MarketStockLocation.php` · `src/Modules/Catalog/Application/{ProductWriter,ProductStockService}.php` (location lookup only) · `src/Modules/Catalog/Infrastructure/DbalCatalogAdminQuery.php` (location lookup only) · `src/Core/Extension/ExtensionPackageManager.php` (only if a seam is needed for the test, no behaviour change) · `bin/i18n-runtime-hardcode-audit.php` · `tests/Unit/MarketStockLocationTest.php` · `tests/Unit/ExtensionPackageManager*Test.php`
**Волна:** 1
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

Склад рынка выбирается одним методом во всех местах каталога. Перенос настроек модуля при обновлении проверен тестом через `activate`. Проверка кириллицы в коде зелёная на Windows.

## Из брифа, дословно

> Дополнения: «4. взять из прошлой сборки: а) общий выбор склада, б) тест переноса настроек при обновлении, в) кириллица в 7 PHP-файлах» — «согласен, продолжай»

## Разделы спецификации

Истории 43–45; `interfaces.md` → «Warehouse of a market»; `notes.md` §3.

## Критерии приёмки

- [ ] `MarketStockLocation::locationId` используется в 5 местах, одинаковый SQL больше нигде не повторяется (`grep`), тесты остатка зелёные
- [ ] Тест: `ExtensionPackageManager::activate` новой версии переносит настройки; `activate(id, true)` (откат) — нет
- [ ] `php bin/i18n-runtime-hardcode-audit.php --strict` — exit 0 на Windows; исключения не расширены
