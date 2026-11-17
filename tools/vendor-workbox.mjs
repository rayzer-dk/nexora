/** Workbox v7.4.1: self-hosted, pinned release files for production builds.
 * No runtime CDN dependency is introduced into the storefront. */
import { mkdir, readFile, rename, stat, writeFile } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { createHash } from 'node:crypto';

const version = '7.4.1';
const destination = resolve('public/assets/workbox/v' + version);
const files = [
  'workbox-sw.js',
  'workbox-core.prod.js',
  'workbox-routing.prod.js',
  'workbox-strategies.prod.js',
  'workbox-precaching.prod.js',
  'workbox-cacheable-response.prod.js',
  'workbox-expiration.prod.js',
];
const source = 'https://storage.googleapis.com/workbox-cdn/releases/' + version + '/';

await mkdir(destination, { recursive: true });
const hashes = {};
for (const name of files) {
  const location = join(destination, name);
  let bytes;
  try {
    const existing = await stat(location);
    if (existing.size > 256) bytes = await readFile(location);
  } catch {
    // First build: download the version-pinned release over HTTPS.
  }
  if (!bytes) {
    const response = await fetch(source + name, { signal: AbortSignal.timeout(30000), redirect: 'error' });
    if (!response.ok) throw new Error('Workbox ' + name + ': HTTP ' + response.status);
    bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length < 256 || bytes.length > 1024 * 1024 || bytes.toString('utf8', 0, 50).includes('<Error')) {
      throw new Error('Invalid Workbox library: ' + name);
    }
    const tmp = location + '.' + process.pid + '.tmp';
    await writeFile(tmp, bytes);
    await rename(tmp, location);
  }
  hashes[name] = createHash('sha256').update(bytes).digest('hex');
}
await writeFile(join(destination, 'SHA256.json'), JSON.stringify({ version, source, files: hashes }, null, 2) + '\n');
console.log('Vendored Workbox v' + version + ' (' + files.length + ' local scripts)');
