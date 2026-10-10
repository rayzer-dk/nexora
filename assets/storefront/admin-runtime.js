import '../admin/admin-runtime.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initDismissibleNotices } from '../shared/dismissible-notices.js';
import { lucideIconNode } from '../shared/lucide-icons.js';
import { decorateLangTabs } from '../shared/flags.js';

const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
const q = (selector, root = document) => root.querySelector(selector);
const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

// Closing a modal must not scroll the page: remember the position at open time and restore it afterwards.
(() => {
  const proto = window.HTMLDialogElement?.prototype;
  if (!proto || proto.__mcScrollKeeper) return;
  const original = proto.showModal;
  proto.showModal = function showModalKeepingScroll(...args) {
    const x = window.scrollX;
    const y = window.scrollY;
    this.addEventListener('close', () => {
      const restore = () => window.scrollTo(x, y);
      restore();
      requestAnimationFrame(restore);
    }, { once: true });
    return original.apply(this, args);
  };
  proto.__mcScrollKeeper = true;
})();

function toast(message, type = 'info', timeout = 5200) {
  let stack = q('[data-admin-toast-stack]');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'admin-toast-stack';
    stack.dataset.adminToastStack = '';
    document.body.appendChild(stack);
  }
  const item = document.createElement('div');
  item.className = `admin-toast is-${type}`;
  item.setAttribute('role', type === 'error' ? 'alert' : 'status');
  const icons = { success: 'circle-check', warning: 'triangle-alert', error: 'circle-alert', info: 'info' };
  const icon = lucideIconNode(icons[type] || 'info', 20);
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'admin-toast__close';
  close.replaceChildren(lucideIconNode('x', 16));
  close.setAttribute('aria-label', t('js_close'));
  close.setAttribute('title', t('js_close'));
  close.addEventListener('click', () => item.remove());
  item.append(icon, text, close);
  stack.appendChild(item);
  requestAnimationFrame(() => item.classList.add('is-visible'));
  if (timeout > 0) {
    window.setTimeout(() => {
      item.classList.remove('is-visible');
      window.setTimeout(() => item.remove(), 220);
    }, timeout);
  }
}

window.mcAdminToast = toast;

function initFlashToasts() {
  qa('[data-toast-source], .admin-notice').forEach((source) => {
    const message = source.textContent.trim();
    if (!message) return;
    const type = source.classList.contains('is-error') ? 'error' : source.classList.contains('is-warning') ? 'warning' : source.classList.contains('is-success') ? 'success' : 'info';
    toast(message, type);
    source.classList.add('is-toast-mirrored');
  });
}

// Admin forms marked data-admin-ajax (or a submit button marked data-admin-ajax-submit) are sent with fetch. The notices of the answer
// become toasts, the page stays put, and data-admin-ajax-refresh names iframes to reload. A failure falls back to the normal submit.
function initAdminAjaxForms() {
  qa('form[data-admin-ajax]').forEach((form) => {
    if (form.dataset.adminAjaxBound === '1') return;
    form.dataset.adminAjaxBound = '1';
    form.addEventListener('submit', async (event) => {
      const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      if (submitter && form.dataset.adminAjax === 'buttons' && !submitter.hasAttribute('data-admin-ajax-submit')) return;
      if (event.defaultPrevented) return;
      event.preventDefault();
      const target = submitter?.getAttribute('formaction') || form.action;
      const body = new FormData(form);
      if (submitter?.getAttribute('name')) body.append(submitter.getAttribute('name') || '', submitter.getAttribute('value') || '');
      if (submitter instanceof HTMLButtonElement) submitter.disabled = true;
      try {
        const response = await fetch(target, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', redirect: 'follow' });
        if (response.redirected && /\/admin\/login/.test(new URL(response.url).pathname)) {
          window.location.href = response.url;
          return;
        }
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const notices = Array.from(doc.querySelectorAll('[data-toast-source], .admin-notice')).filter((n) => n.textContent.trim() !== '');
        if (!response.ok && notices.length === 0) throw new Error('request failed');
        notices.forEach((n) => {
          const type = n.classList.contains('is-error') ? 'error' : n.classList.contains('is-warning') ? 'warning' : n.classList.contains('is-success') ? 'success' : 'info';
          toast(n.textContent.trim(), type);
        });
        qa('input[type="password"]', form).forEach((input) => { if (input instanceof HTMLInputElement) input.value = ''; });
        const replace = form.dataset.adminAjaxReplace;
        if (replace) {
          replace.split(',').forEach((selector) => {
            const current = q(selector.trim());
            const next = doc.querySelector(selector.trim());
            if (!current || !next) return;
            // The notices were shown as toasts already; the dialogs and the page's own scripts keep working on the new content.
            qa('.admin-notice, [data-toast-source]', next).forEach((node) => node.remove());
            current.replaceChildren(...Array.from(next.childNodes).map((node) => document.importNode(node, true)));
          });
          initDirtyGuard();
          initAdminAjaxForms();
        }
        const refresh = form.dataset.adminAjaxRefresh;
        if (refresh) qa(refresh).forEach((frame) => { try { frame.contentWindow?.location.reload(); } catch (_) { /* a frame from another origin stays as it is */ } });
      } catch (_) {
        form.submit();
        return;
      } finally {
        if (submitter instanceof HTMLButtonElement) submitter.disabled = false;
      }
    });
  });
}

function ensureConfirmDialog() {
  let modal = q('[data-admin-confirm]');
  if (modal) return modal;
  modal = document.createElement('div');
  modal.className = 'admin-modal';
  modal.dataset.adminConfirm = '';
  modal.hidden = true;
  const make = (tag, className, attrs = {}) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
    return node;
  };
  const dialog = make('section', 'admin-modal__dialog', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'admin-confirm-title' });
  const iconWrap = make('span', 'admin-modal__icon');
  iconWrap.append(lucideIconNode('triangle-alert', 20));
  const title = make('h2', '', { id: 'admin-confirm-title' });
  const message = make('p', '', { 'data-confirm-message': '' });
  const actions = make('div', 'admin-modal__actions');
  actions.append(make('button', 'admin-button', { type: 'button', 'data-confirm-cancel': '' }), make('button', 'admin-button is-danger', { type: 'button', 'data-confirm-accept': '' }));
  const closeButton = make('button', 'admin-modal__close', { type: 'button', 'data-confirm-cancel': '', 'aria-label': t('js_close'), title: t('js_close') });
  closeButton.append(lucideIconNode('x', 18));
  dialog.append(closeButton, iconWrap, title, message, actions);
  modal.replaceChildren(make('div', 'admin-modal__backdrop', { 'data-confirm-cancel': '' }), dialog);
  q('#admin-confirm-title', modal).textContent = t('js_confirm_title');
  q('.admin-modal__actions [data-confirm-cancel]', modal).textContent = t('js_cancel');
  q('[data-confirm-accept]', modal).textContent = t('js_continue');
  document.body.appendChild(modal);
  return modal;
}

// Icon picker: every Lucide icon in a blurred modal; the owner picks the icon for a card, banner or menu entry.
// Markup: <div data-icon-picker><input type="hidden" data-icon-input name="…" value="…"><button type="button" data-icon-open>…</button></div>
let iconLibraryPromise = null;
function loadIconLibrary() {
  iconLibraryPromise ??= window.fetch('/admin/icons.json', { credentials: 'same-origin' })
    .then((response) => (response.ok ? response.json() : Promise.reject(new Error('icons'))))
    .then((data) => data.icons || {})
    .catch(() => { iconLibraryPromise = null; return {}; });
  return iconLibraryPromise;
}

function iconSvg(body, size = 20) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  for (const [name, value] of Object.entries({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.75, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })) svg.setAttribute(name, String(value));
  svg.classList.add('ui-icon');
  // The bodies come from our own icon library file, never from user input.
  svg.innerHTML = body;
  return svg;
}

function ensureIconDialog() {
  let modal = q('[data-icon-dialog]');
  if (modal) return modal;
  modal = document.createElement('div');
  modal.className = 'admin-modal admin-icon-modal';
  modal.hidden = true;
  modal.setAttribute('data-icon-dialog', '');
  modal.innerHTML = `<div class="admin-modal__backdrop" data-icon-close></div>
    <section class="admin-modal__dialog admin-icon-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="admin-icon-title">
      <header class="admin-icon-modal__head"><h2 id="admin-icon-title"></h2><button class="admin-modal__close" type="button" data-icon-close></button></header>
      <input class="admin-icon-modal__search" type="search" data-icon-search autocomplete="off">
      <div class="admin-icon-modal__meta"><span data-icon-count></span><button type="button" class="admin-button is-sm" data-icon-none></button></div>
      <div class="admin-icon-modal__grid" data-icon-grid role="listbox"></div>
    </section>`;
  q('#admin-icon-title', modal).textContent = t('js_icon_title');
  const close = q('.admin-modal__close', modal);
  close.setAttribute('aria-label', t('js_close'));
  close.append(lucideIconNode('x', 18));
  q('[data-icon-search]', modal).setAttribute('placeholder', t('js_icon_search'));
  q('[data-icon-none]', modal).textContent = t('js_icon_none');
  document.body.appendChild(modal);
  return modal;
}

function initIconPickers() {
  const fields = qa('[data-icon-picker]');
  // The picker is also offered to scripts (the text editor inserts icons into the text): window.mcPickIcon(current) → Promise<name|null>.
  const modal = ensureIconDialog();
  const grid = q('[data-icon-grid]', modal);
  const search = q('[data-icon-search]', modal);
  const count = q('[data-icon-count]', modal);
  const none = q('[data-icon-none]', modal);
  let library = {};
  let names = [];
  let shown = [];
  let rendered = 0;
  let active = null;
  const CHUNK = 240;

  const paint = (field, name, silent = false) => {
    const input = q('[data-icon-input]', field);
    const preview = q('[data-icon-preview]', field);
    const label = q('[data-icon-name]', field);
    input.value = name;
    if (preview) preview.replaceChildren(name && library[name] ? iconSvg(library[name], 20) : document.createTextNode(''));
    if (label) label.textContent = name || t('js_icon_none');
    if (!silent) input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const renderMore = () => {
    const fragment = document.createDocumentFragment();
    const current = active && typeof active.resolve !== 'function' ? q('[data-icon-input]', active).value : '';
    for (const name of shown.slice(rendered, rendered + CHUNK)) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'admin-icon-modal__item';
      button.setAttribute('role', 'option');
      button.dataset.icon = name;
      button.title = name;
      if (name === current) { button.classList.add('is-active'); button.setAttribute('aria-selected', 'true'); }
      button.append(iconSvg(library[name], 24));
      const caption = document.createElement('span');
      caption.textContent = name;
      button.append(caption);
      fragment.append(button);
    }
    rendered += CHUNK;
    grid.append(fragment);
  };

  const filter = () => {
    const needle = search.value.trim().toLowerCase().replace(/\s+/g, '-');
    shown = needle ? names.filter((name) => name.includes(needle)) : names;
    rendered = 0;
    grid.replaceChildren();
    grid.scrollTop = 0;
    count.textContent = t('js_icon_count', { count: shown.length });
    if (!shown.length) {
      const empty = document.createElement('p');
      empty.className = 'admin-help';
      empty.textContent = t('js_icon_empty');
      grid.append(empty);
      return;
    }
    renderMore();
  };

  const closeSilently = () => {
    modal.hidden = true;
    modal.classList.remove('is-open');
    document.body.classList.remove('has-admin-modal');
  };
  const close = () => {
    closeSilently();
    if (active && typeof active.resolve === 'function') active.resolve(null);
    else active?.querySelector?.('[data-icon-open]')?.focus();
    active = null;
  };

  grid.addEventListener('scroll', () => { if (rendered < shown.length && grid.scrollTop + grid.clientHeight > grid.scrollHeight - 240) renderMore(); });
  search.addEventListener('input', filter);
  qa('[data-icon-close]', modal).forEach((node) => node.addEventListener('click', close));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) { event.preventDefault(); close(); } });
  grid.addEventListener('click', (event) => {
    const item = event.target.closest('[data-icon]');
    if (!item || !active) return;
    if (typeof active.resolve === 'function') { const done = active.resolve; active = null; closeSilently(); done(item.dataset.icon); return; }
    paint(active, item.dataset.icon);
    close();
  });
  none.addEventListener('click', () => {
    if (active && typeof active.resolve === 'function') { const done = active.resolve; active = null; closeSilently(); done(''); return; }
    if (active) paint(active, '');
    close();
  });

  const open = async (field) => {
    active = field;
    modal.hidden = false;
    document.body.classList.add('has-admin-modal');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    none.hidden = typeof field.resolve !== 'function' && !field.hasAttribute('data-icon-clearable');
    if (!names.length) {
      count.textContent = t('js_icon_loading');
      library = await loadIconLibrary();
      names = Object.keys(library).sort();
    }
    search.value = '';
    filter();
    const current = q('.is-active', grid);
    if (current) current.scrollIntoView({ block: 'center' });
    search.focus();
  };

  // Show the chosen icon in the field right away (the page only renders the name).
  loadIconLibrary().then((data) => {
    library = data;
    fields.forEach((field) => { const input = q('[data-icon-input]', field); paint(field, input.value, true); });
  });
  fields.forEach((field) => q('[data-icon-open]', field)?.addEventListener('click', () => open(field)));
  window.mcPickIcon = () => new Promise((resolve) => { open({ resolve }); });
}

// Dates arrive from the database with seconds, microseconds and a zone; people read "2026-10-05 04:00".
function tidyTimestamps() {
  const pattern = /^(\s*)(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}):\d{2}(?:\.\d+)?(?:Z|[+-]00:?00)?(\s*)$/;
  const walker = document.createTreeWalker(q('main') || document.body, window.NodeFilter.SHOW_TEXT);
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);
  nodes.forEach((node) => {
    if (node.parentElement?.closest('input, textarea, script, style, pre, code, [data-keep-timestamp]')) return;
    const match = node.nodeValue.match(pattern);
    if (match) {
      node.nodeValue = `${match[1]}${match[2]} ${match[3]}${match[4]}`;
      node.parentElement?.closest('td')?.classList.add('is-nowrap');
    }
  });
}

