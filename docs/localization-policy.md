# Політика локалізації

`uk-UA` — канонічна локаль поточного етапу розробки.

Core-каталоги мають locale-first структуру. У корені `resources/translations/` мовні PHP-файли не зберігаються:

`resources/translations/uk-UA/core.php`
`resources/translations/uk-UA/admin.php`
`resources/translations/uk-UA/runtime.php`
`resources/translations/uk-UA/api.php`
`resources/translations/uk-UA/commerce.php`
`resources/translations/uk-UA/storefront.php`
`resources/translations/uk-UA/installer.php`
`resources/translations/uk-UA/emergency.php`

Для інших локалей використовується така сама модель, наприклад `resources/translations/en-US/storefront.php`. Фізичний домен не є частиною ключа: loader рекурсивно об'єднує всі PHP-каталоги поточної локалі в один runtime catalog.

Усі нові користувацькі тексти, повідомлення, підписи, placeholder, title/aria-label, CLI-повідомлення, email/system тексти та JavaScript/Vue fallback-тексти мають зберігатися у мовних каталогах, а не безпосередньо в runtime-коді.

Нові ключі додаються лише до українських каталогів. Наявні каталоги інших мов не розширюються до окремого етапу перекладу. Якщо переклад відсутній, система використовує `uk-UA` як fallback.

Виняток для кирилиці у runtime-коді допускається лише для алгоритмічних даних, де самі символи є частиною алгоритму, наприклад таблиці української транслітерації. Технічні ідентифікатори, протокольні значення, SKU, GTIN, API, URL та назви зовнішніх продуктів не вважаються перекладним текстом.

Обов’язкова перевірка перед релізом: `php bin/localization-contract-check.php`.

## Extension-owned translations

Core translations stay centralized in `resources/translations/`. Installable extensions own their translations and keep them in the extension's versioned installation directory. The runtime merges only the active extension catalogs.

Third-party declarative and remote extensions must use JSON translation catalogs. Preferred layout is locale-first inside the extension, for example `translations/uk-UA/messages.json` or `translations/uk-UA/admin.json`. PHP translation files are executable code and remain subject to the extension quarantine policy.

The required extension key prefix is `extension.<normalized_extension_code>.`. For example, `vendor.delivery` uses `extension.vendor_delivery.*`. `uk-UA` is the mandatory extension fallback locale. Core keys and keys belonging to another extension are rejected during package validation.
