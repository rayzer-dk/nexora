/**
 * Consent-gated, bounded history of public product references in localStorage.
 * IndexedDB was used by an earlier release; existing entries are migrated once
 * and its old database is deleted. Neither cache nor history stores live commerce data.
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
    url = parsed.pathname;
  } catch { return null; }
  if (['/admin', '/account', '/cart', '/checkout', '/api'].some((path) => url === path || url.startsWith(path + '/'))) return null;
  let image = '';
  if (typeof value.image === 'string' && value.image.length < 2048) {
    try {
      const parsed = new URL(value.image, window.location.origin);
      if (parsed.protocol === 'https:' || (parsed.protocol === 'http:' && parsed.origin === window.location.origin)) {
        image = parsed.origin === window.location.origin ? parsed.pathname : parsed.origin + parsed.pathname;
      }
    } catch { /* Invalid image URL. */ }
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

function storedHistory() {
  try { return normalizeRecentHistory(JSON.parse(window.localStorage.getItem(LEGACY) || '[]')); }
  catch { return []; }
}

function clearStoredHistory() {
  try { window.localStorage.removeItem(LEGACY); } catch { /* Storage unavailable. */ }
}

function oldProvider() {
  try { return window.indexedDB || null; } catch { return null; }
}

async function oldDatabaseExists(provider) {
  if (typeof provider.databases !== 'function') return true;
  try {
    const databases = await provider.databases();
    return !Array.isArray(databases) || databases.some((db) => db.name === DATABASE);
  } catch { return true; }
}

// Read the previous version's history without ever creating a new optional DB.
async function readOldDatabase() {
  const provider = oldProvider();
  if (!provider || !(await oldDatabaseExists(provider))) return [];
  return new Promise((resolve) => {
    try {
      const request = provider.open(DATABASE);
      request.onupgradeneeded = () => request.transaction?.abort();
      request.onblocked = () => resolve([]);
      request.onerror = () => resolve([]);
      request.onsuccess = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE)) { db.close(); resolve([]); return; }
        try {
          const tx = db.transaction(STORE, 'readonly');
          const record = tx.objectStore(STORE).get(RECORD);
          record.onsuccess = () => {
            const items = normalizeRecentHistory(record.result);
            db.close();
            resolve(items);
          };
          record.onerror = () => { db.close(); resolve([]); };
          tx.onabort = () => { db.close(); resolve([]); };
        } catch { db.close(); resolve([]); }
      };
    } catch { resolve([]); }
  });
}

async function removeOldDatabase() {
  const provider = oldProvider();
  if (!provider || !(await oldDatabaseExists(provider))) return;
  await new Promise((resolve) => {
    try {
      const request = provider.deleteDatabase(DATABASE);
      request.onsuccess = () => resolve();
      request.onerror = () => resolve();
      request.onblocked = () => resolve();
    } catch { resolve(); }
  });
}

export function readRecentHistory() {
  return queue(async () => {
    if (!personalizationAllowed()) return [];
    const current = storedHistory();
    const previous = await readOldDatabase();
    if (!personalizationAllowed()) return [];
    const merged = normalizeRecentHistory([...current, ...previous]);
    try {
      if (merged.length) window.localStorage.setItem(LEGACY, JSON.stringify(merged));
      // Delete the former IndexedDB data only after committing the migrated copy.
      await removeOldDatabase();
    } catch {
      // If localStorage is unavailable, preserve the previous database for a later migration.
    }
    return merged;
  });
}

export function writeRecentHistory(raw) {
  return queue(async () => {
    if (!personalizationAllowed()) return false;
    try {
      window.localStorage.setItem(LEGACY, JSON.stringify(normalizeRecentHistory(raw)));
      return true;
    } catch { return false; }
  });
}

export function clearRecentHistory() {
  return queue(async () => {
    clearStoredHistory();
    await removeOldDatabase();
  });
}