// Fields that exist once per store language (label[data-lang]) get language tabs, like OpenCart: pick a language once and every
// such group on the page follows; a tab shows a mark when that language has text.
function initFieldLangTabs() {
  const labels = qa('main [data-lang]');
  if (!labels.length) return;
  const scopeOf = (el) => el.closest('fieldset, details, .admin-benefit, .admin-method, .admin-method__own, .admin-panel, form') || document.body;
  const scopes = new Map();
  labels.forEach((label) => {
    const scope = scopeOf(label);
    if (!scopes.has(scope)) scopes.set(scope, []);
    scopes.get(scope).push(label);
  });
  let saved = '';
  try { saved = window.localStorage.getItem('mc_admin_lang') || ''; } catch (_) { /* storage may be blocked */ }
  const bars = [];
  const select = (code) => {
    bars.forEach(({ scope, items, tabs }) => {
      const has = items.some((item) => item.dataset.lang === code);
      const active = has ? code : items[0].dataset.lang;
      items.forEach((item) => { item.hidden = item.dataset.lang !== active; });
      tabs.forEach((tab) => { const on = tab.dataset.code === active; tab.setAttribute('aria-selected', on ? 'true' : 'false'); tab.closest('.lang-tabs__item').classList.toggle('is-current', on); });
      scope.classList.add('has-lang-tabs');
    });
    try { window.localStorage.setItem('mc_admin_lang', code); } catch (_) { /* ignore */ }
  };
  const mark = (items, code) => items.filter((item) => item.dataset.lang === code).some((item) => Array.from(item.querySelectorAll('input, textarea')).some((field) => field.value.trim() !== ''));
  // Pages with many such groups (delivery and payment methods, benefits) get ONE language bar at the top for the whole page.
  const single = scopes.size > 2;
  const groups = single ? new Map([[q('main') || document.body, labels]]) : scopes;
  groups.forEach((items, scope) => {
    const codes = Array.from(new Set(items.map((item) => item.dataset.lang)));
    if (codes.length < 2) return;
    const nav = document.createElement('nav');
    nav.className = 'lang-tabs lang-tabs--inline';
    nav.setAttribute('aria-label', t('js_lang_tabs'));
    const list = document.createElement('ul');
    list.className = 'lang-tabs__list';
    const tabs = [];
    codes.forEach((code) => {
      const first = items.find((item) => item.dataset.lang === code);
      const li = document.createElement('li');
      li.className = 'lang-tabs__item';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'lang-tabs__tab';
      button.dataset.code = code;
      button.lang = code; button.setAttribute('lang', code);
      const short = document.createElement('span'); short.className = 'lang-tabs__code'; short.textContent = code.slice(0, 2).toUpperCase();
      const name = document.createElement('span'); name.className = 'lang-tabs__name'; name.textContent = first.dataset.langName || code;
      const flag = document.createElement('span'); flag.className = 'lang-tabs__mark'; flag.textContent = mark(items, code) ? '✓' : '•';
      li.classList.add(mark(items, code) ? 'is-done' : 'is-missing');
      button.append(short, name, flag);
      button.addEventListener('click', () => select(code));
      li.append(button);
      list.append(li);
      tabs.push(button);
    });
    nav.append(list);
    if (single) {
      nav.classList.add('lang-tabs--page');
      const firstPanel = items[0].closest('.admin-panel') || items[0];
      firstPanel.parentElement.insertBefore(nav, firstPanel);
    } else {
      items[0].parentElement.insertBefore(nav, items[0]);
    }
    scope.addEventListener('input', () => codes.forEach((code, i) => { const done = mark(items, code); const li = tabs[i].closest('.lang-tabs__item'); li.classList.toggle('is-done', done); li.classList.toggle('is-missing', !done); tabs[i].querySelector('.lang-tabs__mark').textContent = done ? '✓' : '•'; }));
    bars.push({ scope, items, tabs });
  });
  if (bars.length) select(saved || labels[0].dataset.lang);
  decorateLangTabs();
}

function initConfirmations() {
  const modal = ensureConfirmDialog();
  const message = q('[data-confirm-message]', modal);
  const accept = q('[data-confirm-accept]', modal);
  let pending = null;

  const close = () => {
    modal.hidden = true;
    modal.classList.remove('is-open');
    document.body.classList.remove('has-admin-modal');
    pending = null;
  };
  qa('[data-confirm-cancel]', modal).forEach((button) => button.addEventListener('click', close));
  accept.addEventListener('click', () => {
    const target = pending;
    close();
    if (!target) return;
    if (target.tagName === 'A' && target.href) {
      window.location.assign(target.href);
      return;
    }
    const form = target.form || target.closest('form');
    if (form) {
      form.dataset.confirmedSubmit = '1';
      form.requestSubmit(target.matches('button,input[type="submit"]') ? target : undefined);
    }
  });

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;
    // A confirmation on a whole <form> guards its submit buttons only; typing in fields must not open the dialog.
    if (trigger.tagName === 'FORM' && !event.target.closest('button:not([type="button"]), input[type="submit"]')) return;
    const form = trigger.form || trigger.closest('form');
    if (form?.dataset.confirmedSubmit === '1') {
      delete form.dataset.confirmedSubmit;
      return;
    }
    event.preventDefault();
    pending = trigger;
    message.textContent = trigger.dataset.confirm || t('js_confirm_question');
    // Only a destructive action gets the red button; a harmless one (send a reminder, apply) stays primary.
    const target = `${form?.getAttribute('action') || ''} ${trigger.getAttribute('href') || ''} ${trigger.dataset.confirmTone || ''}`;
    accept.classList.toggle('is-danger', /delete|remove|disable|uninstall|restore|rollback|revoke|purge|reset|archive|ban|clear|wipe|cancel|refund|danger/i.test(target));
    accept.classList.toggle('is-primary', !accept.classList.contains('is-danger'));
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add('is-open'));
    document.body.classList.add('has-admin-modal');
    accept.focus();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
}

function initDirtyGuard() {
  qa('form[data-dirty-guard]').forEach((form) => {
    if (form.dataset.dirtyBound === '1') return;
    form.dataset.dirtyBound = '1';
    let dirty = false;
    const mark = () => { dirty = true; form.classList.add('is-dirty'); };
    form.addEventListener('input', mark, { passive: true });
    form.addEventListener('change', mark, { passive: true });
    form.addEventListener('submit', () => { dirty = false; form.classList.remove('is-dirty'); });
    window.addEventListener('beforeunload', (event) => {
      if (!dirty || form.dataset.guardOff === '1') return;
      event.preventDefault();
      event.returnValue = '';
    });
  });
}

function initImagePreviews() {
  qa('input[type="file"][data-image-upload], input[type="file"][name="product_images[]"]').forEach((input) => {
    input.addEventListener('change', () => {
      const targetSelector = input.dataset.imagePreview;
      const target = targetSelector ? q(targetSelector) : q('[data-image-preview]', input.closest('form'));
      if (!target) return;
      target.innerHTML = '';
      Array.from(input.files || []).slice(0, 12).forEach((file) => {
        if (!file.type.startsWith('image/')) return;
        const card = document.createElement('div');
        card.className = 'admin-upload-preview__item';
        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        const label = document.createElement('small');
        label.textContent = file.name;
        card.append(image, label);
        target.appendChild(card);
      });
    });
  });
}

// The photos and videos of a product are one list that is ordered by dragging (or with the arrow buttons, which also work
// on touch screens). The first photo is the main one. On a saved product every change is stored at once.
function initThumbZoom() {
  let pop = null;
  const hide = () => { pop?.remove(); pop = null; };
  document.addEventListener('mouseover', (event) => {
    const thumb = event.target instanceof Element ? event.target.closest('.admin-thumb[data-zoom]') : null;
    if (!thumb) { hide(); return; }
    if (pop && pop.dataset.src === thumb.dataset.zoom) return;
    hide();
    const rect = thumb.getBoundingClientRect();
    pop = document.createElement('div');
    pop.className = 'admin-thumb-pop';
    pop.dataset.src = thumb.dataset.zoom || '';
    const image = document.createElement('img');
    image.src = thumb.dataset.zoom || '';
    image.alt = '';
    pop.append(image);
    const top = Math.min(window.innerHeight - 250, Math.max(8, rect.top + rect.height / 2 - 120));
    const left = rect.right + 8 + 240 > window.innerWidth ? Math.max(8, rect.left - 248) : rect.right + 8;
    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
    document.body.append(pop);
  });
  document.addEventListener('scroll', hide, true);
}

/** Product code generator: asks the server for the next code by the template (the template is remembered on the server). */
function initSkuGenerator() {
  document.querySelectorAll('[data-sku-generate]').forEach((button) => {
    button.addEventListener('click', async () => {
      const field = button.closest('.admin-sku-field');
      const input = field?.querySelector('input[name="sku"]');
      const template = field?.querySelector('[data-sku-template]');
      if (!input) return;
      button.disabled = true;
      try {
        const body = new URLSearchParams({ _token: button.dataset.token || '', template: template?.value || '' });
        const response = await fetch(button.dataset.url || '', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
        const data = await response.json();
        if (data.ok && data.sku) {
          input.value = data.sku;
          if (template && data.template) template.value = data.template;
          input.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          template?.focus();
        }
      } catch { /* the field stays as it was */ } finally {
        button.disabled = false;
      }
    });
  });
}


/** Product attributes: only the ones with a value are shown; others are found by typing a name (datalist) and added. */
function initAttributePicker() {
  const search = q('[data-attr-search]');
  if (!search) return;
  const rows = qa('[data-attr-row]');
  const reveal = (row) => {
    row.hidden = false;
    const field = q('input, select', row);
    field?.focus();
  };
  search.addEventListener('change', () => {
    const needle = search.value.trim().toLowerCase();
    if (needle === '') return;
    const row = rows.find((candidate) => (candidate.dataset.attrName || '').toLowerCase() === needle);
    if (!row) return;
    reveal(row);
    search.value = '';
  });
  rows.forEach((row) => {
    q('[data-attr-remove]', row)?.addEventListener('click', () => {
      const field = q('input, select', row);
      if (field) field.value = '';
      row.hidden = true;
      row.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
}


function initQuickPrice() {
  qa('[data-quick-price]').forEach((box) => {
    const input = q('[data-quick-price-input]', box);
    if (!input) return;
    let busy = false;
    const save = async () => {
      const value = input.value.trim().replace(',', '.');
      if (busy || value === input.dataset.original) return;
      if (!/^\d{1,9}(\.\d{1,2})?$/.test(value)) { input.setCustomValidity(t('js_price_invalid')); input.reportValidity(); return; }
      input.setCustomValidity('');
      busy = true;
      box.classList.add('is-saving');
      try {
        const body = new URLSearchParams({ _token: box.dataset.token || '', price: value });
        const response = await fetch(box.dataset.url || '', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error('price');
        input.value = data.price;
        input.dataset.original = data.price;
        box.classList.add('is-saved');
        window.setTimeout(() => box.classList.remove('is-saved'), 1500);
        toast(t('js_price_saved'), 'success', 2000);
      } catch (_) {
        input.value = input.dataset.original || '';
        toast(t('js_price_failed'), 'error', 4000);
      } finally {
        busy = false;
        box.classList.remove('is-saving');
      }
    };
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); save(); input.blur(); }
      if (event.key === 'Escape') { input.value = input.dataset.original || ''; input.blur(); }
    });
    input.addEventListener('change', save);
  });
}

const SMS_GSM_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const SMS_GSM_EXTENDED = '^{}\\[~]|€\f';

// Same rule as Commerce\Modules\Notification\Domain\SmsText: GSM 7-bit is 160 / 153 per part, anything else is UCS-2 with 70 / 67.
function smsAnalyze(text) {
  let gsmUnits = 0;
  let gsm = true;
  for (const char of text) {
    if (SMS_GSM_BASIC.includes(char)) gsmUnits += 1;
    else if (SMS_GSM_EXTENDED.includes(char)) gsmUnits += 2;
    else { gsm = false; break; }
  }
  if (gsm) return { encoding: 'gsm7', units: gsmUnits, segments: gsmUnits === 0 ? 0 : (gsmUnits <= 160 ? 1 : Math.ceil(gsmUnits / 153)), single: 160, part: 153 };
  const units = text.length; // JavaScript strings are UTF-16: the length is the UCS-2 unit count
  return { encoding: 'ucs2', units, segments: units <= 70 ? 1 : Math.ceil(units / 67), single: 70, part: 67 };
}

function initSmsCounters() {
  qa('[data-sms-counter]').forEach((area) => {
    const scope = area.closest('label') || area.parentElement;
    const out = scope ? q('[data-sms-counter-out]', scope) : null;
    if (!out) return;
    const update = () => {
      const info = smsAnalyze(area.value.replace(/\r\n/g, '\n'));
      const limit = info.segments <= 1 ? info.single : info.segments * info.part;
      out.textContent = t('js_sms_counter', { n: info.units, limit, parts: info.segments, enc: t('js_sms_enc.' + info.encoding) });
      out.classList.toggle('is-long', info.segments > 1);
    };
    area.addEventListener('input', update);
    update();
  });
  qa('[data-sms-template]').forEach((select) => {
    select.addEventListener('change', () => {
      const area = q('[data-sms-counter]', select.closest('form') || document);
      if (!area || !select.value) return;
      area.value = select.value;
      area.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
}

function initPrimaryCategory() {
  qa('[data-primary-category]').forEach((box) => {
    const select = q('[data-primary-category-select]', box);
    const form = box.closest('form');
    if (!select || !form) return;
    const sync = () => {
      const picked = qa('input[name="category_ids[]"]:checked', form).map((input) => input.value);
      Array.from(select.options).forEach((option) => {
        const on = picked.includes(option.value);
        option.hidden = !on;
        option.disabled = !on;
      });
      if (!picked.includes(select.value)) select.value = picked[0] || '';
      // One category is always the main one; the choice only matters with two or more.
      box.hidden = picked.length < 2;
    };
    form.addEventListener('change', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.name === 'category_ids[]') sync();
    });
    form.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('[data-multiselect-clear]')) setTimeout(sync, 0);
    });
    sync();
  });
}

function initQuickOrder() {
  qa('[data-quick-order]').forEach((box) => {
    const input = q('[data-quick-order-input]', box);
    if (!input) return;
    let busy = false;
    const save = async () => {
      const value = input.value.trim();
      if (busy || value === input.dataset.original) return;
      if (!/^\d{1,6}$/.test(value)) { input.setCustomValidity(t('js_order_invalid')); input.reportValidity(); return; }
      input.setCustomValidity('');
      busy = true;
      try {
        const body = new URLSearchParams({ _token: box.dataset.token || '', order: value });
        const response = await fetch(box.dataset.url || '', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error('order');
        input.dataset.original = String(data.order);
        toast(t('js_order_saved'), 'success', 2000);
      } catch (_) {
        input.value = input.dataset.original || '';
        toast(t('js_order_failed'), 'error', 4000);
      } finally {
        busy = false;
      }
    };
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); save(); input.blur(); }
      if (event.key === 'Escape') { input.value = input.dataset.original || ''; input.blur(); }
    });
    input.addEventListener('change', save);
  });
}

