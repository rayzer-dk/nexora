import './cart-drawer.js';
import { lucideIcon } from '../shared/lucide-icons.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initDismissibleNotices } from '../shared/dismissible-notices.js';
import './lightbox.js';
import './chrome.js';
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

// Forms marked data-ajax-form are sent with fetch: the answer's flash messages become toasts and the page stays where it is.
// Any failure falls back to the ordinary submit, so nothing depends on JavaScript.
function initAjaxForms() {
  qa('form[data-ajax-form]').forEach((form) => {
    if (form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';
    form.addEventListener('submit', async (event) => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      const submit = form.querySelector('[type="submit"]');
      if (submit) submit.disabled = true;
      try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', redirect: 'follow' });
        if (response.redirected && /\/account\/login/.test(new URL(response.url).pathname)) {
          window.location.href = response.url;
          return;
        }
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const notices = Array.from(doc.querySelectorAll('[data-store-toast-source]')).filter((n) => n.textContent.trim() !== '');
        if (!response.ok && notices.length === 0) throw new Error('request failed');
        let failed = false;
        notices.forEach((n) => {
          const isError = n.classList.contains('is-error');
          failed = failed || isError;
          toast(n.textContent.trim(), isError ? 'error' : 'success', isError ? 7000 : 4200);
        });
        if (notices.length === 0 && form.dataset.ajaxOk) toast(form.dataset.ajaxOk, 'success');
        if (!failed && form.hasAttribute('data-ajax-reset')) form.reset();
        // a captcha challenge is single-use: the page is not reloaded, so ask for a fresh one
        form.querySelector('[data-captcha-refresh]')?.click();
        const answer = form.querySelector('input[name="mc_captcha_answer"]');
        if (answer) answer.value = '';
      } catch (_) {
        form.submit();
        return;
      } finally {
        if (submit) submit.disabled = false;
      }
    });
  });
}

function initForumToolbar() {
  qa('[data-fx-toolbar]').forEach((bar) => {
    const textarea = q('textarea', bar.closest('.fx-editor') || bar.parentElement);
    if (!textarea || bar.dataset.ready) return;
    bar.dataset.ready = '1';
    const replace = (from, to, text, selectFrom, selectTo) => {
      textarea.setRangeText(text, from, to, 'end');
      textarea.focus();
      textarea.setSelectionRange(selectFrom, selectTo);
    };
    bar.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      if (!button) return;
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      const picked = textarea.value.slice(start, end);
      if (button.dataset.fxWrap) {
        const mark = button.dataset.fxWrap;
        replace(start, end, mark + picked + mark, start + mark.length, start + mark.length + picked.length);
      } else if (button.dataset.fxLine) {
        const mark = button.dataset.fxLine;
        const lines = (picked || '').split('\n').map((line) => mark + line).join('\n');
        replace(start, end, lines, start, start + lines.length);
      } else if (button.hasAttribute('data-fx-link')) {
        const url = window.prompt('URL', 'https://');
        if (!url || !/^https?:\/\//i.test(url)) return;
        const text = picked || url;
        const md = `[${text}](${url})`;
        replace(start, end, md, start, start + md.length);
      }
    });
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
  if (!nav || !row || q('[data-mega]', row) || q('[data-mobile-catalog-toggle]', row)) return; // the mega-menu (chrome.js) is the mobile drawer

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
  try { initAjaxForms(); } catch (_) {}
  try { initForumCompose(); } catch (_) {}
  try { initForumToolbar(); } catch (_) {}
  try { initLiveSearch(); } catch (_) {}
  try { initMobileNavigation(); } catch (_) {}
  try { initProductCardCartActions(); } catch (_) {}
});

// Content swapped in by script (catalogue pages) gets the same behaviour as server-rendered content.
document.addEventListener('commerce:content-updated', () => {
  try { initAjaxForms(); } catch (_) {}
  try { initProductCardCartActions(); } catch (_) {}
});

