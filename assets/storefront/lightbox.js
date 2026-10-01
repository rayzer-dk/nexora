/*
 * Full-screen photo viewer for content pages (blog articles, information pages).
 * Every image inside [data-lightbox-scope] that is not a link and is large enough becomes clickable;
 * the images of one scope form a gallery with previous / next buttons, arrow keys and swipe.
 */
import { lucideIconNode } from '../shared/lucide-icons.js';

const t = (key, replace = {}) => {
  let value = String(window.MC_I18N?.[key] ?? key);
  for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement));
  return value;
};

function collect(scope) {
  return [...scope.querySelectorAll('img')].filter((img) => {
    if (img.closest('a, button, [data-lightbox-skip], .article-rail')) return false;
    const w = img.naturalWidth || Number(img.getAttribute('width')) || 0;
    return w >= 160 || img.matches('[data-lightbox-item] img');
  });
}

function build() {
  const root = document.createElement('div');
  root.className = 'lightbox';
  root.hidden = true;
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-modal', 'true');
  root.setAttribute('aria-label', t('js_lightbox_open'));
  const button = (cls, label, icon) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `lightbox__btn ${cls}`;
    b.setAttribute('aria-label', label);
    b.title = label;
    b.append(lucideIconNode(icon, 24));
    return b;
  };
  const close = button('lightbox__close', t('js_lightbox_close'), 'x');
  const prev = button('lightbox__prev', t('js_lightbox_prev'), 'chevron-left');
  const next = button('lightbox__next', t('js_lightbox_next'), 'chevron-right');
  const figure = document.createElement('figure');
  figure.className = 'lightbox__figure';
  const image = document.createElement('img');
  image.className = 'lightbox__image';
  image.alt = '';
  const caption = document.createElement('figcaption');
  caption.className = 'lightbox__caption';
  figure.append(image, caption);
  root.append(close, prev, figure, next);
  document.body.append(root);
  return { root, close, prev, next, image, caption };
}

function init() {
  const scopes = [...document.querySelectorAll('[data-lightbox-scope]')];
  if (!scopes.length) return;
  let ui = null;
  let items = [];
  let index = 0;
  let opener = null;

  const show = (i) => {
    if (!items.length) return;
    index = (i + items.length) % items.length;
    const img = items[index];
    const src = img.currentSrc || img.src;
    ui.image.src = src;
    ui.image.alt = img.alt || '';
    const text = [img.alt, items.length > 1 ? t('js_lightbox_counter', { n: index + 1, total: items.length }) : ''].filter(Boolean).join(' · ');
    ui.caption.textContent = text;
    ui.prev.hidden = ui.next.hidden = items.length < 2;
  };
  const open = (list, i, from) => {
    ui ??= build();
    items = list;
    opener = from;
    ui.root.hidden = false;
    document.body.classList.add('has-lightbox');
    show(i);
    ui.close.focus();
  };
  const close = () => {
    if (!ui || ui.root.hidden) return;
    ui.root.hidden = true;
    ui.image.removeAttribute('src');
    document.body.classList.remove('has-lightbox');
    opener?.focus?.({ preventScroll: true });
  };

  scopes.forEach((scope) => {
    scope.addEventListener('click', (event) => {
      const img = event.target instanceof Element ? event.target.closest('img') : null;
      if (!img || !scope.contains(img)) return;
      const list = collect(scope);
      const i = list.indexOf(img);
      if (i < 0) return;
      event.preventDefault();
      open(list, i, img);
    });
    collect(scope).forEach((img) => img.classList.add('is-zoomable'));
  });

  document.addEventListener('click', (event) => {
    if (!ui || ui.root.hidden) return;
    const target = event.target;
    if (target === ui.close || ui.close.contains(target)) close();
    else if (target === ui.prev || ui.prev.contains(target)) show(index - 1);
    else if (target === ui.next || ui.next.contains(target)) show(index + 1);
    else if (target === ui.root || target.classList?.contains('lightbox__figure')) close();
  });
  document.addEventListener('keydown', (event) => {
    if (!ui || ui.root.hidden) return;
    if (event.key === 'Escape') close();
    else if (event.key === 'ArrowLeft') show(index - 1);
    else if (event.key === 'ArrowRight') show(index + 1);
    else if (event.key === 'Tab') {
      const focusable = [...ui.root.querySelectorAll('button:not([hidden])')];
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  let startX = null;
  document.addEventListener('touchstart', (event) => { startX = ui && !ui.root.hidden ? event.touches[0].clientX : null; }, { passive: true });
  document.addEventListener('touchend', (event) => {
    if (startX === null) return;
    const dx = event.changedTouches[0].clientX - startX;
    startX = null;
    if (Math.abs(dx) > 48) show(index + (dx < 0 ? 1 : -1));
  }, { passive: true });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