function initQuickStatus() {
  qa('[data-quick-status]').forEach((box) => {
    const input = q('input', box);
    const badge = box.parentElement ? q('.admin-badge', box.parentElement) : null;
    if (!input) return;
    input.addEventListener('change', async () => {
      const on = box.dataset.on || 'published';
      const want = input.checked ? on : (box.dataset.off || 'draft');
      input.disabled = true;
      try {
        const body = new URLSearchParams({ _token: box.dataset.token || '', status: want });
        const response = await fetch(box.dataset.url || '', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error('status');
        if (badge) {
          const onNow = want === on;
          badge.className = 'admin-badge is-' + (onNow ? (box.dataset.onTone || 'success') : (box.dataset.offTone || 'warning'));
          badge.textContent = (onNow ? box.dataset.labelOn : box.dataset.labelOff) || (onNow ? t('js_status_published') : t('js_status_draft'));
        }
        toast(t('js_status_saved'), 'success', 2000);
      } catch (_) {
        input.checked = !input.checked;
        toast(t('js_status_failed'), 'error', 4000);
      } finally {
        input.disabled = false;
      }
    });
  });
}

function initMultiSelects() {
  const boxes = document.querySelectorAll('[data-multiselect]');
  if (boxes.length === 0) return;
  const refresh = (box) => {
    const checked = [...box.querySelectorAll('input[type="checkbox"]:checked')];
    const text = box.querySelector('[data-multiselect-text]');
    if (!text) return;
    if (checked.length === 0) text.textContent = box.dataset.multiselectAll || '';
    else if (checked.length === 1) text.textContent = checked[0].closest('label')?.textContent?.trim() || '';
    else text.textContent = (box.dataset.multiselectMany || '%count%').replace('%count%', String(checked.length));
  };
  const chips = (box) => {
    const host = box.querySelector('[data-multiselect-chips]');
    if (!host) return;
    host.textContent = '';
    box.querySelectorAll('input[type="checkbox"]:checked').forEach((input) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'admin-chip';
      chip.title = input.closest('label')?.textContent?.trim() || '';
      const label = document.createElement('span');
      label.textContent = chip.title;
      chip.append(label, lucideIconNode('x', 12));
      chip.addEventListener('click', () => { input.checked = false; input.dispatchEvent(new Event('change', { bubbles: true })); });
      host.append(chip);
    });
  };
  boxes.forEach((box) => {
    box.addEventListener('change', () => { refresh(box); chips(box); });
    chips(box);
    box.querySelector('[data-multiselect-search]')?.addEventListener('input', (event) => {
      const needle = event.target.value.trim().toLowerCase();
      box.querySelectorAll('.admin-multiselect__panel > label').forEach((label) => { label.hidden = needle !== '' && !(label.textContent || '').toLowerCase().includes(needle); });
    });
    box.querySelector('[data-multiselect-clear]')?.addEventListener('click', () => {
      box.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
      refresh(box);
      chips(box);
    });
  });
  document.addEventListener('click', (event) => {
    boxes.forEach((box) => {
      const details = box.querySelector('details');
      if (details?.open && !box.contains(event.target)) details.open = false;
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    boxes.forEach((box) => { const details = box.querySelector('details'); if (details) details.open = false; });
  });
}

function initMediaSortable() {
  qa('[data-media-sortable]').forEach((grid) => {
    let dragged = null;
    const items = () => qa(':scope > [data-media-token]', grid);
    const refresh = () => {
      let mainSeen = false;
      items().forEach((item) => {
        const input = q('input[name="media_order[]"]', item);
        if (input) input.value = item.dataset.mediaToken || '';
        const isPhoto = item.dataset.mediaKind === 'photo';
        const isMain = isPhoto && !mainSeen;
        if (isPhoto) mainSeen = true;
        item.classList.toggle('is-primary', isMain);
        const badge = q('[data-media-badge]', item);
        if (badge && isPhoto) badge.textContent = isMain ? (grid.dataset.labelMain || '') : (badge.dataset.labelOther || badge.textContent);
      });
    };
    items().forEach((item) => { const badge = q('[data-media-badge]', item); if (badge && !item.classList.contains('is-primary')) badge.dataset.labelOther = badge.textContent || ''; });
    const save = async () => {
      refresh();
      if (!grid.dataset.orderUrl) return;
      const body = new URLSearchParams();
      body.set('_media_token', grid.dataset.orderToken || '');
      items().forEach((item) => body.append('media_order[]', item.dataset.mediaToken || ''));
      try {
        const response = await fetch(grid.dataset.orderUrl, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error('order');
        toast(t('js_media_order_saved'), 'success', 2200);
      } catch (_) {
        toast(t('js_media_order_failed'), 'error', 6000);
      }
    };
    grid.addEventListener('dragstart', (event) => {
      const item = event.target instanceof Element ? event.target.closest('[data-media-token]') : null;
      if (!item || item.parentElement !== grid) return;
      dragged = item;
      item.classList.add('is-dragging');
      if (event.dataTransfer) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', item.dataset.mediaToken || ''); }
    });
    grid.addEventListener('dragover', (event) => {
      if (!dragged) return;
      event.preventDefault();
      const over = event.target instanceof Element ? event.target.closest('[data-media-token]') : null;
      if (!over || over === dragged || over.parentElement !== grid) return;
      const box = over.getBoundingClientRect();
      const after = (event.clientX - box.left) > box.width / 2 || (event.clientY - box.top) > box.height * 0.75;
      grid.insertBefore(dragged, after ? over.nextSibling : over);
    });
    grid.addEventListener('drop', (event) => { if (dragged) event.preventDefault(); });
    grid.addEventListener('dragend', () => {
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      dragged = null;
      void save();
    });
    grid.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-media-move]') : null;
      if (!button) return;
      const item = button.closest('[data-media-token]');
      if (!item) return;
      const step = Number(button.getAttribute('data-media-move')) || 0;
      if (step < 0 && item.previousElementSibling) grid.insertBefore(item, item.previousElementSibling);
      else if (step > 0 && item.nextElementSibling) grid.insertBefore(item.nextElementSibling, item);
      else return;
      button.focus();
      void save();
    });
    refresh();
  });
  // The folder new photos are uploaded to is remembered for the next product.
  qa('select[data-upload-folder]').forEach((select) => {
    select.addEventListener('change', () => {
      try { document.cookie = `mc_upload_folder=${encodeURIComponent(select.value)}; path=/admin; max-age=31536000; samesite=lax`; } catch (_) { /* the choice is simply not remembered */ }
    });
  });
}

function initSidebar() {
  const toggle = q('[data-sidebar-toggle]');
  const sidebar = q('[data-admin-sidebar]');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', () => {
    const open = sidebar.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', (event) => {
    if (window.innerWidth > 1024 || !sidebar.classList.contains('is-open')) return;
    if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
    sidebar.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
  });
}


const collator = new Intl.Collator(document.documentElement.lang || undefined, { numeric: true, sensitivity: 'base' });

function tableCellValue(cell) {
  if (!cell) return { text: '', num: NaN };
  const explicit = cell.dataset.sort;
  const raw = (explicit ?? cell.textContent ?? '').replace(/\s+/g, ' ').trim();
  const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (iso) return { text: raw, num: Date.UTC(+iso[1], +iso[2] - 1, +iso[3], +(iso[4] || 0), +(iso[5] || 0)) };
  const eu = raw.match(/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
  if (eu) return { text: raw, num: Date.UTC(+eu[3], +eu[2] - 1, +eu[1], +(eu[4] || 0), +(eu[5] || 0)) };
  const numeric = raw.replace(/\s/g, '').replace(/,(?=\d{1,2}$)/, '.').replace(/[^0-9.-]/g, '');
  const looksNumeric = /^-?\d+(\.\d+)?$/.test(numeric) && /^[^\p{L}]*\d[^\p{L}]*[A-Z₴€$%]{0,4}$/u.test(raw.replace(/\s/g, ''));
  return { text: raw, num: looksNumeric ? Number(numeric) : NaN };
}

function enhanceTable(table) {
  const body = table.tBodies[0];
  const headRow = table.tHead?.rows[0];
  if (!body || !headRow || table.closest('td, [data-no-table-tools]') || table.hasAttribute('data-no-table-tools')) return;
  const rows = Array.from(body.rows);
  const span = (row) => Array.from(row.cells).reduce((n, c) => n + (c.colSpan || 1), 0);
  if (rows.some((row) => span(row) !== span(headRow) && row.querySelector('td[colspan]'))) return;
  if (rows.length < 2) return;
  const wrap = table.closest('.admin-table-wrap') || table.parentElement;
  const serverPagers = qa('.admin-pagination:not(.admin-table-pager)');
  const tables = qa('table.admin-table');
  const serverPaged = wrap.hasAttribute('data-server-paged') || serverPagers.some((pager) => {
    if (!(table.compareDocumentPosition(pager) & Node.DOCUMENT_POSITION_FOLLOWING)) return false;
    return !tables.some((other) => other !== table && (table.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING) && (other.compareDocumentPosition(pager) & Node.DOCUMENT_POSITION_FOLLOWING));
  });
  const state = { col: -1, dir: 1, page: 1, size: Number(table.dataset.pageSize || 25) };
  const original = rows.slice();
  const headers = Array.from(headRow.cells);

  const sortable = (th, index) => !th.matches('[data-no-sort], .is-actions, .is-select') && th.textContent.trim() !== '' && !th.querySelector('input[type=checkbox]') && rows.some((row) => tableCellValue(row.cells[index]).text !== '');
  const link = headers.map((th) => th.querySelector('a[href*="sort="]'));
  const params = new URLSearchParams(location.search);
  headers.forEach((th) => {
    const key = th.dataset.sortKey;
    if (!key) return;
    th.classList.add('is-sortable');
    th.tabIndex = 0;
    th.title = t('js_table_sort');
    th.setAttribute('aria-sort', params.get('sort') === key ? (params.get('dir') === 'asc' ? 'ascending' : 'descending') : 'none');
    const go = () => {
      const next = new URLSearchParams(location.search);
      next.set('sort', key);
      next.set('dir', params.get('sort') === key && params.get('dir') !== 'asc' ? 'asc' : (params.get('sort') === key ? 'desc' : 'asc'));
      location.assign(`${location.pathname}?${next.toString()}`);
    };
    th.addEventListener('click', (event) => { if (!event.target.closest('a, button, input, select, label')) go(); });
    th.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); go(); } });
  });
  headers.forEach((th, index) => {
    if (th.dataset.sortKey || link[index] || !sortable(th, index)) return;
    th.classList.add('is-sortable');
    th.tabIndex = 0;
    th.setAttribute('role', 'columnheader');
    th.setAttribute('aria-sort', 'none');
    th.title = serverPaged ? t('js_table_sort_page') : t('js_table_sort');
    const go = () => {
      state.dir = state.col === index ? -state.dir : 1;
      state.col = index;
      state.page = 1;
      headers.forEach((h, i) => h.setAttribute('aria-sort', i === index ? (state.dir === 1 ? 'ascending' : 'descending') : (h.classList.contains('is-sortable') ? 'none' : h.getAttribute('aria-sort') || 'none')));
      render();
    };
    th.addEventListener('click', (event) => { if (!event.target.closest('a, button, input, select, label')) go(); });
    th.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); go(); } });
  });

  let query = '';
  let searchBox = null;
  if (!serverPaged && rows.length > 10 && !table.hasAttribute('data-no-search') && !document.querySelector('main form[method="get"] input[name="q"]')) {
    searchBox = document.createElement('input');
    searchBox.type = 'search';
    searchBox.className = 'admin-table-search';
    searchBox.placeholder = t('js_table_search');
    searchBox.setAttribute('aria-label', t('js_table_search'));
    wrap.before(searchBox);
    searchBox.addEventListener('input', () => { query = searchBox.value.trim().toLowerCase(); state.page = 1; render(); });
  }
  const haystack = new WeakMap(original.map((row) => [row, row.textContent.replace(/\s+/g, ' ').toLowerCase()]));

  let pager = null;
  const pageable = !serverPaged && !table.hasAttribute('data-no-paging');
  if (pageable && rows.length > 10) {
    pager = document.createElement('div');
    pager.className = 'admin-pagination admin-table-pager';
    wrap.after(pager);
  }

  function ordered() {
    const source = query ? original.filter((row) => haystack.get(row).includes(query)) : original;
    if (state.col < 0) return source.slice();
    const keyed = source.map((row, i) => ({ row, i, v: tableCellValue(row.cells[state.col]) }));
    keyed.sort((a, b) => {
      const an = Number.isNaN(a.v.num), bn = Number.isNaN(b.v.num);
      let r;
      if (!an && !bn) r = a.v.num - b.v.num;
      else if (a.v.text === '' || b.v.text === '') r = (a.v.text === '') - (b.v.text === '') || 0;
      else r = collator.compare(a.v.text, b.v.text);
      return r * state.dir || a.i - b.i;
    });
    return keyed.map((k) => k.row);
  }

  function pageButton(label, page, extra = {}) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'admin-button is-sm' + (extra.active ? ' is-primary' : '');
    b.textContent = label;
    if (extra.title) { b.title = extra.title; b.setAttribute('aria-label', extra.title); }
    if (extra.disabled) b.disabled = true;
    if (extra.active) b.setAttribute('aria-current', 'page');
    b.addEventListener('click', () => { state.page = page; render(); wrap.scrollIntoView({ block: 'nearest' }); });
    return b;
  }

  function render() {
    const list = ordered();
    const total = list.length;
    const size = pager ? state.size : total;
    const pages = Math.max(1, Math.ceil(total / size));
    state.page = Math.min(state.page, pages);
    const from = (state.page - 1) * size;
    const frag = document.createDocumentFragment();
    list.forEach((row, i) => { row.hidden = i < from || i >= from + size; frag.appendChild(row); });
    original.forEach((row) => { if (!list.includes(row)) { row.hidden = true; frag.appendChild(row); } });
    body.appendChild(frag);
    if (!pager) return;
    pager.replaceChildren();
    const info = document.createElement('span');
    info.textContent = t('js_table_range', { from: total ? from + 1 : 0, to: Math.min(total, from + size), total });
    const nav = document.createElement('nav');
    nav.setAttribute('aria-label', t('js_table_pages'));
    nav.append(pageButton('«', 1, { disabled: state.page === 1, title: t('js_table_first') }), pageButton('‹', state.page - 1, { disabled: state.page === 1, title: t('js_table_prev') }));
    const window_ = new Set([1, pages, state.page - 1, state.page, state.page + 1]);
    let last = 0;
    Array.from(window_).filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b).forEach((n) => {
      if (n - last > 1) { const gap = document.createElement('span'); gap.textContent = '…'; nav.appendChild(gap); }
      nav.appendChild(pageButton(String(n), n, { active: n === state.page }));
      last = n;
    });
    nav.append(pageButton('›', state.page + 1, { disabled: state.page === pages, title: t('js_table_next') }), pageButton('»', pages, { disabled: state.page === pages, title: t('js_table_last') }));
    const sizeLabel = document.createElement('label');
    sizeLabel.className = 'admin-table-pager__size';
    const select = document.createElement('select');
    [10, 25, 50, 100].forEach((n) => { const o = document.createElement('option'); o.value = String(n); o.textContent = String(n); o.selected = n === state.size; select.appendChild(o); });
    select.addEventListener('change', () => { state.size = Number(select.value); state.page = 1; render(); });
    sizeLabel.append(t('js_table_per_page') + ' ', select);
    pager.append(info, nav, sizeLabel);
  }
  render();
}

