import '../admin/admin-runtime.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { lucideIconNode } from '../shared/lucide-icons.js';

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

function initFlashToasts() {
  qa('[data-toast-source], .admin-notice').forEach((source) => {
    const message = source.textContent.trim();
    if (!message) return;
    const type = source.classList.contains('is-error') ? 'error' : source.classList.contains('is-warning') ? 'warning' : source.classList.contains('is-success') ? 'success' : 'info';
    toast(message, type);
    source.classList.add('is-toast-mirrored');
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
  q('button[data-confirm-cancel]', modal).textContent = t('js_cancel');
  q('[data-confirm-accept]', modal).textContent = t('js_continue');
  document.body.appendChild(modal);
  return modal;
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
  boxes.forEach((box) => {
    box.addEventListener('change', () => refresh(box));
    box.querySelector('[data-multiselect-clear]')?.addEventListener('click', () => {
      box.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
      refresh(box);
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
      next.delete('page');
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
  if (!serverPaged && rows.length > 10 && !table.hasAttribute('data-no-search')) {
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

/** Every plain colour input gets a HEX text box next to it (type or paste #ffffff). */
function initColorHexInputs() {
  qa('input[type="color"]:not([data-color-picker])').forEach((picker) => {
    if (picker.dataset.hexReady) return;
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
    panels[0].before(list);
    const activate = (id, save = true) => {
      const target = panels.find((p) => p.dataset.tab === id) || panels[0];
      panels.forEach((p) => { p.hidden = p !== target; });
      buttons.forEach((b) => { const on = b.dataset.tabTarget === target.dataset.tab; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; });
      if (save) { try { sessionStorage.setItem(key, target.dataset.tab); } catch { /* per-page only */ } }
    };
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

function initCommandPalette() {
  const palette = q('[data-command-palette]');
  const input = q('[data-command-input]', palette || document);
  const results = q('[data-command-results]', palette || document);
  if (!palette || !input || !results) return;
  const links = qa('[data-command-source] a').map((link) => ({ label: link.textContent.trim(), href: link.href })).filter((item) => item.label);
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
    const matches = links.filter((item) => !needle || item.label.toLocaleLowerCase('uk-UA').includes(needle)).slice(0, 12);
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
  initConfirmations();
  initDirtyGuard();
  initImagePreviews();
  initSidebar();
  initSidebarCollapse();
  initNavAccordion();
  initHotkeys();
  initCommandPalette();
  initAutoSubmit();
  initTabs();
  initTemplateEditors();
  initDataTables();
  initMediaPresetForm();
  initHelpTips();
  initColorFields();
  initColorHexInputs();
  initCustomSelects();
  initDefaultSubmit();
  initMediaPickers();
  initSlugGenerators();
  initMediaSortable();
  initMultiSelects();
  initProductTabs();
  initCopyControls();
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

initThemeToggle('mc_admin_theme');
