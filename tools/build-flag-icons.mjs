#!/usr/bin/env node
/**
 * Generates resources/icons/flags.json from the installed flag-icons package (MIT, 4x3 SVG): the one source of the
 * flags shown next to languages in the storefront switcher and in every admin language tab. A flag file heavier than
 * MAX_BYTES (detailed coats of arms) is left out unless tools/flag-overrides.json has a simplified version of it.
 */
import { readFileSync, readdirSync, writeFileSync, copyFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const MAX_BYTES = 5000;
const require = createRequire(import.meta.url);
const pkgDir = dirname(require.resolve('flag-icons/package.json'));
const pkg = JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8'));
const overrides = JSON.parse(readFileSync('tools/flag-overrides.json', 'utf8'));
const dir = join(pkgDir, 'flags/4x3');
const out = { flag_icons_version: pkg.version, flags: {} };

for (const file of readdirSync(dir).filter((name) => /^[a-z]{2}\.svg$/.test(name)).sort()) {
  const code = file.slice(0, 2);
  if (typeof overrides[code] === 'string') {
    out.flags[code.toUpperCase()] = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480">${overrides[code]}</svg>`;
    continue;
  }
  const svg = readFileSync(join(dir, file), 'utf8');
  if (svg.length > MAX_BYTES) continue;
  out.flags[code.toUpperCase()] = svg.replace(/\s+id="flag-icons-[a-z-]+"/, '').replace(/>\s+</g, '><').replace(/\s{2,}/g, ' ').trim();
}
writeFileSync('resources/icons/flags.json', JSON.stringify(out) + '\n');
copyFileSync(join(pkgDir, 'LICENSE'), 'resources/icons/FLAG-ICONS-LICENSE.txt');
console.log(`flag-icons ${pkg.version}: ${Object.keys(out.flags).length} flags`);