function initDataTables() {
  qa('table.admin-table').forEach(enhanceTable);
}

function initNavAccordion() {
  const nav = q('.admin-nav');
  if (!nav) return;
  const children = Array.from(nav.children);
  const groups = [];
  let current = null;
  children.forEach((el) => {
    if (el.classList.contains('admin-nav__label')) {
      current = { label: el, items: [] };
      groups.push(current);
    } else if (current) current.items.push(el);
  });
  if (!groups.length) return;
  const stored = (() => { try { return localStorage.getItem('mc_admin_nav_group'); } catch { return null; } })();
  const activeGroup = groups.find((g) => g.items.some((el) => el.classList?.contains('is-active')));
  const open = (group, save) => {
    groups.forEach((g) => {
      const on = g === group;
      g.label.setAttribute('aria-expanded', String(on));
      g.label.classList.toggle('is-open', on);
      g.items.forEach((el) => { el.hidden = !on; });
    });
    if (save && group) { try { localStorage.setItem('mc_admin_nav_group', group.label.dataset.group); } catch { /* per-page only */ } }
  };
  groups.forEach((g, i) => {
    const text = g.label.textContent.trim();
    g.label.dataset.group = String(i);
    g.label.setAttribute('role', 'button');
    g.label.tabIndex = 0;
    g.label.replaceChildren(document.createTextNode(text), lucideIconNode('chevron-down', 14));
    const toggle = () => open(g.label.classList.contains('is-open') ? null : g, true);
    g.label.addEventListener('click', toggle);
    g.label.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
  });
  open(activeGroup || groups[Number(stored)] || groups[0], false);
  nav.classList.add('is-accordion');
  keepNavScroll(nav);
}

/* The sidebar keeps its scroll position across page loads (sessionStorage, restored before paint by an inline script and
   again here after the accordion settled); the active item is scrolled into view only when it is actually out of sight. */
function keepNavScroll(nav) {
  const key = 'mc_admin_nav_scroll';
  const read = () => { try { const v = sessionStorage.getItem(key); return v === null ? null : Number.parseInt(v, 10) || 0; } catch { return null; } };
  const write = () => { try { sessionStorage.setItem(key, String(Math.round(nav.scrollTop))); } catch { /* storage is optional */ } };
  const saved = read();
  if (saved !== null) nav.scrollTop = saved;
  const active = q('.admin-nav__link.is-active', nav);
  if (active && !active.hidden) {
    const box = nav.getBoundingClientRect();
    const item = active.getBoundingClientRect();
    if (item.top < box.top) nav.scrollTop += item.top - box.top - 8;
    else if (item.bottom > box.bottom) nav.scrollTop += item.bottom - box.bottom + 8;
  }
  write();
  let timer = 0;
  nav.addEventListener('scroll', () => { window.clearTimeout(timer); timer = window.setTimeout(write, 80); }, { passive: true });
  nav.addEventListener('click', write, true);
  window.addEventListener('pagehide', write);
}

/* Media settings: touching an advanced field switches the preset to "custom" so the edit is not silently ignored. */
function initMediaPresetForm() {
  qa('[data-media-preset-form]').forEach((form) => {
    const custom = q('input[name="preset"][value="custom"]', form);
    const advanced = q('[data-media-advanced]', form);
    if (!custom || !advanced) return;
    advanced.addEventListener('input', () => { custom.checked = true; });
    advanced.addEventListener('change', () => { custom.checked = true; });
    form.addEventListener('change', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.name === 'preset' && event.target.value === 'custom') advanced.open = true;
    });
  });
}

/* Help tips: click/tap toggles the popover (hover and keyboard focus are handled in CSS); Escape and outside clicks close it. */
function initHelpTips() {
  const close = (except) => qa('[data-admin-tip].is-open').forEach((tip) => {
    if (tip === except) return;
    tip.classList.remove('is-open');
    q('.admin-tip__button', tip)?.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.admin-tip__button') : null;
    if (!button) { close(null); return; }
    event.preventDefault();
    const tip = button.closest('[data-admin-tip]');
    const open = !tip.classList.contains('is-open');
    close(tip);
    tip.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(null); });
}

function initColorFields() {
  qa('[data-color-field]').forEach((field) => {
    const preset = q('[data-color-preset]', field);
    const box = q('[data-color-custom]', field);
    const picker = q('[data-color-picker]', field);
    const hex = q('[data-color-hex]', field);
    if (!preset || !box || !picker || !hex) return;
    const valid = (v) => /^#[0-9a-fA-F]{6}$/.test(v);
    const sync = () => { box.hidden = preset.value !== 'custom'; hex.required = preset.value === 'custom'; };
    preset.addEventListener('change', sync);
    picker.addEventListener('input', () => { hex.value = picker.value; hex.setCustomValidity(''); });
    hex.addEventListener('input', () => {
      let v = hex.value.trim();
      if (v && !v.startsWith('#')) v = `#${v}`;
      hex.value = v;
      const ok = valid(v);
      hex.setCustomValidity(ok || v === '' ? '' : t('js_color_invalid'));
      if (ok) picker.value = v.toLowerCase();
    });
    sync();
  });
}

