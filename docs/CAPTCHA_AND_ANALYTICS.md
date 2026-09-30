# Captcha and analytics (3.14.0)

## Captcha
Admin → System → Captcha. One provider per store, forms chosen individually.

| Provider | Third-party requests | Notes |
|---|---|---|
| Built-in | none | Signed expiring token, answer derived by HMAC, accepted once. Image via GD, arithmetic question otherwise. |
| reCAPTCHA v2 | Google | Checkbox. |
| reCAPTCHA v3 | Google | Invisible; requests scoring below the threshold (default 50) or with a different action are rejected. |
| Turnstile | Cloudflare | Checkbox / managed. |

Order of checks for anonymous forms: honeypot and render time → per-client rate limit → captcha. If Google or Cloudflare is unreachable the form is not blocked (the other checks stay active). Add reCAPTCHA / Turnstile to the privacy policy; the built-in captcha needs no disclosure.

Adding the widget to a template: `{% include '@storefront/components/captcha.html.twig' with {form:'contact'} only %}`; verifying: `PublicFormProtection::allow($request, $scope, $captchaFormKey)` or `CaptchaVerifier::verify($request, $formKey)`. New form keys are added to `CaptchaSettings::FORMS`.

## Analytics
Server side: sales, refunds, AOV, sources, search analytics (Admin → Analytics); GA4 Measurement Protocol, Meta Conversions API and TikTok Events API for purchases.
Client side (Admin → System → Analytics & pixels): GA4 (`G-…`), GTM (`GTM-…`) and Meta Pixel IDs. Free-form code is not accepted. Tags are inert `text/plain` placeholders until consent: Google tags need "Analytics", Meta needs "Marketing". Google Consent Mode v2 defaults are denied. Events: `view_item`, `add_to_cart` (via `gtag('event')` and `fbq`). Do not enable GA4 and a GTM container that also fires GA4 — it double counts.
