import { readFileSync, existsSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { gzipSync, brotliCompressSync, constants } from 'node:zlib';

const root = resolve(process.cwd());
const configPath = join(root, 'config/performance/asset-budgets.json');
const budgets = JSON.parse(readFileSync(configPath, 'utf8'));
const buildDir = join(root, 'public/build');

const compressed = (file) => {
  const buf = readFileSync(file);
  return {
    raw: buf.length,
    gzip: gzipSync(buf, { level: 9 }).length,
    brotli: brotliCompressSync(buf, { params: { [constants.BROTLI_PARAM_QUALITY]: 11 } }).length,
  };
};

let failed = false;

const manifestPath = join(buildDir, '.vite/manifest.json');
if (!existsSync(manifestPath)) {
  console.error('BUDGET FAIL compiled: public/build/.vite/manifest.json is missing.');
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const fileFor = (key) => {
  const entry = manifest[key];
  if (!entry?.file) return null;
  const file = join(buildDir, entry.file);
  return existsSync(file) ? file : null;
};
// Budgets apply to the compiled files that are served (public/build); the committed public/assets copies are only a fallback.
const checks = [
  ['storefront_css', 'assets/storefront/storefront.css'],
  ['page_css_product', 'assets/storefront/pages/product.css'],
  ['page_css_cart', 'assets/storefront/pages/cart.css'],
  ['page_css_home', 'assets/storefront/pages/home.css'],
  ['page_css_account', 'assets/storefront/pages/account.css'],
  ['page_css_blog', 'assets/storefront/pages/blog.css'],
  ['page_css_forum', 'assets/storefront/forum.css'],
  ['page_css_landing', 'assets/storefront/landing.css'],
  ['admin_core_css', 'assets/admin/admin-runtime.css'],
  ['admin_feature_css', 'assets/admin/admin-builder-media.css'],
  ['admin_runtime_js', 'assets/storefront/admin-runtime.js'],
];
for (const [budgetKey, entryKey] of checks) {
  const file = fileFor(entryKey);
  if (!file) {
    failed = true;
    console.error(`BUDGET FAIL ${budgetKey}: compiled output for ${entryKey} is missing.`);
    continue;
  }
  const size = compressed(file);
  const budget = budgets[budgetKey];
  console.log(`${budgetKey}: raw=${size.raw} gzip=${size.gzip} br=${size.brotli}`);
  if (budget && size.brotli > budget.max_brotli_bytes) {
    failed = true;
    console.error(`BUDGET FAIL ${budgetKey}: ${size.brotli} > ${budget.max_brotli_bytes} Brotli bytes`);
  }
}
const collectImportKeys = (key, seen = new Set()) => {
  if (seen.has(key)) return seen;
  seen.add(key);
  const entry = manifest[key];
  for (const imported of entry?.imports ?? []) collectImportKeys(imported, seen);
  return seen;
};
const brotliTotalForEntries = (keys) => {
  const files = new Set();
  for (const key of keys) {
    for (const dependencyKey of collectImportKeys(key)) {
      const file = fileFor(dependencyKey);
      if (file && file.endsWith('.js')) files.add(file);
    }
  }
  let total = 0;
  for (const file of files) total += compressed(file).brotli;
  return { total, files: [...files] };
};

const compiled = budgets.compiled ?? {};
const storefrontKeys = [
  'assets/storefront/product-page.js',
  'assets/storefront/storefront-runtime.js',
  'assets/storefront/cart.js',
  'assets/storefront/checkout.js',
  'assets/storefront/consent.js',
  'assets/storefront/slider.js',
];
const adminKeys = [
  'assets/admin/main.ts',
  'assets/storefront/admin-runtime.js',
  'assets/admin/features/builder.js',
  'assets/admin/features/media-library.js',
];

for (const key of [...storefrontKeys, ...adminKeys]) {
  if (!manifest[key]) {
    failed = true;
    console.error(`BUDGET FAIL compiled: manifest entry missing: ${key}`);
  }
}

const storefront = brotliTotalForEntries(storefrontKeys);
const admin = brotliTotalForEntries(adminKeys);
console.log(`compiled:storefront_initial_js br=${storefront.total} files=${storefront.files.length}`);
console.log(`compiled:admin_initial_js br=${admin.total} files=${admin.files.length}`);
if (compiled.storefront_initial_js_max_brotli_bytes && storefront.total > compiled.storefront_initial_js_max_brotli_bytes) {
  failed = true;
  console.error(`BUDGET FAIL compiled storefront: ${storefront.total} > ${compiled.storefront_initial_js_max_brotli_bytes}`);
}
if (compiled.admin_initial_js_max_brotli_bytes && admin.total > compiled.admin_initial_js_max_brotli_bytes) {
  failed = true;
  console.error(`BUDGET FAIL compiled admin: ${admin.total} > ${compiled.admin_initial_js_max_brotli_bytes}`);
}

for (const [key, entry] of Object.entries(manifest)) {
  if (!entry?.file || !entry.file.endsWith('.js')) continue;
  const file = join(buildDir, entry.file);
  if (!existsSync(file)) {
    failed = true;
    console.error(`BUDGET FAIL compiled: missing output ${entry.file}`);
    continue;
  }
  const size = compressed(file);
  console.log(`vite:${key}: raw=${size.raw} gzip=${size.gzip} br=${size.brotli}`);
  if (compiled.single_lazy_chunk_max_brotli_bytes && size.brotli > compiled.single_lazy_chunk_max_brotli_bytes) {
    failed = true;
    console.error(`BUDGET FAIL compiled chunk ${entry.file}: ${size.brotli} > ${compiled.single_lazy_chunk_max_brotli_bytes}`);
  }
}

process.exit(failed ? 1 : 0);
