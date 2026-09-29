# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.9.0 — 2026-09-30

Schema 53, Extension API 2.0, production channel.

### Added
- **EU withdrawal function** (Directive (EU) 2023/2673): public `/withdrawal` page linked from every footer, two steps ("Withdraw from contract here" → "Confirm withdrawal here"), acknowledgement email with reference and date/time, notices listed in Admin → Customer experience. Works for guests; the notice is stored even when the order is not matched. Migration adds `mc_withdrawal_notice`.
- **Accessibility statement** (`/accessibility`, European Accessibility Act) in six locales, linked from the footer.
- **Mandatory administrator 2FA policy**: `ADMIN_REQUIRE_MFA=1` forces enrolment before any admin page opens and forbids disabling 2FA.
- **PHPStan level 3** in CI (`composer analyse`, pinned phar, no baseline).
- Automatic text colour on brand-coloured surfaces (`contrast_color`) so custom brand colours keep WCAG AA contrast.
- Playwright: axe colour-contrast scan of storefront and admin in light and dark schemes, bonus themes/extensions, withdrawal flow and 2FA policy.

### Fixed
- Colour contrast in light and dark schemes: filled primary/accent buttons and badges, danger badge, warning text, admin navigation labels.
- Product Trust Badge bonus extension (1.0.1): manifest block and empty slot rendering on the product page.
- Phpstan findings: missing `MediaImageService::writeJpeg()`, delivery point cache key, public API catch of non-exception errors.

## 3.8.1 — 2026-09-29

Schema 52, Extension API 2.0, production channel.

### Added
- **Module uninstall** (Admin → System → Extensions): removes a non-active version with its files, published assets and settings; optional "with module data" runs the package's `down` migrations. Recorded in the lifecycle audit trail.
- `commerce:extension:keygen` creates an Ed25519 publisher key pair and prints the manifest and `trusted-publishers.json` fragments.
- `public/.htaccess`: Brotli/Gzip precompressed asset delivery, on-the-fly compression for HTML/JSON/XML and cache headers (immutable for hashed build assets) for Apache hosting, matching the nginx example.
- Playwright `extension-authoring` covers the whole module life cycle for declarative and signed PHP modules.

### Fixed
- Rolling an extension back to its previous version always failed because the rollback called activation, which forbids downgrades. Rollback now activates the older version explicitly.
- The `product-block` scaffold and the docs example declared regions `main`/`sidebar` that the product page does not render, so the block was accepted but never shown. Scaffold and example use real regions and the validator rejects unknown product regions.
- `commerce:extension:scaffold` generated a broken `Entrypoint.php` for trusted modules (`TrustedExtensionContext \)` plus a PHP warning); the `trusted-route` preset now generates a working route and handler.
- `commerce:extension:pack` failed on any trusted module because the placeholder signature was validated before signing; pack now validates structure and reminds to sign.

## 3.8.0 — 2026-09-29

Schema 52, Extension API 2.0, production channel.