/** Badge colour: preset swatches plus a free HEX colour; a free colour switches the tone to "custom". */
function initToneFields() {
  qa('[data-tone-field]').forEach((field) => {
    const value = q('[data-tone-value]', field);
    const picker = q('[data-tone-picker]', field);
    const hex = q('[data-tone-hex]', field);
    const swatches = qa('[data-tone-preset]', field);
    if (!value || !picker || !hex) return;
    const valid = (v) => /^#[0-9a-fA-F]{6}$/.test(v);
    const toHex = (css) => { const m = css.match(/\d+/g); return m && m.length >= 3 ? '#' + m.slice(0, 3).map((n) => Number(n).toString(16).padStart(2, '0')).join('') : ''; };
    const mark = (active) => swatches.forEach((s) => { const on = s === active; s.classList.toggle('is-active', on); s.setAttribute('aria-pressed', String(on)); });
    swatches.forEach((swatch) => swatch.addEventListener('click', () => {
      value.value = swatch.dataset.tonePreset || 'primary';
      mark(swatch);
      const shown = toHex(window.getComputedStyle(swatch).backgroundColor);
      if (shown) { picker.value = shown; }
      hex.value = '';
      hex.setCustomValidity('');
    }));
    const custom = (v) => { value.value = 'custom'; mark(null); hex.value = v; picker.value = v; hex.setCustomValidity(''); };
    picker.addEventListener('input', () => custom(picker.value.toLowerCase()));
    hex.addEventListener('input', () => {
      let v = hex.value.trim();
      if (v && !v.startsWith('#')) v = `#${v}`;
      hex.value = v;
      if (valid(v)) { value.value = 'custom'; mark(null); picker.value = v.toLowerCase(); hex.setCustomValidity(''); }
      else hex.setCustomValidity(v === '' ? '' : t('js_color_invalid'));
    });
    if (value.value === 'custom') mark(null);

    // Text colour: its own picker and HEX box (empty = chosen automatically by contrast), plus a live preview.
    const fgPicker = q('[data-tone-fg-picker]', field);
    const fgHex = q('[data-tone-fg-hex]', field);
    const preview = q('[data-tone-preview]', field);
    if (!fgPicker || !fgHex) return;
    const lum = (hexColor) => {
      const c = [1, 3, 5].map((i) => parseInt(hexColor.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
      return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    const auto = (bg) => ((1.05 / (lum(bg) + 0.05)) > ((lum(bg) + 0.05) / 0.05) ? '#ffffff' : '#111111');
    const currentBg = () => {
      if (value.value === 'custom') return valid(hex.value) ? hex.value.toLowerCase() : picker.value;
      const active = swatches.find((sw) => sw.classList.contains('is-active'));
      return (active && toHex(window.getComputedStyle(active).backgroundColor)) || picker.value;
    };
    const refresh = () => {
      const bg = currentBg();
      const fg = valid(fgHex.value) ? fgHex.value.toLowerCase() : auto(bg);
      if (!valid(fgHex.value)) fgPicker.value = fg;
      if (preview) { preview.style.background = bg; preview.style.color = fg; }
    };
    fgPicker.addEventListener('input', () => { fgHex.value = fgPicker.value.toLowerCase(); fgHex.setCustomValidity(''); if (value.value !== 'custom') custom(currentBg()); refresh(); });
    fgHex.addEventListener('input', () => {
      let v = fgHex.value.trim();
      if (v && !v.startsWith('#')) v = `#${v}`;
      fgHex.value = v;
      if (valid(v)) { fgHex.setCustomValidity(''); if (value.value !== 'custom') custom(currentBg()); } else { fgHex.setCustomValidity(v === '' ? '' : t('js_color_invalid')); }
      refresh();
    });
    [picker, hex].forEach((el) => el.addEventListener('input', refresh));
    swatches.forEach((sw) => sw.addEventListener('click', () => window.setTimeout(refresh, 0)));
    refresh();
  });
}

/** Every plain colour input gets a HEX text box next to it (type or paste #ffffff). */
function initColorHexInputs() {
  qa('input[type="color"]:not([data-color-picker])').forEach((picker) => {
    if (picker.dataset.hexReady || picker.closest('.admin-option__row')) return;
    picker.dataset.hexReady = '1';
    const hex = document.createElement('input');
    hex.type = 'text';
    hex.className = 'admin-color-hex';
    hex.value = picker.value;
    hex.maxLength = 7;
    hex.spellcheck = false;
    hex.autocomplete = 'off';
    hex.placeholder = '#ffffff';
    hex.setAttribute('aria-label', t('js_color_hex'));
    hex.disabled = picker.disabled;
    picker.after(hex);
    picker.addEventListener('input', () => { hex.value = picker.value; hex.setCustomValidity(''); });
    hex.addEventListener('input', () => {
      let v = hex.value.trim();
      if (v && !v.startsWith('#')) v = `#${v}`;
      hex.value = v;
      if (/^#[0-9a-fA-F]{6}$/.test(v)) {
        hex.setCustomValidity('');
        picker.value = v.toLowerCase();
        picker.dispatchEvent(new Event('input', { bubbles: true }));
        picker.dispatchEvent(new Event('change', { bubbles: true }));
      } else hex.setCustomValidity(v === '' ? '' : t('js_color_invalid'));
    });
  });
}

/**
 * A page with four or more panels in a row becomes tabs (no long ribbons): the panels are grouped into one tab set and the
 * tab names come from the panel headings. Pages that already use data-tabs, or carry data-no-auto-tabs, are left alone.
 */
function initAutoTabs() {
  const main = q('main.admin-content');
  if (!main || main.hasAttribute('data-no-auto-tabs') || main.querySelector('[data-tabs]')) return;
  const panels = Array.from(main.children).filter((el) => el.matches('section.admin-panel') && q('h2', el) && !el.hasAttribute('data-tab'));
  if (panels.length < 4) return;
  const wrap = document.createElement('div');
  wrap.dataset.tabs = 'auto';
  wrap.dataset.tabsFlex = '';
  panels[0].before(wrap);
  panels.forEach((panel, i) => { panel.dataset.tab = `p${i + 1}`; wrap.appendChild(panel); });
}

/**
 * A click on a table row opens the record: the row's own open/edit link (marked data-row-open, or the first icon link of the
 * actions cell, or a title link). Clicks on controls, links, selected text and tables marked data-no-row-click are left alone.
 */
function initRowLinks() {
  const skip = 'a, button, input, select, textarea, label, summary, details, [data-quick-status], [data-quick-order], [data-quick-price], [contenteditable]';
  const unsafe = /delete|remove|export|download|label|pdf|logout|\.csv/i;
  const target = (row) => {
    const candidates = [
      ...row.querySelectorAll('[data-row-open]'),
      ...row.querySelectorAll('td.is-actions a.admin-icon-button[href], .admin-row-actions a[href]'),
      ...row.querySelectorAll('td a[href^="/admin"]'),
    ];
    return candidates.find((a) => a instanceof window.HTMLAnchorElement && a.getAttribute('href')?.startsWith('/admin') && !a.target && !a.hasAttribute('download') && !a.classList.contains('is-danger') && !unsafe.test(a.getAttribute('href') || '')) || null;
  };
  const rowOf = (el) => (el instanceof Element ? el.closest('table.admin-table tbody tr') : null);
  document.addEventListener('mouseover', (event) => {
    const row = rowOf(event.target);
    if (!row || row.dataset.rowLink || row.closest('[data-no-row-click]')) return;
    row.dataset.rowLink = target(row) ? '1' : '0';
  });
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    const row = rowOf(event.target);
    if (!row || row.closest('[data-no-row-click]') || event.target.closest(skip)) return;
    if (window.getSelection()?.toString()) return;
    const link = target(row);
    if (link) window.location.assign(link.href);
  });
}

function initTabs() {
  qa('[data-tabs]').forEach((root) => {
    const panels = Array.from(root.children).filter((el) => el.matches('[data-tab]'));
    if (panels.length < 2) return;
    const key = `mc_tab_${location.pathname}_${root.dataset.tabs}`;
    const list = document.createElement('div');
    list.className = 'admin-tabs';
    list.setAttribute('role', 'tablist');
    const buttons = panels.map((panel) => {
      const id = panel.dataset.tab;
      const heading = q('h2, h3', panel);
      const countEl = q('.admin-panel__header > span', panel);
      const count = panel.dataset.tabCount ?? (countEl && /^\d+$/.test(countEl.textContent.trim()) ? countEl.textContent.trim() : '');
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'admin-tab';
      b.setAttribute('role', 'tab');
      b.dataset.tabTarget = id;
      if (panel.dataset.tabIcon) b.appendChild(lucideIconNode(panel.dataset.tabIcon, 16));
      const label = document.createElement('span');
      label.textContent = panel.dataset.tabLabel || heading?.textContent.trim() || id;
      b.appendChild(label);
      if (count !== '') { const c = document.createElement('em'); c.textContent = count; b.appendChild(c); }
      panel.setAttribute('role', 'tabpanel');
      return b;
    });
    list.append(...buttons);
    // Long pages offer "all sections": every panel stays open one under another (remembered on this device).
    const flex = root.hasAttribute('data-tabs-flex');
    const readAll = () => { try { return localStorage.getItem('mc_admin_tabs') === 'all'; } catch { return false; } };
    let showAll = flex && readAll();
    let allButton = null;
    if (flex) {
      allButton = document.createElement('button');
      allButton.type = 'button';
      allButton.className = 'admin-tab admin-tab--all';
      allButton.title = t('js_tabs_all');
      allButton.setAttribute('aria-pressed', String(showAll));
      allButton.appendChild(lucideIconNode('layout-grid', 16));
      const allLabel = document.createElement('span');
      allLabel.textContent = t('js_tabs_all');
      allButton.appendChild(allLabel);
      list.appendChild(allButton);
    }
    panels[0].before(list);
    const activate = (id, save = true) => {
      const target = panels.find((p) => p.dataset.tab === id) || panels[0];
      panels.forEach((p) => { p.hidden = !showAll && p !== target; });
      buttons.forEach((b) => { const on = b.dataset.tabTarget === target.dataset.tab; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; });
      if (save) { try { sessionStorage.setItem(key, target.dataset.tab); } catch { /* per-page only */ } }
    };
    allButton?.addEventListener('click', () => {
      showAll = !showAll;
      try { localStorage.setItem('mc_admin_tabs', showAll ? 'all' : 'tabs'); } catch { /* per-session only */ }
      allButton.setAttribute('aria-pressed', String(showAll));
      activate(buttons.find((b) => b.classList.contains('is-active'))?.dataset.tabTarget || panels[0].dataset.tab, false);
    });
    buttons.forEach((b, i) => {
      b.addEventListener('click', () => { activate(b.dataset.tabTarget); history.replaceState(null, '', `#${b.dataset.tabTarget}`); });
      b.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        const n = buttons[(i + (e.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
        n.focus();
        n.click();
      });
    });
    let initial = location.hash.slice(1);
    // A link to an element inside a hidden panel (#language-packs) opens the tab that holds it.
    if (initial && !panels.some((p) => p.dataset.tab === initial)) {
      let anchored = null;
      try { anchored = document.getElementById(decodeURIComponent(initial)); } catch { anchored = null; }
      const host = anchored ? panels.find((p) => p === anchored || p.contains(anchored)) : null;
      if (host) initial = host.dataset.tab;
    }
    if (!panels.some((p) => p.dataset.tab === initial)) { try { initial = sessionStorage.getItem(key) || ''; } catch { initial = ''; } }
    activate(initial, false);
  });
}

function initProductTabs() {
  const form = q('#product-form[data-product-tabs]');
  if (!form) return;
  let labels = {};
  try { labels = JSON.parse(form.dataset.productTabs || '{}'); } catch { labels = {}; }
  const panels = qa('[data-product-tab]');
  const order = Object.keys(labels).filter((id) => panels.some((p) => p.dataset.productTab === id));
  if (order.length < 2) return;
  const list = document.createElement('div');
  list.className = 'admin-tabs';
  list.setAttribute('role', 'tablist');
  const key = `mc_product_tab_${location.pathname}`;
  const buttons = order.map((id) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'admin-tab';
    b.setAttribute('role', 'tab');
    b.dataset.tabTarget = id;
    const label = document.createElement('span');
    label.textContent = labels[id];
    b.appendChild(label);
    return b;
  });
  list.append(...buttons);
  form.before(list);
  const activate = (id, save = true) => {
    const target = order.includes(id) ? id : order[0];
    panels.forEach((p) => { p.hidden = p.dataset.productTab !== target; });
    buttons.forEach((b) => { const on = b.dataset.tabTarget === target; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; });
    if (save) { try { sessionStorage.setItem(key, target); } catch { /* per-page only */ } }
  };
  buttons.forEach((b, i) => {
    b.addEventListener('click', () => activate(b.dataset.tabTarget));
    b.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      e.preventDefault();
      const n = buttons[(i + (e.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
      n.focus();
      n.click();
    });
  });
  // A required field in a hidden tab cannot be focused by the browser: show its tab so the message is visible.
  form.addEventListener('invalid', (event) => {
    const panel = event.target instanceof Element ? event.target.closest('[data-product-tab]') : null;
    if (panel && panel.hidden) activate(panel.dataset.productTab, false);
  }, true);
  // A link to a section (#variants, #digital-files...) opens the tab it lives in.
  const fromHash = () => {
    const el = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    const panel = el?.closest('[data-product-tab]');
    return panel ? panel.dataset.productTab : '';
  };
  let initial = fromHash();
  if (!initial) { try { initial = sessionStorage.getItem(key) || ''; } catch { initial = ''; } }
  activate(initial, false);
  window.addEventListener('hashchange', () => { const id = fromHash(); if (id) activate(id); });
}

function initTemplateEditors() {
  qa('[data-notification-template]').forEach((card) => {
    const form = q('[data-tpl-form]', card);
    if (!form) return;
    const frame = q('[data-tpl-preview]', card);
    const source = q('[data-tpl-html]', card);
    const subject = q('input[name="subject"]', form);
    const body = q('textarea[name="body"]', form);
    qa('[data-insert-placeholder]', form).forEach((button) => button.addEventListener('click', () => {
      const text = button.dataset.insertPlaceholder || '';
      const target = document.activeElement === subject ? subject : body;
      const start = target.selectionStart ?? target.value.length;
      const end = target.selectionEnd ?? start;
      target.setRangeText(text, start, end, 'end');
      target.focus();
      target.dispatchEvent(new Event('input', { bubbles: true }));
    }));
    let last = '';
    const render = async () => {
      const data = new FormData(form);
      const key = `${data.get('subject')}\u0000${data.get('body')}`;
      if (key === last) return;
      last = key;
      try {
        const response = await fetch(form.dataset.previewUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const json = await response.json();
        if (!json.ok) throw new Error('preview');
        if (frame) frame.srcdoc = json.html;
        if (source) {
          source.textContent = json.html;
          import('../admin/features/html-code-editor.ts').then(({ highlightHtml }) => highlightHtml?.(source, json.html)).catch(() => {});
        }
      } catch {
        last = '';
        if (frame) frame.srcdoc = `<p style="font:14px sans-serif;padding:16px">${t('js_preview_failed')}</p>`;
      }
    };
    qa('[data-tab-target="preview"], [data-tab-target="html"]', card).forEach((b) => b.addEventListener('click', render));
  });
}

function initSidebarCollapse() {
  const button = q('[data-sidebar-collapse]');
  if (!button) return;
  const root = document.documentElement;
  const sync = () => button.setAttribute('aria-pressed', String(root.dataset.sidebar === 'collapsed'));
  sync();
  button.addEventListener('click', () => {
    const collapsed = root.dataset.sidebar !== 'collapsed';
    if (collapsed) root.dataset.sidebar = 'collapsed';
    else delete root.dataset.sidebar;
    try {
      localStorage.setItem('mc_admin_sidebar', collapsed ? 'collapsed' : 'expanded');
    } catch {
      /* storage unavailable: state lasts for this page only */
    }
    sync();
  });
}

// Quick buttons: the page list is the sidebar itself, so it always matches what this administrator may open.
function initQuickLinkForm() {
  const form = q('[data-quicklink-form]');
  const select = form ? q('[data-quicklink-pages]', form) : null;
  const label = form ? q('[data-quicklink-label]', form) : null;
  if (!form || !select || !label) return;
  const seen = new Set();
  qa('[data-command-source] a').forEach((link) => {
    const url = new URL(link.href, window.location.origin);
    const href = url.pathname + url.search;
    const text = link.textContent.trim();
    if (!text || !href.startsWith('/admin') || seen.has(href)) return;
    seen.add(href);
    const option = document.createElement('option');
    option.value = href;
    option.textContent = text;
    select.appendChild(option);
  });
  const sync = () => { label.value = select.selectedOptions[0] ? select.selectedOptions[0].textContent : ''; };
  select.addEventListener('change', sync);
  sync();
}

// SEO fields: a compact counter with the recommended length, a correct example and the {variables} that are filled in on the page.
function initSeoFields() {
  const kindOf = (field) => {
    const name = field.getAttribute('name') || '';
    if (/(^|\[|_)(meta_title|seo_title)(\]|$)/.test(name) || /^tpl\[[a-z]+\]\[[^\]]+\]\[title\]$/.test(name)) return 'title';
    if (/(^|\[|_)(meta_description|seo_description)(\]|$)/.test(name) || /^tpl\[[a-z]+\]\[[^\]]+\]\[description\]$/.test(name)) return 'description';
    return '';
  };
  const range = { title: [30, 60], description: [70, 160] };
  qa('input[name], textarea[name]').forEach((field) => {
    if (field.dataset.seoReady || !(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return;
    const kind = kindOf(field);
    if (!kind) return;
    field.dataset.seoReady = '1';
    const [min, max] = range[kind];
    const box = document.createElement('div');
    box.className = 'seo-hint';
    const count = document.createElement('span');
    count.className = 'seo-hint__count';
    const advice = document.createElement('span');
    advice.className = 'seo-hint__advice';
    advice.textContent = `${t(`admin.seo.${kind}_advice`, { min, max })} ${t(`admin.seo.${kind}_example`)}`;
    box.append(count, advice);
    const form = field.closest('form');
    const tpl = /^tpl\[([a-z]+)\]/.exec(field.getAttribute('name') || '');
    const product = tpl ? tpl[1] === 'product' : Boolean(form && form.querySelector('[name="sku"], [name="brand_id"]'));
    const names = product ? ['name', 'store', 'price', 'brand', 'category', 'sku'] : ['name', 'store'];
    const chips = document.createElement('div');
    chips.className = 'seo-chips';
    const label = document.createElement('span');
    label.textContent = t('admin.seo.variables');
    chips.append(label);
    names.forEach((name) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'seo-chip';
      chip.textContent = `{${name}}`;
      chip.title = t(`admin.seo.var_${name}`);
      chip.addEventListener('click', () => {
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        field.value = `${field.value.slice(0, start)}{${name}}${field.value.slice(end)}`;
        field.focus();
        field.setSelectionRange(start + name.length + 2, start + name.length + 2);
        field.dispatchEvent(new Event('input', { bubbles: true }));
      });
      chips.append(chip);
    });
    const anchor = field.closest('label') ?? field;
    anchor.insertAdjacentElement('afterend', box);
    box.insertAdjacentElement('afterend', chips);
    const update = () => {
      const length = Array.from(field.value).length;
      count.textContent = t('admin.seo.chars', { count: length, max: max });
      count.classList.toggle('is-ok', length >= min && length <= max);
      count.classList.toggle('is-warn', length > 0 && (length < min || length > max));
    };
    field.addEventListener('input', update);
    update();
  });
}

// Builder: the real preview frame takes the width of the chosen device.
function initRealPreview() {
  const box = q('[data-real-preview]');
  const frame = box ? q('iframe', box) : null;
  if (!box || !frame) return;
  qa('[data-real-width]').forEach((button) => button.addEventListener('click', () => {
    qa('[data-real-width]').forEach((other) => other.classList.toggle('is-active', other === button));
    frame.style.width = button.dataset.realWidth || '100%';
  }));
  frame.addEventListener('load', () => {
    try {
      const style = frame.contentDocument.createElement('style');
      style.textContent = '.mc-consent,.consent-fab,.cw,[data-support-chat],.support-chat{display:none!important}';
      frame.contentDocument.head.appendChild(style);
    } catch (_error) {
      // A frame we cannot reach keeps the plain page.
    }
  });
}

function initCommandPalette() {
  const palette = q('[data-command-palette]');
  const input = q('[data-command-input]', palette || document);
  const results = q('[data-command-results]', palette || document);
  if (!palette || !input || !results) return;
  const links = qa('[data-command-source] a').map((link) => {
    const path = new URL(link.href, window.location.origin).pathname.replace(/[/_-]+/g, ' ');
    return { label: link.textContent.trim(), href: link.href, hay: `${link.textContent.trim()} ${path}`.toLocaleLowerCase('uk-UA').replace(/\u0451/g, '\u0435') };
  }).filter((item) => item.label);
  // Typed in the other alphabet or on the wrong keyboard layout, or with one typo: the section is still found.
  const cyr = '\u0439\u0446\u0443\u043a\u0435\u043d\u0433\u0448\u0449\u0437\u0445\u044a\u0444\u044b\u0432\u0430\u043f\u0440\u043e\u043b\u0434\u0436\u044d\u044f\u0447\u0441\u043c\u0438\u0442\u044c\u0431\u044e\u0456\u0457\u0454';
  const lat = "qwertyuiop[]asdfghjkl;'zxcvbnm,.sie";
  const swapLayout = (text) => Array.from(text).map((ch) => { const a = cyr.indexOf(ch); if (a >= 0) return lat[a]; const b = lat.indexOf(ch); return b >= 0 ? cyr[b] : ch; }).join('');
  const sound = { '\u0430': 'a', '\u0431': 'b', '\u0432': 'v', '\u0433': 'h', '\u0434': 'd', '\u0435': 'e', '\u0454': 'ye', '\u0436': 'zh', '\u0437': 'z', '\u0438': 'y', '\u0456': 'i', '\u0457': 'yi', '\u0439': 'y', '\u043a': 'k', '\u043b': 'l', '\u043c': 'm', '\u043d': 'n', '\u043e': 'o', '\u043f': 'p', '\u0440': 'r', '\u0441': 's', '\u0442': 't', '\u0443': 'u', '\u0444': 'f', '\u0445': 'kh', '\u0446': 'ts', '\u0447': 'ch', '\u0448': 'sh', '\u0449': 'shch', '\u044c': '', '\u044e': 'yu', '\u044f': 'ya', '\u044b': 'y', '\u044d': 'e' };
  const toLatin = (text) => Array.from(text).map((ch) => (ch in sound ? sound[ch] : ch)).join('');
  const near = (word, hay) => {
    if (word.length < 4) return false;
    return hay.split(/\s+/).some((candidate) => {
      if (Math.abs(candidate.length - word.length) > 1) return false;
      let diff = 0; let i = 0; let j = 0;
      while (i < word.length && j < candidate.length && diff < 2) {
        if (word[i] === candidate[j]) { i += 1; j += 1; continue; }
        diff += 1;
        if (word.length > candidate.length) i += 1; else if (word.length < candidate.length) j += 1; else { i += 1; j += 1; }
      }
      return diff + (word.length - i) + (candidate.length - j) <= 1;
    });
  };
  const isMatch = (item, needle) => needle.split(/\s+/).every((token) => [token, swapLayout(token), toLatin(token)].some((variant) => item.hay.includes(variant) || near(variant, item.hay)));
  let remote = [];
  let timer = 0;
  let sequence = 0;
  const row = (item, index) => {
    const link = document.createElement('a');
    link.href = item.href;
    const label = document.createElement('span');
    label.textContent = item.detail ? `${item.label} · ${item.detail}` : item.label;
    const key = document.createElement('kbd');
    key.textContent = item.type ? t(`js_qs_${item.type}`) : String(index + 1);
    link.append(label, key);
    return link;
  };
  const render = (query = '') => {
    const needle = query.trim().toLocaleLowerCase('uk-UA');
    const matches = links.filter((item) => !needle || isMatch(item, needle)).slice(0, 12);
    results.innerHTML = '';
    matches.forEach((item, index) => results.appendChild(row(item, index)));
    remote.forEach((item, index) => results.appendChild(row(item, index)));
    if (!matches.length && !remote.length) { const empty = document.createElement('p'); empty.textContent = t('js_nothing_found'); results.replaceChildren(empty); }
  };
  // Data search (products, orders, customers): asked after a short pause, stale answers are dropped.
  const searchData = (query) => {
    window.clearTimeout(timer);
    const value = query.trim();
    if (value.length < 2) { if (remote.length) { remote = []; render(query); } return; }
    timer = window.setTimeout(async () => {
      const mine = ++sequence;
      try {
        const response = await fetch(`/admin/api/quick-search?q=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok || mine !== sequence) return;
        remote = (await response.json()).results ?? [];
        render(input.value);
      } catch (_) { /* the section list keeps working without the data search */ }
    }, 220);
  };
  const open = () => {
    remote = [];
    render('');
    palette.hidden = false;
    palette.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-admin-modal');
    requestAnimationFrame(() => palette.classList.add('is-open'));
    window.setTimeout(() => input.focus(), 30);
  };
  const close = () => {
    palette.classList.remove('is-open');
    palette.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-admin-modal');
    window.setTimeout(() => { palette.hidden = true; input.value = ''; }, 180);
  };
  input.addEventListener('input', () => { render(input.value); searchData(input.value); });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { const first = q('a', results); if (first) { event.preventDefault(); window.location.assign(first.href); } }
  });
  qa('[data-command-close]', palette).forEach((node) => node.addEventListener('click', close));
  qa('[data-command-open]').forEach((node) => node.addEventListener('click', open));
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (palette.hidden) open(); else close();
    } else if (event.key === 'Escape' && !palette.hidden) close();
  });
}

// Keyboard shortcuts: "/" opens the search, "g" then a letter jumps to a section (g o = orders, g p = products, g c = customers, g d = dashboard).
function initHotkeys() {
  const routes = { d: '/admin', o: '/admin/orders', p: '/admin/catalog/products', c: '/admin/commerce/customers', q: '/admin/system/quality' };
  let waiting = 0;
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
    if (!typing && (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'z') {
      const undo = q('[data-undo-form]');
      if (undo) { event.preventDefault(); undo.requestSubmit(); }
      return;
    }
    if (typing || event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key === '/') {
      const opener = q('[data-command-open]');
      if (opener) { event.preventDefault(); opener.click(); }
      return;
    }
    if (waiting && routes[event.key]) { event.preventDefault(); waiting = 0; window.location.assign(routes[event.key]); return; }
    waiting = event.key === 'g' ? Date.now() : 0;
    if (waiting) window.setTimeout(() => { waiting = 0; }, 1200);
  });
}

function initDefaultSubmit() {
  // Enter inside a text field must save the form, not run the first secondary formaction button.
  qa('form[data-default-submit]').forEach((form) => {
    form.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.defaultPrevented) return;
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || target.form !== form || !['text', 'number', 'email', 'url', 'search', 'tel', 'password', 'date'].includes(target.type)) return;
      const main = q(form.dataset.defaultSubmit || '', form);
      if (!main) return;
      event.preventDefault();
      form.requestSubmit(main);
    });
  });
}

function initCustomSelects() {
  qa('select[data-custom-select]').forEach((select) => {
    const target = q(select.dataset.customSelect);
    if (!target) return;
    const sync = () => { target.hidden = select.value !== '__custom'; };
    select.addEventListener('change', sync);
    sync();
  });
}

function initMediaPickers() {
  qa('[data-media-pick]').forEach((button) => {
    const box = q(button.dataset.mediaTarget || '');
    if (!box) return;
    const inputName = box.dataset.inputName || 'existing_media[]';
    const single = button.dataset.mediaPick !== 'multiple';
    const addFigure = (item) => {
      if (box.querySelector(`input[value="${CSS.escape(String(item.id))}"]`)) return;
      const figure = document.createElement('figure');
      const img = document.createElement('img');
      img.src = item.url;
      img.alt = item.alt || '';
      img.loading = 'lazy';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = inputName;
      input.value = String(item.id);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.setAttribute('aria-label', t('js_media_pick_remove'));
      remove.title = t('js_media_pick_remove');
      remove.append(lucideIconNode('x', 12));
      remove.addEventListener('click', () => { figure.remove(); box.closest('form')?.dispatchEvent(new Event('change', { bubbles: true })); });
      figure.append(img, input, remove);
      box.append(figure);
    };
    button.addEventListener('click', async () => {
      try {
        const { pickMedia } = await import('../admin/features/media-picker.ts');
        const picked = await pickMedia({ multiple: !single });
        if (picked.length === 0) return;
        if (single) box.replaceChildren();
        picked.forEach(addFigure);
        box.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (error) {
        console.error('Media picker failed', error);
        toast(t('js_media_unavailable'), 'error', 5000);
      }
    });
    qa('[data-media-picked-remove]', box).forEach((remove) => remove.addEventListener('click', () => { remove.closest('figure')?.remove(); box.closest('form')?.dispatchEvent(new Event('change', { bubbles: true })); }));
  });
}

function initCategoryTree() {
  const table = q('[data-category-tree]');
  if (!table) return;
  const rows = () => qa('[data-cat-row]', table);
  const STORE = 'mc.catalog.collapsed';
  let collapsed = new Set();
  // First visit: every branch is folded. After that the open and folded branches are remembered in this browser.
  let remembered;
  try { remembered = localStorage.getItem(STORE); } catch (_error) { remembered = null; }
  if (remembered === null) {
    collapsed = new Set(qa('[data-cat-row]', table).filter((row) => q('[data-tree-toggle]', row)).map((row) => row.dataset.catNode));
  } else {
    try { collapsed = new Set(JSON.parse(remembered || '[]')); } catch (_error) { collapsed = new Set(); }
  }
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify([...collapsed])); } catch (_error) { /* storage can be blocked */ } };
  const paint = () => {
    const hidden = new Set();
    rows().forEach((row) => {
      const parent = row.dataset.catParent;
      const isHidden = parent !== '' && (collapsed.has(parent) || hidden.has(parent));
      if (isHidden) hidden.add(row.dataset.catNode);
      row.hidden = isHidden;
      const toggle = q('[data-tree-toggle]', row);
      if (toggle) {
        const open = !collapsed.has(row.dataset.catNode);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('is-collapsed', !open);
      }
    });
  };
  table.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-tree-toggle]');
    if (!toggle) return;
    event.stopPropagation();
    const id = toggle.closest('[data-cat-row]').dataset.catNode;
    if (collapsed.has(id)) collapsed.delete(id); else collapsed.add(id);
    save();
    paint();
  });
  const withChildren = () => rows().filter((row) => q('[data-tree-toggle]', row)).map((row) => row.dataset.catNode);
  q('[data-tree-expand]')?.addEventListener('click', () => { collapsed.clear(); save(); paint(); });
  q('[data-tree-collapse]')?.addEventListener('click', () => { collapsed = new Set(withChildren()); save(); paint(); });
  if (remembered === null) save();
  paint();

  const moveUrl = table.dataset.moveUrl;
  if (!moveUrl) return;
  let dragged = null;
  const clear = () => {
    qa('.is-drop-before, .is-drop-after, .is-drop-into', table).forEach((row) => row.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-into'));
    q('[data-tree-root-drop]')?.classList.remove('is-over');
  };
  const isDescendant = (row, ancestorNode) => {
    let parent = row.dataset.catParent;
    while (parent) {
      if (parent === ancestorNode) return true;
      parent = rows().find((r) => r.dataset.catNode === parent)?.dataset.catParent || '';
    }
    return false;
  };
  const zone = (event, row) => {
    const box = row.getBoundingClientRect();
    const ratio = (event.clientY - box.top) / Math.max(1, box.height);
    return ratio < 0.25 ? 'before' : ratio > 0.75 ? 'after' : 'into';
  };
  const send = async (parent, before) => {
    const body = new FormData();
    body.append('_token', table.dataset.moveToken || '');
    body.append('parent', parent);
    body.append('before', before);
    try {
      const response = await fetch(moveUrl.replace('__ID__', dragged.dataset.catId), { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await response.json().catch(() => ({ ok: false }));
      if (!response.ok || !data.ok) throw new Error(data.error || 'move');
      window.location.reload();
    } catch (_error) {
      toast(table.dataset.moveFailed || 'Error', 'error', 5000);
    }
  };
  table.addEventListener('dragstart', (event) => {
    const row = event.target.closest('[data-cat-row]');
    if (!row) return;
    dragged = row;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', row.dataset.catId);
    row.classList.add('is-dragging');
  });
  table.addEventListener('dragend', () => { dragged?.classList.remove('is-dragging'); dragged = null; clear(); });
  table.addEventListener('dragover', (event) => {
    const row = event.target.closest('[data-cat-row]');
    if (!dragged || !row || row === dragged || isDescendant(row, dragged.dataset.catNode)) return;
    event.preventDefault();
    clear();
    row.classList.add('is-drop-' + zone(event, row));
  });
  table.addEventListener('drop', (event) => {
    const row = event.target.closest('[data-cat-row]');
    if (!dragged || !row || row === dragged || isDescendant(row, dragged.dataset.catNode)) return;
    event.preventDefault();
    const where = zone(event, row);
    clear();
    const idOf = (node) => rows().find((r) => r.dataset.catNode === node)?.dataset.catId || '';
    if (where === 'into') return void send(row.dataset.catId, '');
    const parentNode = row.dataset.catParent;
    if (where === 'before') return void send(idOf(parentNode), row.dataset.catId);
    const siblings = rows().filter((r) => r.dataset.catParent === parentNode && r !== dragged);
    const next = siblings[siblings.indexOf(row) + 1];
    send(idOf(parentNode), next ? next.dataset.catId : '');
  });
  const root = q('[data-tree-root-drop]');
  if (root) {
    root.addEventListener('dragover', (event) => { if (!dragged) return; event.preventDefault(); root.classList.add('is-over'); });
    root.addEventListener('dragleave', () => root.classList.remove('is-over'));
    root.addEventListener('drop', (event) => { if (!dragged) return; event.preventDefault(); root.classList.remove('is-over'); send('', ''); });
  }
}

function initSlugGenerators() {
  const sources = ['name', 'title', 'label', 'h1'];
  qa('input[name="slug"], input[name$="[slug]"]').forEach((input) => {
    if (input.closest('.admin-slug-field') || input.type === 'hidden') return;
    const scope = input.closest('form') || document;
    const source = () => sources.map((name) => scope.querySelector(`[name="${name}"], [name$="[${name}]"]`)).find((node) => node && node.value.trim() !== '');
    const wrap = document.createElement('span');
    wrap.className = 'admin-slug-field';
    input.replaceWith(wrap);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'admin-slug-generate';
    button.title = t('js_slug_generate');
    button.setAttribute('aria-label', t('js_slug_generate'));
    button.append(lucideIconNode('wand-sparkles', 16));
    wrap.append(input, button);
    button.addEventListener('click', async () => {
      const from = source();
      if (!from) { toast(t('js_slug_need_name'), 'warning', 3500); return; }
      const locale = q('.admin-context select[name="locale"]')?.value || 'uk-UA';
      try {
        const response = await fetch(`/admin/api/slug?text=${encodeURIComponent(from.value)}&locale=${encodeURIComponent(locale)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || !data.slug) throw new Error('slug');
        input.value = data.slug;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (_) { toast(t('js_slug_failed'), 'error', 4000); }
    });
  });
}

function initAutoSubmit() {
  qa('[data-autosubmit]').forEach((control) => {
    control.addEventListener('change', () => {
      const form = control.closest('form');
      if (!form) return;
      // Switching store/market/content language reloads the page: warn instead of silently dropping edits.
      if (control.closest('.admin-context') && document.querySelector('form[data-dirty-guard].is-dirty')) {
        if (!window.confirm(t('js_unsaved_switch'))) {
          control.value = Array.from(control.options).find((option) => option.defaultSelected)?.value ?? control.value;
          return;
        }
        document.querySelectorAll('form[data-dirty-guard]').forEach((f) => { f.classList.remove('is-dirty'); f.dataset.guardOff = '1'; });
      }
      form.requestSubmit();
    });
  });
}

/**
 * Long forms keep their Save button at the bottom; the same button is mirrored into the page header so nobody has to scroll for it.
 * Pages that already carry a primary submit in the header (the product form) are left alone.
 */
function initHeaderSaveButton() {
  const actions = q('.admin-page-header .admin-page-actions');
  if (!actions || q('button[type="submit"].is-primary', actions)) return;
  const forms = qa('main form.admin-form, form.admin-form').filter((form) => q('.admin-form-actions button[type="submit"]', form));
  if (forms.length !== 1) return;
  const form = forms[0];
  const source = q('.admin-form-actions button[type="submit"]:not([formaction])', form);
  if (!source) return;
  if (!form.id) form.id = 'admin-main-form';
  const label = (source.textContent || '').trim();
  if (!label) return;
  const button = document.createElement('button');
  button.type = 'submit';
  button.className = 'admin-button is-primary';
  button.setAttribute('form', form.id);
  button.textContent = label;
  actions.prepend(button);
}


/**
 * "key=value, key=value" text fields (quantity price breaks, prices per customer group) become rows: a number or a
 * group from a list, a price, a remove button. The original input stays in the form and always holds the text.
 */
function initPairRepeaters() {
  qa('input[data-pair-repeater]').forEach((source) => {
    if (source.dataset.pairReady) return;
    source.dataset.pairReady = '1';
    let options = [];
    try { options = JSON.parse(source.dataset.pairOptions || '[]'); } catch (_error) { options = []; }
    const isGroup = options.length > 0 || source.dataset.pairRepeater === 'group';
    source.hidden = true;
    const box = document.createElement('div');
    box.className = 'admin-pair-rows';
    source.insertAdjacentElement('afterend', box);
    const sync = () => {
      const parts = [];
      qa('.admin-pair-row', box).forEach((row) => {
        const key = q('[data-pair-key]', row).value.trim();
        const value = q('[data-pair-value]', row).value.trim().replace(',', '.');
        if (key !== '' && value !== '') parts.push(`${key}=${value}`);
      });
      source.value = parts.join(', ');
      source.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const addRow = (key = '', value = '') => {
      const row = document.createElement('div');
      row.className = 'admin-pair-row';
      let keyField;
      if (isGroup) {
        keyField = document.createElement('select');
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = source.dataset.pairKeyLabel || '';
        keyField.append(empty);
        options.forEach((option) => {
          const item = document.createElement('option');
          item.value = option.code;
          item.textContent = option.name;
          keyField.append(item);
        });
        if (key && !options.some((option) => option.code === key)) {
          const unknown = document.createElement('option');
          unknown.value = key;
          unknown.textContent = key;
          keyField.append(unknown);
        }
        keyField.value = key;
      } else {
        keyField = document.createElement('input');
        keyField.type = 'number';
        keyField.min = '2';
        keyField.step = '1';
        keyField.inputMode = 'numeric';
        keyField.placeholder = source.dataset.pairKeyLabel || '';
        keyField.value = key;
      }
      keyField.setAttribute('data-pair-key', '');
      keyField.setAttribute('aria-label', source.dataset.pairKeyLabel || '');
      const valueField = document.createElement('input');
      valueField.type = 'text';
      valueField.inputMode = 'decimal';
      valueField.placeholder = source.dataset.pairValueLabel || '';
      valueField.setAttribute('aria-label', source.dataset.pairValueLabel || '');
      valueField.setAttribute('data-pair-value', '');
      valueField.value = value;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'admin-icon-button is-sm is-danger';
      remove.textContent = '×';
      remove.setAttribute('aria-label', source.dataset.pairRemove || 'Remove');
      remove.addEventListener('click', () => { row.remove(); sync(); });
      [keyField, valueField].forEach((field) => field.addEventListener('input', sync));
      keyField.addEventListener('change', sync);
      row.append(keyField, valueField, remove);
      box.append(row);
    };
    source.value.split(',').map((part) => part.trim()).filter(Boolean).forEach((part) => {
      const [key, value] = part.split('=');
      if (key !== undefined && value !== undefined) addRow(key.trim(), value.trim());
    });
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'admin-button';
    add.textContent = source.dataset.pairAdd || '+';
    add.addEventListener('click', () => { addRow(); q('.admin-pair-row:last-child [data-pair-key]', box)?.focus(); });
    box.insertAdjacentElement('afterend', add);
  });
}

/**
 * Lists of product articles ("Related", "Bought together") with a search box: type a name or an article, pick from the
 * results, or take suggestions from the product's category. The original input keeps the comma-separated articles.
 */
function initSkuPickers() {
  qa('input[data-sku-picker]').forEach((source) => {
    if (source.dataset.pickerReady) return;
    source.dataset.pickerReady = '1';
    const url = source.dataset.lookupUrl || '';
    const productId = source.dataset.productId || '';
    source.hidden = true;
    const wrap = document.createElement('div');
    wrap.className = 'admin-sku-picker';
    const chips = document.createElement('div');
    chips.className = 'admin-chip-list';
    const search = document.createElement('input');
    search.type = 'search';
    search.autocomplete = 'off';
    search.placeholder = source.dataset.searchPlaceholder || '';
    search.setAttribute('aria-label', source.dataset.searchPlaceholder || '');
    const results = document.createElement('ul');
    results.className = 'admin-sku-picker__results';
    results.hidden = true;
    const suggest = document.createElement('button');
    suggest.type = 'button';
    suggest.className = 'admin-button';
    suggest.textContent = source.dataset.suggestLabel || '';
    suggest.hidden = productId === '';
    const bar = document.createElement('div');
    bar.className = 'admin-sku-picker__bar';
    bar.append(search, suggest);
    wrap.append(chips, bar, results);
    source.insertAdjacentElement('afterend', wrap);

    let skus = source.value.split(',').map((item) => item.trim()).filter(Boolean);
    const names = new Map();
    const sync = () => { source.value = skus.join(', '); source.dispatchEvent(new Event('input', { bubbles: true })); };
    const paintChips = () => {
      chips.textContent = '';
      skus.forEach((sku) => {
        const chip = document.createElement('span');
        chip.className = 'admin-chip';
        const label = document.createElement('span');
        label.textContent = names.has(sku) ? `${names.get(sku)} · ${sku}` : sku;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', source.dataset.pairRemove || 'Remove');
        remove.addEventListener('click', () => { skus = skus.filter((item) => item !== sku); sync(); paintChips(); });
        chip.append(label, remove);
        chips.append(chip);
      });
    };
    const fetchItems = async (params) => {
      try {
        const response = await fetch(`${url}?${new URLSearchParams(params)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        return (await response.json()).items || [];
      } catch (_error) {
        return [];
      }
    };
    const showResults = (items) => {
      results.textContent = '';
      items.filter((item) => !skus.includes(item.sku)).forEach((item) => {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = `${item.name} · ${item.sku}`;
        button.addEventListener('click', () => {
          skus.push(item.sku);
          names.set(item.sku, item.name);
          sync();
          paintChips();
          li.remove();
          if (!results.children.length) results.hidden = true;
        });
        li.append(button);
        results.append(li);
      });
      results.hidden = results.children.length === 0;
    };
    let timer = 0;
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      const term = search.value.trim();
      if (term.length < 2) { results.hidden = true; return; }
      timer = window.setTimeout(async () => showResults(await fetchItems({ q: term })), 250);
    });
    search.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
    suggest.addEventListener('click', async () => showResults(await fetchItems({ for: productId })));
    paintChips();
    skus.slice(0, 20).forEach(async (sku) => {
      const found = (await fetchItems({ q: sku })).find((item) => item.sku === sku);
      if (found) { names.set(sku, found.name); paintChips(); }
    });
  });
}


/**
 * File libraries (product documents, digital downloads): a browser with folders, search, "new folder" and upload from
 * the computer. In the product form it opens in a window and hands the chosen file back; on the Files page it is
 * shown in place, for keeping the libraries in order.
 */
function mountFileLibrary(root, { base, token, strings, onPick }) {
  let folder = 0;
  let query = '';
  root.classList.add('admin-filelib');
  root.textContent = '';
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const toolbar = el('div', 'admin-filelib__bar');
  const trail = el('nav', 'admin-filelib__trail');
  trail.setAttribute('aria-label', strings.root);
  const search = el('input');
  search.type = 'search';
  search.placeholder = strings.search;
  search.setAttribute('aria-label', strings.search);
  const newFolder = el('button', 'admin-button', strings.newFolder);
  newFolder.type = 'button';
  const upload = el('label', 'admin-button is-primary', strings.upload);
  const input = el('input');
  input.type = 'file';
  input.multiple = true;
  input.hidden = true;
  upload.append(input);
  toolbar.append(search, newFolder, upload);
  const status = el('p', 'admin-filelib__status');
  status.setAttribute('role', 'status');
  const list = el('ul', 'admin-filelib__list');
  root.append(toolbar, trail, status, list);

  const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
  const load = async () => {
    status.textContent = '';
    try {
      const params = new URLSearchParams({ folder: String(folder), q: query });
      const response = await fetch(`${base}.json?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error('load');
      paint(await response.json());
    } catch (_error) {
      status.textContent = strings.error;
    }
  };
  const paint = (data) => {
    trail.textContent = '';
    const crumb = (label, id) => {
      const button = el('button', 'admin-filelib__crumb', label);
      button.type = 'button';
      button.addEventListener('click', () => { folder = id; query = ''; search.value = ''; load(); });
      trail.append(button);
    };
    crumb(strings.root, 0);
    data.trail.forEach((step) => { trail.append(el('span', 'admin-filelib__sep', '/')); crumb(step.name, step.id); });
    list.textContent = '';
    data.folders.forEach((item) => {
      const li = el('li', 'admin-filelib__row is-folder');
      const open = el('button', 'admin-filelib__name', `📁 ${item.name}`);
      open.type = 'button';
      open.addEventListener('click', () => { folder = item.id; query = ''; search.value = ''; load(); });
      li.append(open, el('small', '', String(item.items)));
      list.append(li);
    });
    data.items.forEach((item) => {
      const li = el('li', 'admin-filelib__row');
      const name = el(onPick ? 'button' : 'span', 'admin-filelib__name', `📄 ${item.title}`);
      if (onPick) {
        name.type = 'button';
        name.addEventListener('click', () => onPick(item));
      }
      li.append(name, el('small', '', `${item.filename} · ${size(item.bytes)}`));
      list.append(li);
    });
    if (!data.folders.length && !data.items.length) list.append(el('li', 'admin-filelib__empty', strings.empty));
  };

  let timer = 0;
  search.addEventListener('input', () => { window.clearTimeout(timer); timer = window.setTimeout(() => { query = search.value.trim(); load(); }, 250); });
  newFolder.addEventListener('click', async () => {
    const name = window.prompt(strings.folderPrompt, '');
    if (!name || !name.trim()) return;
    const body = new FormData();
    body.append('_token', token);
    body.append('name', name.trim());
    body.append('parent', String(folder));
    const response = await fetch(`${base}/folder.json`, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) status.textContent = result.message || strings.error;
    else load();
  });
  input.addEventListener('change', async () => {
    if (!input.files || !input.files.length) return;
    const body = new FormData();
    body.append('_token', token);
    body.append('folder', String(folder));
    Array.from(input.files).forEach((file) => body.append('files[]', file));
    status.textContent = strings.uploading;
    try {
      const response = await fetch(`${base}/upload.json`, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json().catch(() => ({}));
      input.value = '';
      if (!response.ok || (result.errors && result.errors.length)) {
        status.textContent = (result.errors || [result.message || strings.error]).join(' ');
        load();
        return;
      }
      if (onPick && result.stored && result.stored.length === 1) { onPick(result.stored[0]); return; }
      status.textContent = '';
      load();
    } catch (_error) {
      status.textContent = strings.error;
    }
  });
  load();
}

function initFileLibraries() {
  qa('[data-file-library-page]').forEach((root) => {
    let strings;
    try { strings = JSON.parse(root.dataset.strings || '{}'); } catch (_error) { strings = {}; }
    mountFileLibrary(root, { library: root.dataset.fileLibraryPage, base: root.dataset.base, token: root.dataset.token, strings });
  });
  qa('[data-file-library]').forEach((button) => {
    button.addEventListener('click', () => {
      let strings;
      try { strings = JSON.parse(button.dataset.strings || '{}'); } catch (_error) { strings = {}; }
      const dialog = document.createElement('dialog');
      dialog.className = 'admin-modal admin-filelib-modal';
      const head = document.createElement('header');
      const title = document.createElement('h2');
      title.textContent = strings.title || '';
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'admin-icon-button';
      close.textContent = '×';
      close.setAttribute('aria-label', strings.close || 'Close');
      close.addEventListener('click', () => dialog.close());
      head.append(title, close);
      const body = document.createElement('div');
      dialog.append(head, body);
      document.body.append(dialog);
      dialog.addEventListener('close', () => dialog.remove());
      mountFileLibrary(body, {
        library: button.dataset.fileLibrary,
        base: button.dataset.base,
        token: button.dataset.token,
        strings,
        onPick: (item) => {
          const target = q(button.dataset.target || '');
          if (target) target.value = item.id;
          const label = q(button.dataset.label || '');
          if (label) label.textContent = item.title;
          const titleInput = q(button.dataset.titleInput || '');
          if (titleInput && !titleInput.value) titleInput.value = item.title;
          button.dispatchEvent(new CustomEvent('file-library:picked', { bubbles: true, detail: item }));
          dialog.close();
        },
      });
      dialog.showModal();
    });
  });
}

/**
 * Spell checking for the places where people write real text (letters, descriptions, notes): the browser's own checker is
 * switched on and told which language the content is in. It marks mistakes while typing and works offline.
 */
function initSpellcheck() {
  const lang = document.documentElement.lang || 'uk-UA';
  qa('textarea').forEach((area) => {
    if (area.hasAttribute('spellcheck') || area.matches('[data-no-spellcheck], [data-code-editor], [name*="css"], [name*="html"], [name*="json"], [name*="script"]')) return;
    area.setAttribute('spellcheck', 'true');
    if (!area.hasAttribute('lang')) {
      const panel = area.closest('[data-locale]');
      area.setAttribute('lang', (panel && panel.getAttribute('data-locale')) || lang);
    }
  });
}

/**
 * Import with a progress bar: when "apply" is chosen the file is processed in steps of 100 rows; the bar shows how many rows are
 * done, how many were created or updated and how many failed. "Preview" stays a normal submit.
 */
function initImportSteps() {
  const form = q('form[data-import-steps]');
  if (!form) return;
  form.addEventListener('submit', async (event) => {
    const mode = form.elements.namedItem('mode');
    if (!(mode instanceof HTMLSelectElement) || mode.value !== 'apply') return;
    event.preventDefault();
    const box = q('[data-import-progress]', form);
    const bar = q('progress', box);
    const text = q('[data-import-progress-text]', box);
    const submit = q('button[type="submit"]', form);
    box.hidden = false;
    submit.disabled = true;
    const fill = (template, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`%${key}%`, String(value)), template);
    const totals = { created: 0, updated: 0, failed: 0 };
    const errors = [];
    let offset = 0;
    try {
      for (;;) {
        const body = new FormData(form);
        body.set('chunk', '100');
        body.set('offset', String(offset));
        const response = await fetch(window.location.href, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const part = await response.json().catch(() => ({}));
        if (!response.ok || !part.ok) throw new Error(part.message || form.dataset.textError);
        totals.created += Number(part.created || 0);
        totals.updated += Number(part.updated || 0);
        totals.failed += Number(part.failed || 0);
        (part.errors || []).forEach((message) => { if (errors.length < 50) errors.push(message); });
        offset += Number(part.step || 0);
        const total = Math.max(1, Number(part.total || 1));
        bar.value = Math.min(100, Math.round((offset / total) * 100));
        text.textContent = fill(form.dataset.textWorking || '', { done: Math.min(offset, total), total, ...totals });
        if (part.done || Number(part.step || 0) === 0) break;
      }
      text.textContent = fill(form.dataset.textDone || '', totals);
      if (errors.length) {
        const list = document.createElement('ul');
        errors.forEach((message) => { const li = document.createElement('li'); li.textContent = message; list.append(li); });
        box.append(list);
      }
    } catch (error) {
      text.textContent = error instanceof Error ? error.message : (form.dataset.textError || '');
      submit.disabled = false;
    }
  });
}

function initCopyControls() {
  qa('[data-copy-value], [data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const targetSelector = button.dataset.copyTarget;
      const target = targetSelector ? q(targetSelector) : null;
      const value = button.dataset.copyValue ?? target?.textContent?.trim() ?? '';
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value);
        toast(t('js_copied'), 'success', 1800);
      } catch (_) {
        const fallback = document.createElement('textarea');
        fallback.value = value;
        fallback.setAttribute('readonly', '');
        fallback.style.position = 'fixed';
        fallback.style.opacity = '0';
        document.body.appendChild(fallback);
        fallback.select();
        const copied = document.execCommand('copy');
        fallback.remove();
        if (copied) toast(t('js_copied'), 'success', 1800);
        else toast(t('js_copy_failed'), 'error', 4000);
      }
    });
  });
}

function initHealthCheck() {
  qa('[data-health-check]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.dataset.loading === '1') return;
      const original = button.textContent;
      button.dataset.loading = '1';
      button.disabled = true;
      button.textContent = t('js_checking');
      try {
        const response = await fetch('/admin/api/system/health', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (data.healthy) toast(t('js_health_ok'), 'success');
        else toast(t('js_health_issues', { count: data.required_failed || 0 }), 'warning', 7000);
      } catch (_) {
        toast(t('js_health_failed'), 'error', 7000);
      } finally {
        button.disabled = false;
        button.dataset.loading = '0';
        button.textContent = original;
      }
    });
  });
}

function initSiteProfilePreset() {
  const form = q('[data-site-mode-profiles]');
  if (!form) return;
  let profiles = {};
  try {
    profiles = JSON.parse(form.dataset.siteModeProfiles || '{}');
  } catch (_) {
    return;
  }
  qa('input[name="mode"]', form).forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      const features = profiles?.[radio.value]?.features || {};
      Object.entries(features).forEach(([feature, enabled]) => {
        const box = q(`input[name="feature_${feature}"]`, form);
        if (box) box.checked = Boolean(enabled);
      });
    });
  });
}

