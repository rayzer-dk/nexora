# Icons in Nexora Commerce

Nexora ships its icon assets locally. The standard UI icon set is Lucide; official
outline brand marks for social networks and messengers come from Tabler Icons
(MIT, `resources/icons/TABLER-LICENSE.txt`).

## Local catalogs

- `resources/icons/lucide.json`: curated core icons. The browser JavaScript
  bundle contains these; server-side Twig also uses them for the common icons.
- `resources/icons/lucide-library.json`: full Lucide catalog. It stays on the
  server; opening the admin icon picker loads it once from `/admin/icons.json`.
- `resources/icons/tabler-brands.json`: official Tabler outlines with stable
  `brand-*` identifiers. Included locally in the icon picker and storefront
  JavaScript; no external CSS, CDN or icon-font request is needed.

Only the relevant SVG markup is emitted into a Twig-rendered page.

## For modules and themes

Twig:

```twig
{{ ui_icon('alarm-clock-check', 20) }}
{{ ui_icon('brand-discord', 20) }}
```

`ui_icon()` first checks the curated set, then falls back to the full Lucide
catalog automatically. Modules do not need to add common Lucide icons to the
curated manifest or modify the core files.

JavaScript (for modules built with the Nexora asset pipeline):

```js
import { lucideIcon, lucideIconAsync, lucideIconNodeAsync } from '../../shared/lucide-icons.js';

// Common icons are synchronous, no requests.
button.innerHTML = lucideIcon('check', 20);

// An icon from the optional Lucide catalog loads by name from the same-origin
// /icons.json endpoint. The synchronous version temporarily renders a link
// placeholder, then updates it automatically after insertion into the DOM.
button.innerHTML = lucideIcon('alarm-clock-check', 20);

// Or await a complete SVG before inserting it:
button.innerHTML = await lucideIconAsync('alarm-clock-check', 20);
button.replaceChildren(await lucideIconNodeAsync('alarm-clock-check', 20));
```

The optional-icon request only contains the requested names, deduplicates
concurrent requests and caches successful lookups. No full-catalog browser
download occurs. A name absent from both libraries remains a visible link
placeholder instead of an empty button; the name must be corrected in the module.

For rich-text icons, the storefront similarly requests only the distinct names
in the document using `/icons.json?names=...` (up to 60 per request).
The admin icon picker is the only interface that requests the entire catalog.

## Adding more official social marks

Fetch the official outline drawing from the corresponding
`icons/outline/brand-<name>.svg` in
`https://github.com/tabler/tabler-icons`, preserve its inner SVG paths in the
local Tabler JSON, and keep the MIT license. The `brand-` identifier becomes
available to Twig, the admin icon picker and JavaScript.

To expose a new network in the Contacts settings, add a safe URL host allowlist
and optional profile prefix in `StorefrontContactSettings::SOCIAL`. The
`social_links.html.twig` component resolves `brand-<code>` by name.

## Validation

`php bin/icons-check.php` verifies fixed icon references against both Lucide
catalogs and Tabler, checks the Contacts network-to-icon mapping, and rejects
hand-drawn SVG icons in Twig templates.
