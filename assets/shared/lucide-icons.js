// Real Lucide icons, generated from the installed package by tools/build-lucide-icons.mjs.
import data from '../../resources/icons/lucide.json';

const sizes = [14, 16, 20, 24, 32];

export function lucideIcon(name, size = 20, className = '') {
  const body = data.icons[name];
  if (!body) return '';
  const safeSize = sizes.find((step) => Number(size) <= step) ?? 32;
  const safeClass = String(className).replace(/[^a-zA-Z0-9_:\- ]/g, '');
  const fill = name.endsWith('-filled') ? 'currentColor' : 'none';
  return `<svg class="ui-icon ${safeClass}" width="${safeSize}" height="${safeSize}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${body}</svg>`;
}

/** Same icon as a DOM node (no HTML string sinks needed by callers). */
export function lucideIconNode(name, size = 20, className = '') {
  const markup = lucideIcon(name, size, className);
  if (!markup) return document.createComment('');
  // The XML parser only creates SVG-namespaced elements when the namespace is declared; without it the node is an inert
  // unknown element (zero size, no stroke) and the icon is invisible.
  const parsed = new DOMParser().parseFromString(markup.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" '), 'image/svg+xml').documentElement;
  return document.importNode(parsed, true);
}
