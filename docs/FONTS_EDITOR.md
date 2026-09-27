# Fonts and rich text

Storefront defaults to the operating-system font stack. It adds no external Google Fonts request, improving privacy, first render and resilience.

A store may upload licensed WOFF2/WOFF2 variable fonts into the Media/Appearance system. The theme then emits local `@font-face` declarations with `font-display: swap`. External font CDNs remain opt-in, not a core dependency.

Administration rich text uses Tiptap 3 on Vue 3. Persisted HTML is always sanitized on the server with Symfony HtmlSanitizer; editor output is never trusted merely because it came from the admin UI. Product descriptions, CMS pages and blog posts share the same editor contract.