document.addEventListener('mc:toast', (event) => toast(event.detail?.message, event.detail?.type === 'error' ? 'error' : 'success', 6000));
function initProductCardCartActions() {
  qa('[data-card-add-to-cart]').forEach((form) => {
    if (form.dataset.cardBound === '1') return;
    form.dataset.cardBound = '1';
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
        document.dispatchEvent(new CustomEvent('mc:cart-updated', { detail: { count: Number(payload.cart?.count || 0), source: 'add' } }));
        if (!window.matchMedia('(min-width: 1024px)').matches) toast(payload.message || t('js_added_cart')); // on desktop the cart drawer slides in instead
      } catch (error) {
        toast(error?.message || t('js_add_failed'), 'error', 6500);
      } finally {
        if (button) { button.disabled = false; button.classList.remove('is-loading'); button.replaceChildren(...original.map((node) => node.cloneNode(true))); }
      }
    });
  });
}
let recentlyViewedDone = false;
function initRecentlyViewed() {
  // "Recently viewed" is a personalisation feature: it stores data on the device only after "preferences" consent.
  const KEY = 'mc_recent';
  let allowed;
  try { allowed = Boolean(JSON.parse(localStorage.getItem('mc_consent_v1') || 'null')?.preferences); } catch { allowed = false; }
  if (!allowed) {
    try { localStorage.removeItem(KEY); } catch { /* storage unavailable */ }
    return;
  }
  if (recentlyViewedDone) return;
  recentlyViewedDone = true;
  let items;
  try { items = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch { items = []; }
  if (!Array.isArray(items)) items = [];
  const track = document.querySelector('[data-recent-track]');
  const currentId = track?.dataset.id ?? '';
  if (track && currentId) {
    const { id, name, url, image, price, variant } = track.dataset;
    items = [{ id, name, url, image, price, variant }, ...items.filter((item) => item && item.id !== id)].slice(0, 12);
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
      img.addEventListener('error', () => { const ph = document.createElement('span'); ph.className = 'recent-viewed__ph'; ph.setAttribute('aria-hidden', 'true'); img.replaceWith(ph); }, { once: true });
      link.append(img);
    } else {
      const ph = document.createElement('span');
      ph.className = 'recent-viewed__ph';
      ph.setAttribute('aria-hidden', 'true');
      link.append(ph);
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
    const cartToken = document.querySelector('meta[name="mc-cart-token"]')?.content;
    if (cartToken && /^[0-9a-f-]{32,36}$/i.test(item.variant || '')) {
      const form = document.createElement('form');
      form.className = 'recent-viewed__cart';
      form.method = 'post'; form.action = '/cart/add'; form.dataset.cardAddToCart = '';
      [['_token', cartToken], ['variant_id', item.variant], ['quantity', '1']].forEach(([name, value]) => {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
      });
      const button = document.createElement('button');
      button.type = 'submit'; button.className = 'button button--primary catalog-card__buy';
      button.innerHTML = '<svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>';
      const label = document.createElement('span'); label.textContent = t('js_add_to_cart'); button.append(label);
      form.append(button);
      li.append(form);
    } else {
      const wrap = document.createElement('div');
      wrap.className = 'recent-viewed__cart';
      const more = document.createElement('a');
      more.className = 'button button--secondary';
      more.href = item.url;
      more.textContent = t('js_details');
      wrap.append(more);
      li.append(wrap);
    }
    list.append(li);
  });
  box.hidden = false;
}
initRecentlyViewed();
document.addEventListener('commerce:consent-changed', initRecentlyViewed);
initThemeToggle('mc_theme');
initDismissibleNotices();
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
      window.mcCaptchaReset?.(form);
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

