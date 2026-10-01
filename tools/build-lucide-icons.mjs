#!/usr/bin/env node
/**
 * Generates resources/icons/lucide.json from the installed @lucide/vue package.
 * Application icon names are Nexora's stable semantic names; `aliases` maps a
 * legacy/semantic name to the real Lucide icon. Every icon used by templates,
 * PHP or JS must be listed here — bin/icons-check.php enforces that.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const pkgDir = dirname(require.resolve('@lucide/vue/package.json'));
const pkg = JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8'));
const manifest = JSON.parse(readFileSync('tools/lucide-manifest.json', 'utf8'));
const aliases = manifest.aliases ?? {};
const out = { lucide_version: pkg.version, icons: {} };

const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
for (const name of [...new Set(manifest.icons)].sort()) {
  const target = aliases[name] ?? name;
  let data;
  try {
    ({ __iconData: data } = await import(pathToFileURL(join(pkgDir, 'dist/esm/icons', `${target}.mjs`)).href));
  } catch {
    console.error(`Lucide ${pkg.version} has no icon "${target}" (for "${name}")`);
    process.exit(1);
  }
  const tree = data.node;
  out.icons[name] = tree.map(([tag, attrs]) =>
    `<${tag} ${Object.entries(attrs).filter(([k]) => k !== 'key').map(([k, v]) => `${k}="${esc(v)}"`).join(' ')}/>`,
  ).join('');
}
// Custom glyphs Lucide does not ship (social network marks): raw SVG bodies from the manifest.
for (const [name, body] of Object.entries(manifest.custom ?? {})) out.icons[name] = String(body);
writeFileSync('resources/icons/lucide.json', JSON.stringify(out, null, 1) + '\n');
console.log(`lucide ${pkg.version}: ${Object.keys(out.icons).length} icons`);