### Added
- **Two-factor authentication for administrators** (TOTP, RFC 6238): enrolment with secret + `otpauth://` URI, 8 single-use recovery codes, replay protection (a time step can be used once), lock-out of the session after 5 wrong codes, secrets encrypted with `SecretVault`. Managed at Admin → Account security; migration `Version20260929120000` adds `mc_admin_mfa`.
- **Dark theme**: `theme.color_scheme` (light / auto / dark) in Admin → Appearance. Auto follows the device and shows a visitor toggle remembered in the browser; the admin panel has its own toggle. All colours come from design tokens.
- **PWA**: web app manifest, service worker (static assets cached, HTML never cached, private areas bypassed) and an `/offline` page; can be switched off in Appearance (a self-unregistering worker is served then).
- **Recently viewed** block on the home and product pages (stored in the visitor's browser only).
- **Error monitoring hook**: optional `ERROR_WEBHOOK_URL` (https) receives a minimal JSON payload for every intercepted runtime incident; no stack traces or personal data.
- Playwright coverage: `admin-mfa`, `color-scheme`, `pwa`, `recently-viewed`; PHPUnit: `TotpTest`, `IncidentWebhookNotifierTest`.

### Changed
- Design tokens gained `--mc-color-primary-base` / `--mc-color-accent-base` (brand colours) with derived hover colours and dark-mode overrides.
- Lucide set extended to 140 icons.

## 3.7.2 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Fixed
- `ConfigurationRevisionStore::save()` referenced `$payload` inside its transaction closure without importing it, raising `Undefined variable $payload` on every settings save (store, site, appearance) and defeating the "identical configuration → no new revision" check. Saving unchanged settings no longer creates a revision, and the warning is gone from the runtime log audit.
- The UI-text Twig functions no longer treat a template loop variable named `locale` (as on Admin → Localization) as the UI locale; this removed hundreds of `Array to string conversion` warnings per page render.
- CI runtime-log audit ignores the expected `AccessDeniedHttpException` produced by the read-only RBAC E2E check, in addition to 404s.

## 3.7.1 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- Complete, ready-to-review texts for all ten system information pages (about, contacts, delivery, payment, returns, warranty, FAQ, privacy policy, cookie policy, terms) in Ukrainian and English (`resources/content/information/`). The installer creates them as drafts filled from the store profile; missing legal details show as highlighted fields. The demo publishes them.
- Cookie policy lists the cookies the storefront really sets (`PHPSESSID`, `mc_cart`, `store_locale`, `store_currency`, `store_market`, `mc_consent_v1`) with purpose, duration and consent category.
- Curated technical specifications (display, processor, camera, battery, connectivity, OS, ports, weight, features) for 26 of the 29 demo products, shown in the product summary and the full specification table.
- Long-form page typography: tables, ordered lists, links, code and placeholder highlighting.
- Playwright: `information-pages.spec.ts` checks every information page, the cookie table and reopening consent from the footer, and product specifications.

## 3.7.0 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- One design-token layer (`assets/shared/tokens.css`) for admin and storefront: spacing, type scale, control heights (32/40/48), radius, elevation, z-index layers, icon sizes and a fixed breakpoint set. Density in Appearance now really rescales controls and spacing.
- Lucide is the only icon set: icons are generated from the installed `@lucide/vue` package (`tools/build-lucide-icons.mjs` → `resources/icons/lucide.json`) and rendered by `ui_icon()` / `lucideIcon()`.
- Release gates: `icons-check` (only real Lucide icons, no inline SVG), `css-coverage-check` (every template class has a style), `design-tokens-check` (no raw font sizes, z-indexes, radii, shadows or breakpoints).
- Demo catalog grows to 29 products in 5 categories (DummyJSON, MIT); demo counts on the home page are computed from real data.

### Changed
- Admin and storefront CSS rewritten from scratch on the tokens (one button, form, table, card, badge, modal and notice system). Admin gets a grouped sidebar with icons, breadcrumbs, a working dashboard action center, icon row actions and consistent list toolbars.
- Storefront: compact non-blocking cookie card, rebuilt hero, product-on-tone promo banners, uniform product cards, adaptive category tiles, text-first article cards.
- Storefront font is chosen in Appearance: System UI, Inter Variable or Manrope (self-hosted).
- New stores ship without promotional artwork; hero and banners appear after they are configured.
- Vite `base` is `/build/`, so chunk preloads no longer 404.

### Fixed
- Home page tiles and banners linked to categories that did not exist (`/kids`, `/beauty-health`, …); they are now built from real categories.
- Forum search returned 500 (invalid `ESCAPE` clause).
- Product page showed the raw unit code (`item`) next to the quantity.
- About 70 admin and 55 storefront CSS classes used in templates had no styles; Bootstrap-style leftovers removed.

## 3.6.6 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- Store-scoped customer membership and customer groups; customers are bound to the store on registration, cart binding and checkout.
- Store scope carried through the integration, Google and marketing job queues, delivery records and diagnostics/retry.
- Real-runtime E2E: admin catalog CRUD against the storefront, category merchandising, Media Library lifecycle, read-only RBAC with a limited admin, cron execution state, route sweep of every admin/storefront route.
- CI workflows: quality gate, QA, database runtime matrix (MySQL 8.4, MariaDB 10.11 / 11.4), production certification, release package build.
- Self-hosted Inter and Manrope fonts (no third-party font requests; compatible with the CSP).

### Changed
- Site capability guard is a single storefront subscriber; the admin runtime uses server-defined capability profiles.
- Release metadata, version and schema are derived from the platform version and validated by `release-contract-check`.
- `main` is the only canonical branch; `vendor/`, compiled assets and `SHA256SUMS.txt` are build artifacts and are no longer stored in git.
- Documentation consolidated: versioned capability matrices, QA notes and README sections replaced by one current copy.

### Fixed
- Installer and UI showed raw translation keys: 45 missing uk-UA/en-US keys added (installer errors and success screen, SEO descriptions, scheduler labels, checkout price error).
- Hardcoded exception literals and template strings ("OK", "Email") moved to translation catalogs.
- Schema 51 store-scope migration made atomic; media table collation pinned across MySQL and MariaDB.
- Read-only RBAC gaps for Universal Import, multilingual export, SEO redirect manager and Nova Poshta actions.
- Stale release-gate checks (demo showcase, multistore isolation, install/upgrade safety, localization) aligned with the current implementation.
- SQL accidentally extracted into a translation catalog removed, with a regression gate; DOM-sink XSS hardening in storefront updates and appearance presets.
- Unsafe defaults: reverse-proxy trust is explicit (`SYMFONY_TRUSTED_PROXIES`); fresh uninstalled storefront redirects to `/setup.php`.

## 3.5 — 3.4 lines

Extension API 2.0 with signed trusted releases, reversible-only extension migrations, rollback and quarantine; Theme SDK and bonus themes; universal CSV/XLSX import wizard with multilingual columns; SEO redirect manager; brand assets and per-store image-processing policy; core update preflight against active extensions; developer CLI (validate, test, pack).

## 3.3 — 3.0 lines

B2B accounts and price lists; gift cards and loyalty; Nova Poshta shipment lifecycle; invoices and credit notes; admin RBAC with saved views, previews and audit log; Visual Store Editor with presets and theme tokens; Backup/Restore and Feed Center; promotion/coupon engine, notification center, newsletter double opt-in, optional AI authoring; asset performance budgets; immutable `releases/current/shared` update executor.

## 2.x — 0.9

Foundation: catalog, cart, checkout, orders, payments (monobank, bank transfer, COD), shipping providers, SEO router and sitemaps, media engine, queue and scheduler, installer, Migration Center for OpenCart/ocStore, Google Commerce and marketing integrations.
