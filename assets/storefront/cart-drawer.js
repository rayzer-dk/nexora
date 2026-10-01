// Slide-in cart (header cart icon). Progressive enhancement: without JS the icon is a normal link to /cart.
const OPEN_SELECTOR = '[data-cart-open]';
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
const t = (key) => String(window.MC_I18N?.[key] ?? key);

let root = null;
let panel = null;
let opener = null;
let isOpen = false;
let loadRun = 0;
let closeTimer = null;
let qtyTimer = null;

const desktop = () => window.matchMedia('(min-width: 1024px)').matches;
const onCartPage = () => Boolean(document.querySelector('[data-cart-page], [data-checkout]'));

function toast(message, type = 'error') {
  if (typeof window.storefrontToast === 'function') { window.storefrontToast(message, type); return; }
  document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message, type } }));
}

function setBadges(count) {
  const value = Number(count || 0);
  document.querySelectorAll('[data-cart-count]').forEach((badge) => {
    badge.textContent = String(value);
    badge.hidden = value < 1;
    badge.classList.remove('is-bump');
    void badge.offsetWidth; // restart the animation
    if (value > 0) badge.classList.add('is-bump');
  });
}

function build() {
  if (root) return;
  root = document.createElement('div');
  root.className = 'cart-drawer';
  root.dataset.cartDrawer = '';
  root.hidden = true;
  const backdrop = document.createElement('div');
  backdrop.className = 'cart-drawer__backdrop';
  backdrop.dataset.cartDrawerClose = '';
  panel = document.createElement('aside');
  panel.className = 'cart-drawer__panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-labelledby', 'cart-drawer-title');
  panel.tabIndex = -1;
  root.append(backdrop, panel);
  document.body.append(root);
  root.addEventListener('click', (event) => {
    if (event.target.closest('[data-cart-drawer-close]')) { event.preventDefault(); close(); }
    const plus = event.target.closest('[data-drawer-plus]');
    const minus = event.target.closest('[data-drawer-minus]');
    if (plus || minus) {
      const input = (plus || minus).closest('form')?.querySelector('[data-drawer-qty]');
      if (input) { if (plus) input.stepUp(); else input.stepDown(); queueUpdate(input.form); }
    }
  });
  root.addEventListener('change', (event) => { if (event.target.matches('[data-drawer-qty]')) queueUpdate(event.target.form); });
  root.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('[data-drawer-update]')) { event.preventDefault(); queueUpdate(form); }
    else if (form.matches('[data-drawer-remove]')) { event.preventDefault(); mutate(form); }
  });
  root.addEventListener('keydown', trapFocus);
}

function trapFocus(event) {
  if (event.key !== 'Tab' || !panel) return;
  const items = Array.from(panel.querySelectorAll(FOCUSABLE)).filter((node) => node.offsetParent !== null);
  if (items.length === 0) { event.preventDefault(); panel.focus(); return; }
  const first = items[0];
  const last = items[items.length - 1];
  if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}

function lockScroll() {
  const gutter = window.innerWidth - document.documentElement.clientWidth;
  document.documentElement.style.setProperty('--cart-drawer-gutter', `${Math.max(0, gutter)}px`);
  document.documentElement.classList.add('has-cart-drawer');
}
function unlockScroll() {
  document.documentElement.classList.remove('has-cart-drawer');
  document.documentElement.style.removeProperty('--cart-drawer-gutter');
}

async function load({ keepScroll = false, focusSelector = '' } = {}) {
  const run = ++loadRun;
  const scroller = panel.querySelector('[data-cart-drawer-lines]');
  const scrollTop = keepScroll && scroller ? scroller.scrollTop : 0;
  try {
    const response = await fetch('/cart/drawer', { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const html = await response.text();
    if (run !== loadRun) return;
    panel.innerHTML = html;
    panel.removeAttribute('aria-busy');
    const body = panel.querySelector('[data-cart-drawer-body]');
    if (body) setBadges(body.dataset.cartCountValue);
    const lines = panel.querySelector('[data-cart-drawer-lines]');
    if (lines && keepScroll) lines.scrollTop = scrollTop;
    const target = focusSelector ? panel.querySelector(focusSelector) : null;
    (target || panel.querySelector('[data-cart-drawer-close]') || panel).focus?.({ preventScroll: true });
  } catch (_) {
    if (run !== loadRun) return;
    panel.removeAttribute('aria-busy');
    window.location.assign('/cart');
  }
}

function open(trigger = null) {
  build();
  if (isOpen) { load({ keepScroll: true }); return; }
  isOpen = true;
  opener = trigger || document.activeElement;
  clearTimeout(closeTimer);
  lockScroll();
  root.hidden = false;
  panel.setAttribute('aria-busy', 'true');
  if (!panel.firstElementChild) {
    const loading = document.createElement('p');
    loading.className = 'cart-drawer__loading';
    loading.textContent = t('js_cart_loading');
    panel.append(loading);
  }
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('is-open')));
  document.addEventListener('keydown', onKey);
  document.querySelectorAll(OPEN_SELECTOR).forEach((node) => node.setAttribute('aria-expanded', 'true'));
  panel.focus({ preventScroll: true });
  load();
}

function close() {
  if (!isOpen) return;
  isOpen = false;
  root.classList.remove('is-open');
  document.removeEventListener('keydown', onKey);
  document.querySelectorAll(OPEN_SELECTOR).forEach((node) => node.setAttribute('aria-expanded', 'false'));
  const finish = () => { root.hidden = true; unlockScroll(); };
  clearTimeout(closeTimer);
  closeTimer = setTimeout(finish, 320);
  root.addEventListener('transitionend', function done(event) {
    if (event.target !== panel) return;
    root.removeEventListener('transitionend', done);
    clearTimeout(closeTimer);
    finish();
  });
  if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus({ preventScroll: true });
}

function onKey(event) { if (event.key === 'Escape') { event.preventDefault(); close(); } }

function queueUpdate(form) {
  if (!form) return;
  clearTimeout(qtyTimer);
  qtyTimer = setTimeout(() => mutate(form), 280);
}

async function mutate(form) {
  const line = form.closest('[data-drawer-item]');
  const id = line?.dataset.drawerItem;
  line?.classList.add('is-loading');
  try {
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    let data = {};
    try { data = await response.json(); } catch (_) { /* non-JSON error */ }
    if (!response.ok || !data.ok) throw new Error(data.message || t('js_cart_update_failed'));
    if (data.cart) setBadges(data.cart.count);
    const removed = form.matches('[data-drawer-remove]');
    await load({ keepScroll: true, focusSelector: !removed && id ? `[data-drawer-item="${id}"] [data-drawer-qty]` : '' });
  } catch (error) {
    line?.classList.remove('is-loading');
    toast(error?.message || t('js_cart_update_failed'));
    load({ keepScroll: true });
  }
}

document.addEventListener('click', (event) => {
  const trigger = event.target.closest?.(OPEN_SELECTOR);
  if (!trigger || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  if (!window.fetch || onCartPage()) return; // the link itself goes to /cart
  event.preventDefault();
  open(trigger);
});

// Any "add to cart" (catalog card, product page) announces itself; the drawer follows on desktop.
document.addEventListener('mc:cart-updated', (event) => {
  const { count, source } = event.detail || {};
  setBadges(count);
  if (source === 'add' && desktop() && !onCartPage()) open(document.querySelector(OPEN_SELECTOR));
  else if (isOpen) load({ keepScroll: true });
});
