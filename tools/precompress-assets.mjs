import { existsSync, readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, extname, resolve } from 'node:path';
import { gzipSync, brotliCompressSync, constants } from 'node:zlib';

const root = resolve(process.argv[2] || 'public/assets');
const allowed = new Set(['.js', '.css', '.svg', '.json', '.html']);
let count = 0;
let candidates = 0;

const walk = (dir) => {
  for (const name of readdirSync(dir)) {
    if (name.endsWith('.gz') || name.endsWith('.br')) continue;
    const file = join(dir, name);
    const stat = statSync(file);
    if (stat.isDirectory()) {
      walk(file);
      continue;
    }
    if (!stat.isFile() || !allowed.has(extname(file))) continue;
    const data = readFileSync(file);
    if (data.length < 1024) continue;
    candidates++;
    writeFileSync(file + '.gz', gzipSync(data, { level: 9 }));
    writeFileSync(file + '.br', brotliCompressSync(data, { params: { [constants.BROTLI_PARAM_QUALITY]: 11 } }));
    count++;
  }
};

walk(root);

const viteManifest = join(root, '.vite', 'manifest.json');
if (existsSync(viteManifest) && candidates > 0 && count === 0) {
  console.error(`Precompression failed: Vite bundles were found under ${root}, but 0 files were compressed.`);
  process.exit(1);
}

console.log(`Precompressed ${count} assets in ${root}`);
