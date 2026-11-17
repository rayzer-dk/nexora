/**
 * Optional anonymous storefront history. Workbox caches public assets;
 * IndexedDB persists only product references after preferences consent.
 * Prices, stock, customer accounts, carts and checkout data are never stored here.
 */
const DATABASE = 'nexora-public-ui-v1';
const STORE = 'recently-viewed';
const RECORD = 'products';
const LEGACY = 'mc_recent';
const LIMIT = 12;
let pending = Promise.resolve();

export function personalizationAllowed() {
  try {
    const receipt = JSON.parse(window.localStorage.getItem('mc_consent_v1') || 'null');
    const age = Date.now() - Date.parse(receipt?.saved_at || '');
    return receipt?.preferences === true && Number.isFinite(age) && age >= 0 && age <= 180 * 86400000;
  } catch {
    return false;
  }
}

function queue(work) {
  const next = pending.then(work, work);
  pending = next.catch(() => {});
  return next;
}

function safeProduct(value) {
  if (!value || typeof value !== 'object' || typeof value.id !== 'string' ||
      typeof value.name !== 'string' || typeof value.url !== 'string') return null;
  const id = value.id.trim().slice(0, 128);
  const name = value.name.trim().slice(0, 180);
  if (!id || !name || !value.url.startsWith('/') || value.url.startsWith('//')) return null;
  let url;
  try {
    const parsed = new URL(value.url, window.location.origin);
    if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) return null;
    url = parsed.pathname; // No tracking parameters, session IDs or fragment tokens.
  } catch { return null; }
  if (['/admin', '/account', '/cart', '/checkout', '/api'].some((path) => url === path || url.startsWith(path + '/'))) return null;
  let image = '';
  if (typeof value.image === 'string' && value.image.length < 2048) {
    try {
      const parsed = new URL(value.image, window.location.origin);
      if (parsed.protocol === 'https:' || (parsed.protocol === 'http:' && parsed.origin === window.location.origin)) {
        image = parsed.origin === window.location.origin ? parsed.pathname : parsed.origin + parsed.pathname;
      }
    } catch { /* Invalid image URLs are ignored. */ }
  }
  return { id, name, url, image };
}

export function normalizeRecentHistory(raw) {
  const items = Array.isArray(raw) ? raw : [];
  const seen = new Set();
  const result = [];
  for (const value of items.slice(0, 60)) {
    const item = safeProduct(value);
    if (!item || seen.has(item.id)) continue;
    seen.add(item.id);
    result.push(item);
    if (result.length >= LIMIT) break;
  }
  return result;
}

function legacyRead() {
  try { return normalizeRecentHistory(JSON.parse(window.localStorage.getItem(LEGACY) || '[]')); }
  catch { return []; }
}

function legacyDelete() {
  try { window.localStorage.removeItem(LEGACY); } catch { /* Storage unavailable. */ }
}

async function openDb(create = true) {
  let provider;
  try { provider = window.indexedDB; } catch { return null; }
  if (!provider) return null;
  return new Promise((resolve) => {
    let finished = false;
    const complete = (value) => {
      if (finished) { value?.close?.(); return; }
      finished = true;
      resolve(value);
    };
    try {
      const request = provider.open(DATABASE, 1);
      request.onupgradeneeded = () => {
        // A fresh visitor who has not consented must not create an optional DB.
        if (!create) {
          request.transaction?.abort();
          return;
        }
        if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE);
      };
      request.onsuccess = () => complete(request.result);
      request.onerror = () => complete(null);
      request.onblocked = () => complete(null);
    } catch { complete(null); }
  });
}

async function readDb(db) {
  return new Promise((resolve) => {
    try {
      const transaction = db.transaction(STORE, 'readonly');
      const request = transaction.objectStore(STORE).get(RECORD);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => resolve(null);
      transaction.onabort = () => resolve(null);
    } catch { resolve(null); }
  });
}

async function writeDb(db, items) {
  return new Promise((resolve) => {
    try {
      const transaction = db.transaction(STORE, 'readwrite');
      transaction.objectStore(STORE).put(items, RECORD);
      transaction.oncomplete = () => resolve(true);
      transaction.onerror = () => resolve(false);
      transaction.onabort = () => resolve(false);
    } catch { resolve(false); }
  });
}

async function clearDb(db) {
  return new Promise((resolve) => {
    try {
      const transaction = db.transaction(STORE, 'readwrite');
      transaction.objectStore(STORE).delete(RECORD);
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => resolve();
      transaction.onabort = () => resolve();
    } catch { resolve(); }
  });
}

export function readRecentHistory() {
  return queue(async () => {
    if (!personalizationAllowed()) return [];
    const legacy = legacyRead();
    const db = await openDb();
    if (!db) return legacy;
    try {
      const saved = await readDb(db);
      if (!personalizationAllowed()) return [];
      if (Array.isArray(saved)) {
        // A failed IndexedDB write may leave a newer localStorage fallback.
        // Merge it first and clear the fallback only after a committed DB write.
        const normalized = normalizeRecentHistory([...legacy, ...saved]);
        if (legacy.length && await writeDb(db, normalized)) legacyDelete();
        return normalized;
      }
      if (legacy.length && await writeDb(db, legacy)) legacyDelete();
      return legacy;
    } finally { db.close(); }
  });
}

export function writeRecentHistory(raw) {
  return queue(async () => {
    if (!personalizationAllowed()) return false;
    const items = normalizeRecentHistory(raw);
    const db = await openDb();
    if (db) {
      try {
        if (!personalizationAllowed()) return false;
        if (await writeDb(db, items)) {
          legacyDelete();
          return true;
        }
      } finally { db.close(); }
    }
    if (!personalizationAllowed()) return false;
    try { window.localStorage.setItem(LEGACY, JSON.stringify(items)); return true; }
    catch { return false; }
  });
}

export function clearRecentHistory() {
  return queue(async () => {
    legacyDelete();
    const db = await openDb(false);
    if (!db) return;
    try { await clearDb(db); } finally { db.close(); }
  });
}
