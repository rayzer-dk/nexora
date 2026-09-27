# Multilanguage and multicurrency

Nexora Commerce is natively multistore, multilingual and multicurrency.

## Languages

Locales are normalized BCP-47 tags such as `uk-UA`, `en-GB`, `da-DK`, `de-DE` and `pl-PL`. The platform does not hard-code a fixed language list. Each store independently enables locales, chooses one default locale, controls ordering and can configure an optional URL prefix.

Product, category and brand content is stored per store + locale where the content can genuinely differ by storefront. Internal technical identifiers are language-neutral.

Fallback is explicit: requested locale -> store fallback locale -> default locale. A missing translation is never silently copied into another language as if it were translated.

## Currencies

Currencies use ISO-4217 codes and integer minor units. Each store independently enables currencies and selects a default/base currency.

Explicit prices in the requested currency always win. Optional exchange-rate conversion is a fallback for stores that choose it. Converted values are rounded using store currency rules and never overwrite explicit prices.

Orders snapshot their currency and monetary amounts. A later exchange-rate change can never alter a historic order.

Exchange-rate providers are adapters. No external rate service is a checkout reliability dependency: the last valid non-expired rate can be used according to store policy, or conversion can be disabled while explicit prices continue to work.

## Google Commerce

Target landing page language, Merchant product language, price currency and shipping currency must remain consistent. Merchant projections are therefore generated from the same store/locale/currency context used by the storefront and checkout.

## Translation catalog layout

Core translations are loaded as one logical catalog from locale-first directories under `resources/translations/<locale>/`. Domain files such as `admin.php`, `runtime.php`, `api.php`, `commerce.php`, `storefront.php`, `installer.php`, or `emails.php` are recursively merged without changing application lookup calls.

Independent extensions keep their language files inside the extension package. Supported locations are `extensions/<code>/translations/<locale>.php`, `extensions/<code>/translations/*.<locale>.php` and `extensions/<code>/resources/translations/*.<locale>.php`.

Extension keys must be namespaced as `extension.<code>.*`. The loader ignores extension keys outside that namespace, so installing an extension cannot silently replace Core translations. Removing the extension removes its translations without editing any global language file.

`uk-UA` remains the canonical fallback. New domain files can be introduced gradually; the existing catalogs do not need a risky one-time split.
