# Asset Performance

Production assets are built before release; the storefront and admin never minify source files during a request.

## Rules

- Vite production build uses tree-shaking, JS/CSS minification, CSS code splitting and content-hashed files.
- Tiptap, Chart.js, Lucide and Vue/Pinia are split into dedicated vendor chunks when referenced by the compiled Vue application.
- Admin Builder and Media Library use feature CSS and JavaScript that load only on their own pages.
- The generic admin shell no longer downloads the storefront stylesheet.
- Heavy optional integrations remain server-side/background features and do not add browser assets to normal storefront requests.
- Brotli/Gzip files are generated at release time, not at runtime.
- Hashed production assets use a long immutable cache; unhashed compatibility assets use a short revalidating cache.

## Commands

`npm run build:production` builds, checks bundle budgets and precompresses compiled assets.

`npm run assets:audit` checks the source compatibility assets even when node_modules/dist are not present.

`npm run assets:compress` generates .gz/.br files for compatibility assets when preparing a manually deployed build.

## Budget policy

Budgets live in `config/performance/asset-budgets.json`. A release must fail rather than silently ship a large regression. Large editors, charts and builders are allowed to be substantial lazy chunks; they must not inflate the initial storefront bundle.
