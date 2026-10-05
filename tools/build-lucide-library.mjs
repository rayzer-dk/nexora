#!/usr/bin/env node
/**
 * Generates resources/icons/lucide-library.json: every icon of the installed @lucide/vue package.
 * The admin icon picker offers all of them; templates keep using the curated lucide.json first
 * (bin/icons-check.php still guards that), the library only answers icons an owner picked in the admin.
 */
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const pkgDir = dirname(require.resolve('@lucide/vue/package.json'));
const pkg = JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8'));
const dir = join(pkgDir, 'dist/esm/icons');
const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
const icons = {};
const seen = new Map();
for (const file of readdirSync(dir).filter((f) => f.endsWith('.mjs')).sort()) {
  const name = file.slice(0, -4);
  const { __iconData: data } = await import(pathToFileURL(join(dir, file)).href);
  if (!data?.node) continue;
  const body = data.node.map(([tag, attrs]) => `<${tag} ${Object.entries(attrs).filter(([k]) => k !== 'key').map(([k, v]) => `${k}="${esc(v)}"`).join(' ')}/>`).join('');
  // Deprecated aliases re-export the same drawing under another name: keep the first one only.
  if (seen.has(body)) continue;
  seen.set(body, name);
  icons[name] = body;
}
writeFileSync('resources/icons/lucide-library.json', JSON.stringify({ lucide_version: pkg.version, icons }) + '\n');
console.log(`lucide ${pkg.version}: ${Object.keys(icons).length} icons in the library`);
