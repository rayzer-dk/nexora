import { lucideIcon } from '../shared/lucide-icons.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
const q = (selector, root = document) => root.querySelector(selector);
const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function iconNode(name, size = 20) {
  const template = document.createElement('template');
  template.innerHTML = lucideIcon(name, size);
  return template.content.firstElementChild ?? document.createTextNode('');
}
function toast(message, type = 'success', timeout = 4200) {
  if (!message) return;
  let stack = q('[data-storefront-toast-stack]');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'storefront-toast-stack';
    stack.dataset.storefrontToastStack = '';
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);
  }
  const item = document.createElement('div');
  item.className = `storefront-toast ${type === 'error' ? 'is-error' : ''}`;
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'storefront-toast__close';
  close.setAttribute('aria-label', t('js_close'));
  close.title = t('js_close');
  close.replaceChildren(iconNode('x', 16));
  close.addEventListener('click', () => item.remove());
  item.append(text, close);
  stack.appendChild(item);
  requestAnimationFrame(() => item.classList.add('is-visible'));
  if (timeout > 0) {
    window.setTimeout(() => {
      item.classList.remove('is-visible');
      window.setTimeout(() => item.remove(), 220);
    }, timeout);
  }
}

function initStoreNotices() {
  qa('[data-store-toast-source], .global-notice').forEach((source) => {
    const message = source.textContent.trim();
    if (!message) return;
    const type = source.classList.contains('is-error') ? 'error' : 'success';
    toast(message, type, type === 'error' ? 7000 : 4200);
    source.classList.add('is-toast-mirrored');
  });
}

