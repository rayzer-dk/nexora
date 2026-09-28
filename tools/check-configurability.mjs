import fs from 'node:fs';

const settings = fs.readFileSync('src/Modules/Appearance/Infrastructure/StorefrontPresentationSettings.php', 'utf8');
const base = fs.readFileSync('themes/default/templates/base.html.twig', 'utf8');
const home = fs.readFileSync('themes/default/templates/home.html.twig', 'utf8');
const admin = fs.readFileSync('themes/default/templates/admin/appearance/storefront.html.twig', 'utf8');
const css = fs.readFileSync('assets/storefront/storefront.css', 'utf8');

const required = [
  ['theme.font', "settings.theme.font", 'data-theme-font'],
  ['theme.primary', "settings.theme.primary", '--mc-color-primary'],
  ['theme.accent', "settings.theme.accent", '--mc-color-accent'],
  ['theme.success', "settings.theme.success", '--mc-color-success'],
  ['theme.surface', "settings.theme.surface", '--mc-color-surface'],
  ['theme.radius', "settings.theme.radius", '--mc-radius-lg'],
  ['theme.container', "settings.theme.container", '--mc-content-max'],
  ['brand.logo', 'settings.brand.logo', 'presentation.brand.logo'],
  ['brand.favicon', 'settings.brand.favicon', 'presentation.brand.favicon'],
  ['header.search_placeholder', 'settings.header.search_placeholder', 'presentation.header.search_placeholder'],
  ['home visibility', 'settings.home.show_products', 'presentation.home.show_products'],
  ['hero', 'settings.hero.title', 'presentation.hero.title'],
  ['promo left', 'settings.promo_left.title', 'presentation.promo_left.title'],
  ['promo right', 'settings.promo_right.title', 'presentation.promo_right.title'],
];

const haystack = `${settings}\n${base}\n${home}\n${admin}\n${css}`;
const missing = required.filter(([, ...needles]) => needles.some((needle) => !haystack.includes(needle)));
if (missing.length) {
  console.error('Appearance settings are saved but not wired through the runtime:');
  for (const [name] of missing) console.error(`- ${name}`);
  process.exit(1);
}

if (/const presets=\s*\{\s*modern:/s.test(admin)) {
  console.error('Theme presets are hard-coded in the template. Use config/appearance/presets.json.');
  process.exit(1);
}

console.log('Appearance configurability audit: OK');