function initSecretToggles() {
  qa('[data-secret-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const selector = button.dataset.secretToggle;
      const input = selector ? q(selector) : q('input[type="password"], input[data-secret-input]', button.closest('[data-secret-field]'));
      if (!input) return;
      const visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      button.setAttribute('aria-pressed', String(!visible));
      button.setAttribute('title', visible ? t('js_show_value') : t('js_hide_value'));
    });
  });
}


function initQuickPreview() {
  const dialog = q('[data-quick-preview-dialog]');
  const body = q('[data-quick-preview-body]', dialog || document);
  if (!dialog || !body) return;
  q('[data-quick-preview-close]', dialog)?.addEventListener('click', () => dialog.close());
  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-quick-preview]');
    if (!trigger) return;
    event.preventDefault();
    body.replaceChildren(Object.assign(document.createElement('p'), { textContent: t('js_loading') }));
    if (!dialog.open) dialog.showModal();
    try {
      const response = await fetch(trigger.dataset.quickPreview, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const nodes = Array.from(parsed.body.childNodes).map((node) => document.importNode(node, true));
      body.replaceChildren(...nodes);
    } catch (_) {
      body.replaceChildren(Object.assign(document.createElement('p'), { textContent: t('js_preview_failed') }));
    }
  });
}

function initFileLabels() {
  qa('.admin-dropzone input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
      const label = input.closest('.admin-dropzone');
      const strong = q('strong', label);
      if (strong && input.files?.[0]) strong.textContent = input.files[0].name;
    });
  });
}