function initChatWidget() {
  const holder = q('[data-chat-widget]');
  if (!holder) return;
  const { provider, id, base } = holder.dataset;
  const root = () => q('[data-contact-widget]');
  const allowed = () => {
    try { return Boolean(JSON.parse(localStorage.getItem('mc_consent_v1') || 'null')?.preferences); } catch { return false; }
  };
  const add = (src, attrs = {}) => {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    for (const [name, value] of Object.entries(attrs)) script.setAttribute(name, value);
    document.head.appendChild(script);
    return script;
  };
  let loaded = false;
  const load = () => {
    if (loaded || !allowed()) return;
    loaded = true;
    if (provider === 'tawk') { window.Tawk_API = window.Tawk_API || {}; window.Tawk_LoadStart = new Date(); add(`https://embed.tawk.to/${id}`, { crossorigin: '*' }); }
    else if (provider === 'jivo') add(`https://code.jivosite.com/widget/${id}`);
    else if (provider === 'crisp') { window.$crisp = []; window.CRISP_WEBSITE_ID = id; add('https://client.crisp.chat/l.js'); }
    else if (provider === 'chatwoot') add(`${base}/packs/js/sdk.js`).onload = () => window.chatwootSDK?.run({ websiteToken: id, baseUrl: base });
    root()?.setAttribute('data-chat-loaded', '1');
  };
  const api = () => ({
    tawk: () => window.Tawk_API?.maximize && (window.Tawk_API.maximize(), true),
    jivo: () => window.jivo_api?.open && (window.jivo_api.open(), true),
    crisp: () => Array.isArray(window.$crisp) ? false : (window.$crisp.push(['do', 'chat:open']), true),
    chatwoot: () => window.$chatwoot?.toggle && (window.$chatwoot.toggle('open'), true),
  })[provider]?.() ?? false;
  const open = (attempt = 0) => { if (!api() && attempt < 20) setTimeout(() => open(attempt + 1), 500); }; // the vendor script may still be loading
  load();
  window.addEventListener('commerce:consent-changed', load);
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-cw-chat]') : null;
    if (!button) return;
    if (!allowed()) { q('[data-consent-open]')?.click(); return; } // the vendor is loaded only after the visitor allows it
    load();
    open();
  });
}
initChatWidget();

