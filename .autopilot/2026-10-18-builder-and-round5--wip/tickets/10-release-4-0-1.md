# 10 — Выпуск 4.0.1

**Требования:** R28
**Зависит от:** 09
**Зона:** version files (`src/Core/Platform/PlatformVersion.php`, `resources/platform/release.json`, `release-manifest.json`, `package.json`, `package-lock.json`, `config/theme/default.json`, `src/Modules/Demo/Application/DemoSeeder.php` version line, `README.md`, `README_EN.md`, `INSTALL_UK.txt`) · `CHANGELOG.md` · `docs/PRODUCT_GAP_ANALYSIS.md` (what 4.0.1 delivered)
**Волна:** 5
**Ревью:** да — релиз (упаковка, утечки секретов в архив)
**Модель:** сильная

## Что должно заработать

Версия 4.0.1 везде; CHANGELOG раздел 4.0.1 из «Unreleased» и этой сборки; архив и контрольная сумма на ПК, проверка упакованного релиза и утечек проходит, чистая установка открывает витрину и админку.

## Из брифа, дословно

> «сборка 4.0.1»

## Разделы спецификации

История 46.

## Критерии приёмки

- [ ] 4.0.1 во всех файлах версии; совместимость модулей «^3.x || ^4.0» не сломана (bonus-пакеты VALID)
- [ ] CHANGELOG: раздел «4.0.1 — <дата>» (Unreleased + эта сборка), конкретно, без рекламы
- [ ] `php tools/package-production.php --out="C:/Users/Владимир/Documents/Codex"` → `Nexora_Commerce_v4.0.1_PRODUCTION.zip` + `.sha256`; `verify-packaged-release` и проверка утечек проходят; чистая установка с `--demo` в отдельной БД на 3307 открывает витрину и админку (БД и сервер убраны после)
- [ ] Публикация (push, GitHub Release) — оркестратор, после «да» владельца