function initSeoAuthoring() {
  qa('[data-char-counter]').forEach((counter) => {
    const input = document.getElementById(counter.dataset.charCounter || '');
    if (!input) return;
    const min = Number(counter.dataset.min || 0);
    const max = Number(counter.dataset.max || 0);
    const update = () => {
      const length = input.value.trim().length;
      counter.textContent = `${length} / ${max}`;
      counter.classList.toggle('is-good', length >= min && length <= max);
      counter.classList.toggle('is-warn', length > 0 && (length < min || length > max));
    };
    input.addEventListener('input', update);
    update();
  });
  const preview = q('[data-serp-preview]');
  if (!preview) return;
  const form = preview.closest('form');
  const field = (name) => (form ? form.querySelector(`[data-serp-source="${name}"]`) : null);
  const value = (name) => (field(name)?.value || '').trim();
  const clip = (text, max) => (text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text);
  const render = () => {
    const title = value('meta_title') || value('title');
    const description = value('meta_description') || value('excerpt');
    const slug = value('slug');
    q('[data-serp-title]', preview).textContent = clip(title, 60);
    q('[data-serp-desc]', preview).textContent = clip(description, 160);
    q('[data-serp-url]', preview).textContent = `${location.host} › blog${slug ? ` › ${slug}` : ''}`;
  };
  qa('[data-serp-source]', form || document).forEach((input) => input.addEventListener('input', render));
  render();
}