function initForumCompose() {
  const modal = q('[data-forum-compose]');
  if (!modal) return;
  const openers = qa('[data-forum-compose-open]');
  const closers = qa('[data-forum-compose-close]', modal);
  const firstInput = q('input:not([type="hidden"]),textarea', modal);
  const open = () => {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-storefront-modal');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    window.setTimeout(() => firstInput?.focus(), 30);
  };
  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-storefront-modal');
    window.setTimeout(() => { modal.hidden = true; }, 180);
  };
  openers.forEach((button) => button.addEventListener('click', open));
  qa('[data-forum-quote]').forEach((button) => {
    button.addEventListener('click', () => {
      const textarea = q('textarea[name="body"]', modal);
      if (!textarea) return;
      const author = String(button.dataset.forumQuoteAuthor || '').trim();
      const body = String(button.dataset.forumQuoteBody || '').trim().replace(/\r\n?/g, '\n');
      const quoted = body.split('\n').map((line) => `> ${line}`).join('\n');
      const prefix = author ? `> ${author}\n` : '';
      const insert = `${prefix}${quoted}\n\n`;
      textarea.value = textarea.value.trim() ? `${textarea.value.trim()}\n\n${insert}` : insert;
      open();
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
  });
  closers.forEach((button) => button.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
}

function initLiveSearch() {
  const form = q('[data-live-search]');
  if (!form) return;
  const input = q('input[name="q"]', form);
  const results = q('[data-live-search-results]', form);
  if (!input || !results) return;

  let timer = 0;
  let controller = null;
  let generation = 0;

  const hide = () => {
    results.hidden = true;
    results.innerHTML = '';
    form.classList.remove('has-live-results');
  };

  const render = (items, query) => {
    results.innerHTML = '';
    if (!Array.isArray(items) || !items.length) {
      const empty = document.createElement('a');
      empty.className = 'live-search__all';
      empty.href = `/catalog?q=${encodeURIComponent(query)}`;
      empty.textContent = t('js_show_search_results');
      results.appendChild(empty);
    } else {
      items.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'live-search__item';
        link.href = typeof item.url === 'string' && item.url.startsWith('/') ? item.url : '/catalog';

        const image = document.createElement('img');
        image.loading = 'lazy';
        image.alt = '';
        image.src = typeof item.image === 'string' && item.image.startsWith('/') ? item.image : '/assets/product-placeholder.svg';

        const body = document.createElement('span');
        body.className = 'live-search__body';
        const name = document.createElement('strong');
        name.textContent = String(item.name || t('js_product'));
        const meta = document.createElement('small');
        meta.textContent = String(item.availability || '');
        body.append(name, meta);

        const price = document.createElement('b');
        price.textContent = String(item.price || '');
        link.append(image, body, price);
        results.appendChild(link);
      });
      const all = document.createElement('a');
      all.className = 'live-search__all';
      all.href = `/catalog?q=${encodeURIComponent(query)}`;
      all.textContent = t('js_all_results');
      results.appendChild(all);
    }
    results.hidden = false;
    form.classList.add('has-live-results');
  };

  const search = async () => {
    const query = input.value.trim();
    if (query.length < 2) {
      controller?.abort();
      hide();
      return;
    }
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    form.classList.add('is-searching');
    try {
      const response = await fetch(`/api/storefront/search/suggest?q=${encodeURIComponent(query)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (current === generation && input.value.trim() === query) render(payload.items || [], query);
    } catch (error) {
      if (error?.name !== 'AbortError') hide();
    } finally {
      if (current === generation) form.classList.remove('is-searching');
    }
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(search, 180);
  });
  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2 && results.children.length) results.hidden = false;
  });
  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) hide();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !results.hidden) hide();
  });
}

function initMobileNavigation() {
  const nav = q('.reference-category-nav');
  const row = q('.reference-header__nav-row');
  if (!nav || !row || q('[data-mobile-catalog-toggle]', row)) return;

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'button button--primary reference-mobile-menu';
  toggle.dataset.mobileCatalogToggle = '';
  toggle.setAttribute('aria-expanded', 'false');
  const toggleLabel = document.createElement('span');
  toggleLabel.dataset.mobileMenuLabel = '';
  toggleLabel.textContent = t('js_menu');
  toggle.replaceChildren(iconNode('menu', 16), toggleLabel);
  row.appendChild(toggle);

  const drawer = document.createElement('div');
  drawer.className = 'mobile-catalog-drawer';
  drawer.hidden = true;
  const backdrop = document.createElement('div');
  backdrop.className = 'mobile-catalog-drawer__backdrop';
  backdrop.dataset.mobileMenuClose = '';
  const panel = document.createElement('aside');
  panel.className = 'mobile-catalog-drawer__panel';
  const header = document.createElement('header');
  const title = document.createElement('strong');
  title.dataset.mobileCatalogTitle = '';
  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'button button--ghost button--icon';
  closeButton.dataset.mobileMenuClose = '';
  closeButton.replaceChildren(iconNode('x', 20));
  const drawerNav = document.createElement('nav');
  header.append(title, closeButton);
  panel.append(header, drawerNav);
  drawer.append(backdrop, panel);
  panel.setAttribute('aria-label', t('js_navigation'));
  q('[data-mobile-catalog-title]', drawer).textContent = t('js_catalog_sections');
  closeButton.setAttribute('aria-label', t('js_close'));
  closeButton.setAttribute('title', t('js_close'));
  qa('a', nav).forEach((link) => drawerNav.appendChild(link.cloneNode(true)));
  const serviceLinks = qa('.reference-topbar nav a');
  if (serviceLinks.length) {
    const divider = document.createElement('span');
    divider.className = 'mobile-catalog-drawer__label';
    divider.textContent = t('js_information');
    drawerNav.appendChild(divider);
    serviceLinks.forEach((link) => drawerNav.appendChild(link.cloneNode(true)));
  }
  document.body.appendChild(drawer);

  const open = () => {
    drawer.hidden = false;
    document.body.classList.add('has-storefront-modal');
    toggle.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => drawer.classList.add('is-open'));
  };
  const close = () => {
    drawer.classList.remove('is-open');
    document.body.classList.remove('has-storefront-modal');
    toggle.setAttribute('aria-expanded', 'false');
    window.setTimeout(() => { drawer.hidden = true; }, 180);
  };
  toggle.addEventListener('click', () => drawer.hidden ? open() : close());
  qa('[data-mobile-menu-close]', drawer).forEach((node) => node.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !drawer.hidden) close();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  try { initStoreNotices(); } catch (_) {}
  try { initForumCompose(); } catch (_) {}
  try { initLiveSearch(); } catch (_) {}
  try { initMobileNavigation(); } catch (_) {}
  try { initProductCardCartActions(); } catch (_) {}
});

function initProductCardCartActions() {
  qa('[data-card-add-to-cart]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (!window.fetch) return;
      event.preventDefault();
      const button = q('button[type="submit"]', form);
      if (button?.disabled) return;
      const original = button ? Array.from(button.childNodes, (node) => node.cloneNode(true)) : [];
      if (button) { button.disabled = true; button.classList.add('is-loading'); button.textContent = t('js_add_ellipsis'); }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        let payload = {};
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload.ok) throw new Error(payload.message || t('js_add_failed'));
        qa('[data-cart-count]').forEach((counter) => {
          const count = Number(payload.cart?.count || 0);
          counter.textContent = String(count);
          counter.hidden = count < 1;
        });
        toast(payload.message || t('js_added_cart'));
      } catch (error) {
        toast(error?.message || t('js_add_failed'), 'error', 6500);
      } finally {
        if (button) { button.disabled = false; button.classList.remove('is-loading'); button.replaceChildren(...original.map((node) => node.cloneNode(true))); }
      }
    });
  });
}
function initRecentlyViewed() {
  const KEY = 'mc_recent';
  let items;
  try { items = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch { items = []; }
  if (!Array.isArray(items)) items = [];
  const track = document.querySelector('[data-recent-track]');
  const currentId = track?.dataset.id ?? '';
  if (track && currentId) {
    const { id, name, url, image, price } = track.dataset;
    items = [{ id, name, url, image, price }, ...items.filter((item) => item && item.id !== id)].slice(0, 12);
    try { localStorage.setItem(KEY, JSON.stringify(items)); } catch { /* storage unavailable */ }
  }
  const box = document.querySelector('[data-recent-list]');
  const list = box?.querySelector('[data-recent-items]');
  if (!box || !list) return;
  const safeUrl = (value) => typeof value === 'string' && value.startsWith('/') && !value.startsWith('//');
  const visible = items.filter((item) => item && item.id !== currentId && item.name && safeUrl(item.url)).slice(0, 6);
  if (!visible.length) return;
  visible.forEach((item) => {
    const li = document.createElement('li');
    const link = document.createElement('a');
    link.href = item.url;
    if (safeUrl(item.image) || /^https:\/\//.test(item.image || '')) {
      const img = document.createElement('img');
      img.src = item.image; img.alt = ''; img.width = 96; img.height = 96; img.loading = 'lazy'; img.decoding = 'async';
      link.append(img);
    }
    const name = document.createElement('span');
    name.className = 'recent-viewed__name';
    name.textContent = item.name;
    link.append(name);
    if (item.price) {
      const price = document.createElement('strong');
      price.textContent = item.price;
      link.append(price);
    }
    li.append(link);
    list.append(li);
  });
  box.hidden = false;
}
initRecentlyViewed();
initThemeToggle('mc_theme');
function initContactWidget() {
  const root = q('[data-contact-widget]');
  if (!root) return;
  const toggle = q('[data-cw-toggle]', root);
  const list = q('.cw__list', root);
  const dialog = q('[data-cw-dialog]', root);
  const setOpen = (open) => { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); list.hidden = !open; };
  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
  document.addEventListener('click', (event) => { if (!root.contains(event.target)) setOpen(false); });
  // The cookie banner sits in the same corner; lift the buttons above it while it is on screen.
  const lift = () => {
    const banner = q('.mc-consent');
    const overlaps = banner && !banner.hidden && (() => { const a = banner.getBoundingClientRect(); const b = root.getBoundingClientRect(); return a.width > 0 && a.left < b.right && a.right > b.left; })();
    root.style.setProperty('--cw-offset', overlaps ? `${Math.ceil(banner.getBoundingClientRect().height + 12)}px` : '0px');
  };
  lift();
  new MutationObserver(lift).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
  window.addEventListener('resize', lift);
  if (!dialog) return;
  q('[data-cw-callback]', root)?.addEventListener('click', () => { setOpen(false); if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', ''); });
  q('[data-cw-dialog-close]', dialog)?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  const form = q('[data-cw-form]', dialog);
  form?.addEventListener('submit', async (event) => {
    if (!window.fetch || !window.FormData) return;
    event.preventDefault();
    const button = q('button[type="submit"]', form);
    const errorBox = q('[data-cw-error]', form);
    if (errorBox) errorBox.hidden = true;
    if (button) button.disabled = true;
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('js_send_failed'));
      toast(data.message || t('js_sent'));
      form.reset();
      dialog.close();
    } catch (error) {
      if (errorBox) { errorBox.textContent = error instanceof Error ? error.message : t('js_send_failed'); errorBox.hidden = false; }
    } finally {
      if (button) button.disabled = false;
    }
  });
}
initContactWidget();


if ('serviceWorker' in navigator) {
  if (document.documentElement.dataset.pwa === '1') {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined), { once: true });
  } else {
    navigator.serviceWorker.getRegistrations().then((list) => list.forEach((registration) => registration.unregister())).catch(() => undefined);
  }
}

function initCopyLink() {
  document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy-link]') : null;
    if (!button) return;
    const url = button.getAttribute('data-copy-link') || window.location.href;
    try {
      if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(url);
      else { const field = document.createElement('input'); field.value = url; document.body.appendChild(field); field.select(); document.execCommand('copy'); field.remove(); }
      toast(t('js_link_copied'));
    } catch {
      toast(t('js_send_failed'), 'error');
    }
  });
}
initCopyLink();
