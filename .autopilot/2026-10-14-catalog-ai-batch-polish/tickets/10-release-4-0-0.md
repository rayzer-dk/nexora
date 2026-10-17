# 10 — Релиз 4.0.0: версия, архив на ПК, план «лучше конкурентов», сверка прошлого запроса

**Требования:** R01, R18, R19, R23i
**Зависит от:** 09
**Зона:** version files (`src/Core/Platform/PlatformVersion.php`, `resources/platform/release.json`, `release-manifest.json`, `package.json`, `package-lock.json`, `config/theme/default.json`, `src/Modules/Demo/Application/DemoSeeder.php` version line, `README.md`, `README_EN.md`, `INSTALL_UK.txt`, `CHANGELOG.md`) · `tools/package-production.php` · `docs/PRODUCT_GAP_ANALYSIS.md` · `docs/AI_CAPABILITIES.md`
**Волна:** 9
**Ревью:** да — релиз (упаковка, утечки секретов в архив)
**Модель:** сильная

## Что должно заработать

Версия 4.0.0 записана везде одинаково; на ПК лежит готовый production-архив `Nexora_Commerce_v4.0.0_PRODUCTION.zip` со свежим `public/build`, `vendor` без dev-пакетов и SHA-256; он проходит проверку упакованного релиза и ставится начисто. CHANGELOG описывает 4.0.0. Документ анализа обновлён планом следующих сборок, а AI-документация — пакетным переводом и словарём.

## Из брифа, дословно

> «где готовый архив на github или на пк?»
> Дополнения: «Ок, согласен со всеми» (версия 4.0.0, архив на ПК в Documents\Codex и GitHub Release в rayzer-dk/nexora)
> «Ты все сделал о чем я просил?» · «делавем все чего не хватает до уровня Лучше конкурентов.»

## Разделы спецификации

Истории 43, 44, 46–49; «Решения по реализации» (упаковка PHP-скриптом).

## Критерии приёмки

- [ ] 4.0.0 во всех файлах версии; `php tools/verify-packaged-release.php <out>` проходит; CHANGELOG: раздел «4.0.0 — 2026-10-14» (из «Unreleased» + эта сборка)
- [ ] `tools/package-production.php` повторяет исключения, проверки (`bin/*` как в `.sh`), манифест и SHA256SUMS из `package-production.sh`, работает на Windows без rsync/zip; `vendor` без dev (отдельная копия, `composer install --no-dev` в ней, не в рабочем дереве); `public/build` собран из текущих исходников
- [ ] В архиве нет `.env*` (кроме примера), `var/` данных, `.git`, `.claude`, `.autopilot`, `tests`, ключей; проверка утечек проходит
- [ ] Архив и `.sha256` лежат в `C:\Users\Владимир\Documents\Codex\`; распаковка в пустую папку + `commerce:install --demo` на изолированном MySQL проходит, главная и админка открываются
- [ ] `docs/PRODUCT_GAP_ANALYSIS.md` обновлён: что сделано этой сборкой, план следующих (Rozetka/Epicentr, KeyCRM/SalesDrive, бот Viber/Telegram, ЕС-платежи/доставка); `docs/AI_CAPABILITIES.md` — пакетный перевод, словарь, массовые действия, показ результата
- [ ] Публикация (push в main, GitHub Release) — делает оркестратор после приёмки, не исполнитель