/** Enables Web Push on this device for the admin audience; the browser shows its own permission prompt. */
function initAdminPush() {
  const box = q('[data-push-enable]');
  if (!box) return;
  const button = q('button', box);
  const status = q('[data-push-status]', box);
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { button.disabled = true; status.textContent = t('js_push_unsupported'); return; }
  const key = (b64) => { const pad = '='.repeat((4 - (b64.length % 4)) % 4); const raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, (c) => c.charCodeAt(0)); };
  button.addEventListener('click', async () => {
    try {
      if ((await window.Notification.requestPermission()) !== 'granted') { status.textContent = t('js_push_denied'); return; }
      const registration = await navigator.serviceWorker.register('/nexora-push-sw.js');
      await navigator.serviceWorker.ready;
      const subscription = (await registration.pushManager.getSubscription()) || (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(box.dataset.pushKey) }));
      const json = subscription.toJSON();
      const response = await fetch(box.dataset.pushUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': box.dataset.pushToken }, body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }) });
      status.textContent = response.ok ? t('js_push_enabled') : t('js_push_failed');
    } catch (_error) {
      status.textContent = t('js_push_failed');
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initFlashToasts();
  initAdminAjaxForms();
  tidyTimestamps();
  initFieldLangTabs();
  decorateLangTabs();
  initConfirmations();
  initIconPickers();
  initDirtyGuard();
  initImagePreviews();
  initSidebar();
  initSidebarCollapse();
  initNavAccordion();
  initHotkeys();
  initCommandPalette();
  initQuickLinkForm();
  initSeoFields();
  initRealPreview();
  initCategoryTree();
  initAutoSubmit();
  initRowLinks();
  initAutoTabs();
  initTabs();
  initTemplateEditors();
  initDataTables();
  initMediaPresetForm();
  initHelpTips();
  initColorFields();
  initColorHexInputs();
  initToneFields();
  initCustomSelects();
  initDefaultSubmit();
  initMediaPickers();
  initSlugGenerators();
  initMediaSortable();
  initMultiSelects();
  initSkuGenerator();
  initAttributePicker();
  initQuickPrice();
  initQuickStatus();
  initQuickOrder();
  initPrimaryCategory();
  initSmsCounters();
  initThumbZoom();
  initProductTabs();
  initCopyControls();
  initImportSteps();
  initSpellcheck();
  initFileLibraries();
  initPairRepeaters();
  initSkuPickers();
  initHeaderSaveButton();
  initHealthCheck();
  initSiteProfilePreset();
  initSecretToggles();
  initFileLabels();
  initQuickPreview();
  initSeoAuthoring();
  initAdminPush();
});

async function initPageFeatures() {
  const feature = document.querySelector('[data-builder]') ? 'builder' : document.querySelector('[data-appearance-media]') ? 'appearance-media' : document.querySelector('[data-media-drop]') ? 'media-library' : null;
  if (!feature) return;
  const loaders = {
    builder: () => import('../admin/features/builder.js'),
    'appearance-media': () => import('../admin/features/appearance-media.js'),
    'media-library': () => import('../admin/features/media-library.js'),
  };
  try {
    await loaders[feature]?.();
  } catch (error) {
    console.error(`Admin feature ${feature} failed to load`, error);
    toast(t('js_extra_ui_failed'), 'error', 7000);
  }
}

document.addEventListener('DOMContentLoaded', initPageFeatures, { once: true });

function initUserMenu() {
  const menu = document.querySelector('[data-user-menu]');
  if (!menu) return;
  document.addEventListener('click', (event) => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary')?.focus(); } });
}

initThemeToggle('mc_admin_theme');
initUserMenu();
initDismissibleNotices();
