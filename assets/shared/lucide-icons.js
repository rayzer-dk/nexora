// Self-hosted Lucide UI icons and official Tabler brand marks.
// Only the compact core set is bundled with storefront JavaScript. Other Lucide
// icons are obtained by name from Nexora's local, cacheable /icons.json endpoint.
import data from '../../resources/icons/lucide.json';
import tablerBrands from '../../resources/icons/tabler-brands.json';

const sizes = [14, 16, 20, 24, 32];
const validName = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const fetchedBodies = new Map();
const inFlight = new Map();

function iconBody(name) {
  return tablerBrands.icons[name] ?? data.icons[name] ?? fetchedBodies.get(name) ?? '';
}

function drawIcon(name, body, size, className, pending = false) {
  const safeSize = sizes.find((step) => Number(size) <= step) ?? 32;
  const safeClass = String(className).replace(/[^a-zA-Z0-9_:\- ]/g, '');
  const fill = name.endsWith('-filled') ? 'currentColor' : 'none';
  const pendingAttr = pending ? ` data-ui-pending-icon="${name}"` : '';
  return `<svg class="ui-icon ${safeClass}" width="${safeSize}" height="${safeSize}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"${pendingAttr}>${body}</svg>`;
}

function replacePending(name, body) {
  if (typeof document === 'undefined') return;
  const found = document.querySelectorAll('svg[data-ui-pending-icon]');
  if (!found.length) return;
  const parsed = body ? new DOMParser().parseFromString(`<svg xmlns="http://www.w3.org/2000/svg">${body}</svg>`, 'image/svg+xml').documentElement : null;
  for (const svg of found) {
    if (svg.getAttribute('data-ui-pending-icon') !== name) continue;
    if (parsed && parsed.nodeName.toLowerCase() === 'svg') {
      svg.replaceChildren(...Array.from(parsed.childNodes, (node) => document.importNode(node, true)));
    }
    svg.removeAttribute('data-ui-pending-icon');
  }
}

/** Fetch one optional Lucide icon by name. Deduplicates calls and caches the result. */
export function loadLucideIcon(name) {
  if (typeof name !== 'string' || !validName.test(name)) return Promise.resolve('');
  const body = iconBody(name);
  if (body) return Promise.resolve(body);
  if (fetchedBodies.has(name)) return Promise.resolve('');
  if (!inFlight.has(name)) {
    const request = fetch(`/icons.json?names=${encodeURIComponent(name)}`, { credentials: 'same-origin' })
      .then((response) => response.ok ? response.json() : { icons: {} })
      .then((data) => {
        const icon = typeof data?.icons?.[name] === 'string' ? data.icons[name] : '';
        fetchedBodies.set(name, icon);
        replacePending(name, icon);
        return icon;
      })
      .catch(() => {
        replacePending(name, '');
        return '';
      })
      .finally(() => inFlight.delete(name));
    inFlight.set(name, request);
  }
  return inFlight.get(name);
}

/** Synchronous icon markup. Optional icons render a link placeholder until the local icon arrives. */
export function lucideIcon(name, size = 20, className = '') {
  if (typeof name !== 'string' || !validName.test(name)) return '';
  const body = iconBody(name);
  if (body) return drawIcon(name, body, size, className);
  if (!fetchedBodies.has(name)) {
    void loadLucideIcon(name);
  }
  // Never leave a broken or empty button: show Lucide's normal link glyph while loading.
  return drawIcon(name, data.icons.link, size, className, !fetchedBodies.has(name));
}

/** Promise-based variant for modules that need the final SVG immediately on insertion. */
export async function lucideIconAsync(name, size = 20, className = '') {
  if (typeof name !== 'string' || !validName.test(name)) return '';
  const body = await loadLucideIcon(name);
  return drawIcon(name, body || data.icons.link, size, className);
}

/** Same icon as a DOM node (no HTML string sinks needed by callers). */
export function lucideIconNode(name, size = 20, className = '') {
  const markup = lucideIcon(name, size, className);
  if (!markup) return document.createComment('');
  const parsed = new DOMParser().parseFromString(markup.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" '), 'image/svg+xml').documentElement;
  return document.importNode(parsed, true);
}

/** DOM-node variant for third-party modules referencing optional Lucide icons. */
export async function lucideIconNodeAsync(name, size = 20, className = '') {
  const markup = await lucideIconAsync(name, size, className);
  if (!markup) return document.createComment('');
  const parsed = new DOMParser().parseFromString(markup.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" '), 'image/svg+xml').documentElement;
  return document.importNode(parsed, true);
}
