# 04 — Витрина: только логотип без имени, размер логотипа и подсказка с габаритами

**Требования:** R14, R15, R16
**Зависит от:** 03
**Зона:** `themes/default/templates/base.html.twig` · `themes/default/templates/components/site_footer.html.twig` · `themes/default/templates/landing*.twig` · forum header templates · `themes/default/templates/admin/appearance/storefront.html.twig` (brand fields) · `src/Modules/Appearance/Infrastructure/StorefrontPresentationSettings.php` · `src/Modules/Admin/Http/AppearanceAdminController.php` (brand fields) · `src/Modules/Storefront/PwaController.php` · `themes/default/templates/email/base.html.twig` · `src/Core/Store/StoreIdentitySettings.php` (fallback only) · `assets/storefront/storefront.css` (brand rules) · `assets/storefront/parts/{chrome,landing}.css` (brand rules) · `tests/e2e/brand-logo.spec.ts`
**Волна:** 4
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

Владелец убирает название и оставляет только логотип — на витрине (шапка десктоп и мобильная, подвал, лендинг, форум) больше нигде не видно ни «Nexora», ни названия. Логотип в шапке крупнее по умолчанию, его высота настраивается, а у поля загрузки написано, какого размера делать файл, чтобы он заполнил блок.

## Из брифа, дословно

> «убираю название магазина а только логотип, но система все равно пишет nexora. и размер логотипа наверное нужно немного увеличить или писать какие габариты подходящие для максимального заполнения выделегого блока.»

## Разделы спецификации

Истории 21–25; `interfaces.md` → «Brand / logo (T05)»; `notes.md` §5.

## Критерии приёмки

- [ ] Сначала воспроизвести: с логотипом и выключенной «Показувати назву» (и отдельно — с пустым «Назва бренду») найти все места витрины, где видно имя/«Nexora» (десктоп 1440 и 390); после правки — ни одного видимого
- [ ] Пустое «Назва бренду» + логотип → видимого имени нет; без логотипа → видно название магазина
- [ ] `<title>`, alt логотипа, aria-label, og:site_name, JSON-LD остаются с названием магазина
- [ ] PWA-манифест и письма берут имя магазина; «Nexora Commerce» остаётся только как резерв при ошибке чтения
- [ ] Логотип по умолчанию 48 px в высоту и до 260 px в ширину; настройка «Висота логотипу в шапці» 32–96 px сохраняется и применяется (CSS-переменная), мобильная шапка не ломается
- [ ] Подсказка у поля логотипа с рекомендуемыми габаритами (520×130 px, 4:1, PNG/SVG/WebP, прозрачный фон) и текущей высотой; ключи в uk-UA + en-US
- [ ] e2e-спек проходит на изолированной копии; `bin/i18n-template-check.php` OK