/* ---------- Sale timers, back to top, quick order, live total ---------- */
function initSaleTimers() {
  const nodes = qa('[data-sale-timer]:not(body)');
  if (!nodes.length) return;
  const pad = (n) => String(n).padStart(2, '0');
  const tick = () => {
    const now = Date.now();
    let alive = false;
    nodes.forEach((node) => {
      const end = Date.parse(node.dataset.saleTimer || '');
      const left = end - now;
      if (!Number.isFinite(end) || left <= 0) { node.hidden = true; return; }
      alive = true;
      const s = Math.floor(left / 1000);
      const d = Math.floor(s / 86400);
      const value = q('[data-sale-timer-value]', node);
      if (value) value.textContent = `${d > 0 ? `${d}${t('js_timer_days')} ` : ''}${pad(Math.floor((s % 86400) / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
    });
    if (alive) window.setTimeout(tick, 1000);
  };
  tick();
}
initSaleTimers();

function initBackToTop() {
  const button = q('[data-back-to-top]');
  if (!button) return;
  const update = () => { button.hidden = window.scrollY < 600; };
  window.addEventListener('scroll', update, { passive: true });
  button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }));
  update();
}
initBackToTop();

function initQuickOrder() {
  const dialog = q('[data-quick-order-dialog]');
  const open = q('[data-quick-order-open]');
  if (!dialog || !open) return;
  open.addEventListener('click', () => {
    const qty = q('[data-buy-actions] [data-qty-input]');
    const hidden = q('[data-quick-order-qty]', dialog);
    if (qty && hidden) hidden.value = qty.value;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  });
  q('[data-quick-order-close]', dialog)?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
}
initQuickOrder();

function initLiveTotal() {
  const form = q('form[data-buy-actions][data-unit-price]');
  const out = q('[data-live-total]', form ?? document);
  const input = form ? q('[data-qty-input]', form) : null;
  if (!form || !out || !input) return;
  const currency = form.dataset.currency;
  if (!(Number(form.dataset.unitPrice) > 0) || !currency) return;
  const format = (() => { try { return new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency }); } catch { return null; } })();
  if (!format) return;
  const update = () => {
    const unit = Number(form.dataset.unitPrice) / 100;
    const qty = Number(String(input.value).replace(',', '.'));
    if (!(qty > 0) || qty === 1) { out.hidden = true; return; }
    out.textContent = `${t('js_total')}: ${format.format(Math.round(unit * qty * 100) / 100)}`;
    out.hidden = false;
  };
  ['input', 'change'].forEach((name) => input.addEventListener(name, update));
  form.addEventListener('click', () => window.setTimeout(update, 0));
  update();
}
initLiveTotal();

/* ---------- Captcha (built-in, reCAPTCHA v2/v3, Turnstile) ---------- */
const captchaScripts = new Map();
function loadCaptchaScript(src) {
  if (!captchaScripts.has(src)) {
    captchaScripts.set(src, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src; script.async = true; script.defer = true;
      script.onload = () => resolve(); script.onerror = () => reject(new Error('captcha script'));
      document.head.append(script);
    }));
  }
  return captchaScripts.get(src);
}
async function refreshBuiltinCaptcha(box) {
  try {
    const response = await fetch('/captcha/new', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json();
    const token = q('[data-captcha-token]', box);
    if (token) token.value = data.token;
    const image = q('[data-captcha-image]', box);
    if (image && data.image) image.src = data.image;
    const question = q('[data-captcha-question]', box);
    if (question) question.textContent = data.question;
    const answer = q('input[name="mc_captcha_answer"]', box);
    if (answer) answer.value = '';
  } catch { /* offline: the visitor can retry */ }
}
function initCaptcha() {
  const boxes = qa('[data-captcha]');
  if (!boxes.length) return;
  const byProvider = (name) => boxes.filter((box) => box.dataset.captcha === name);
  byProvider('builtin').forEach((box) => q('[data-captcha-refresh]', box)?.addEventListener('click', () => refreshBuiltinCaptcha(box)));
  const turnstile = byProvider('turnstile');
  if (turnstile.length) {
    window.mcTurnstileReady = () => turnstile.forEach((box) => {
      const el = q('[data-captcha-widget]', box);
      if (el && !el.dataset.wid) el.dataset.wid = String(window.turnstile.render(el, { sitekey: box.dataset.sitekey }));
    });
    loadCaptchaScript('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=mcTurnstileReady').catch(() => {});
  }
  const v2 = byProvider('recaptcha_v2');
  if (v2.length) {
    window.mcRecaptchaReady = () => window.grecaptcha.ready(() => v2.forEach((box) => {
      const el = q('[data-captcha-widget]', box);
      if (el && !el.dataset.wid) el.dataset.wid = String(window.grecaptcha.render(el, { sitekey: box.dataset.sitekey }));
    }));
    loadCaptchaScript('https://www.google.com/recaptcha/api.js?render=explicit&onload=mcRecaptchaReady').catch(() => {});
  }
  const v3 = byProvider('recaptcha_v3');
  if (v3.length) {
    const key = v3[0].dataset.sitekey;
    const run = (box) => window.grecaptcha.ready(() => window.grecaptcha.execute(key, { action: box.dataset.captchaForm }).then((token) => {
      const field = q('[data-captcha-v3]', box);
      if (field) field.value = token;
    }).catch(() => {}));
    loadCaptchaScript(`https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(key)}`).then(() => {
      v3.forEach(run);
      window.setInterval(() => v3.forEach(run), 100000); // tokens live 2 minutes
      v3.forEach((box) => box.closest('form')?.addEventListener('focusin', () => run(box), { once: true }));
    }).catch(() => {});
  }
  window.mcCaptchaReset = (form) => {
    qa('[data-captcha]', form).forEach((box) => {
      const el = q('[data-captcha-widget]', box);
      const wid = el?.dataset.wid;
      if (box.dataset.captcha === 'builtin') refreshBuiltinCaptcha(box);
      else if (box.dataset.captcha === 'turnstile' && wid !== undefined) window.turnstile?.reset(wid);
      else if (box.dataset.captcha === 'recaptcha_v2' && wid !== undefined) window.grecaptcha?.reset(Number(wid));
      else if (box.dataset.captcha === 'recaptcha_v3') window.grecaptcha?.ready(() => window.grecaptcha.execute(box.dataset.sitekey, { action: box.dataset.captchaForm }).then((token) => { const f = q('[data-captcha-v3]', box); if (f) f.value = token; }));
    });
  };
}
/** Opt-in Web Push: nothing is requested until the visitor clicks the button. */
function initPush() {
  const button = document.querySelector('[data-push-subscribe]');
  if (!button || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
  const label = button.querySelector('span');
  const key = (b64) => { const pad = '='.repeat((4 - (b64.length % 4)) % 4); const raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, (c) => c.charCodeAt(0)); };
  const setState = (subscribed) => { button.dataset.state = subscribed ? 'on' : 'off'; label.textContent = t(subscribed ? 'push_unsubscribe' : 'push_subscribe'); };
  const post = (path, body) => fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  button.hidden = false;
  navigator.serviceWorker.getRegistration('/nexora-push-sw.js').then((registration) => registration?.pushManager.getSubscription()).then((subscription) => setState(Boolean(subscription))).catch(() => setState(false));
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      const registration = await navigator.serviceWorker.register('/nexora-push-sw.js');
      await navigator.serviceWorker.ready;
      const existing = await registration.pushManager.getSubscription();
      if (existing) {
        await post('/push/unsubscribe', { endpoint: existing.endpoint });
        await existing.unsubscribe();
        setState(false);
      } else if ((await window.Notification.requestPermission()) === 'granted') {
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(button.dataset.pushKey) });
        const json = subscription.toJSON();
        const response = await post('/push/subscribe', { endpoint: json.endpoint, keys: json.keys });
        if (!response.ok) { await subscription.unsubscribe(); throw new Error('push subscribe failed'); }
        setState(true);
      }
    } catch (_error) {
      setState(false);
    } finally {
      button.disabled = false;
    }
  });
}

initPush();
initCaptcha();

/* ---------- Ecommerce events (consent-gated tags pick them up from gtag/fbq) ---------- */
function mcTrack(name, item) {
  if (!item || !item.currency) return;
  const price = item.price;
  const params = { currency: item.currency, value: Math.round(price * item.quantity * 100) / 100, items: [{ item_id: item.id, item_name: item.name, price, quantity: item.quantity }] };
  try { window.gtag?.('event', name, params); } catch { /* tag blocked */ }
  try {
    if (typeof window.fbq === 'function') window.fbq('track', name === 'view_item' ? 'ViewContent' : 'AddToCart', { content_ids: [item.id], content_name: item.name, content_type: 'product', value: params.value, currency: item.currency });
  } catch { /* tag blocked */ }
}
function initEcommerceEvents() {
  const track = q('[data-recent-track][data-price-minor]');
  if (track) mcTrack('view_item', { id: track.dataset.sku || track.dataset.id, name: track.dataset.name, price: Number(track.dataset.priceMinor) / 100, currency: track.dataset.currency, quantity: 1 });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !/\/cart\/add$/.test(form.getAttribute('action') || '')) return;
    const card = form.closest('[data-product-card]');
    const qty = Number(String(q('[name="quantity"]', form)?.value ?? '1').replace(',', '.')) || 1;
    if (card) mcTrack('add_to_cart', { id: card.dataset.trackId, name: card.dataset.trackName, price: Number(card.dataset.trackPrice) / 100, currency: card.dataset.trackCurrency, quantity: qty });
    else if (track) mcTrack('add_to_cart', { id: track.dataset.sku || track.dataset.id, name: track.dataset.name, price: Number(track.dataset.priceMinor) / 100, currency: track.dataset.currency, quantity: qty });
  }, true);
}
initEcommerceEvents();

// Announcement bar in "static" mode with several messages: show one at a time and rotate.
(() => {
  const bar = document.querySelector('[data-announcement][data-mode="static"]');
  if (!bar) return;
  const items = Array.from(bar.querySelectorAll('[data-ann-item]'));
  if (items.length < 2) return;
  const mainLink = bar.querySelector('[data-ann-main-link]');
  bar.setAttribute('data-rotating', '');
  let index = 0;
  const show = (next) => {
    index = next % items.length;
    items.forEach((item, i) => { item.hidden = i !== index; });
    if (mainLink) mainLink.hidden = index !== 0;
  };
  show(0);
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  let timer = window.setInterval(() => show(index + 1), 5000);
  bar.addEventListener('mouseenter', () => window.clearInterval(timer));
  bar.addEventListener('mouseleave', () => { timer = window.setInterval(() => show(index + 1), 5000); });
})();

// Icons written into a text with the rich-text editor: <span class="mc-inline-icon" data-icon="name">; draw them from the icon feed.
(() => {
  const marks = Array.from(document.querySelectorAll('.mc-inline-icon[data-icon]')).filter((node) => !node.firstChild);
  if (!marks.length) return;
  const names = Array.from(new Set(marks.map((node) => node.getAttribute('data-icon')))).slice(0, 60);
  window.fetch(`/icons.json?names=${encodeURIComponent(names.join(','))}`, { credentials: 'same-origin' })
    .then((response) => (response.ok ? response.json() : { icons: {} }))
    .then((data) => {
      marks.forEach((node) => {
        const body = data.icons?.[node.getAttribute('data-icon')];
        if (!body) return;
        const svg = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + body + '</svg>', 'image/svg+xml').documentElement;
        if (svg.nodeName.toLowerCase() === 'svg') node.replaceChildren(document.importNode(svg, true));
      });
    })
    .catch(() => {});
})();
