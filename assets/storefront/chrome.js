// Storefront header chrome: catalog mega-menu (desktop panel / mobile drawer) and the compact language/currency switchers.
// Everything degrades gracefully: without this script the catalog button is a link to /catalog and the switchers are plain links.
const q = (selector, root = document) => root.querySelector(selector);
const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

function initMegaMenu() {
  const root = q('[data-mega]');
  const toggle = root ? q('[data-mega-toggle]', root) : null;
  const panel = root ? q('[data-mega-panel]', root) : null;
  if (!root || !toggle || !panel) return;
  root.dataset.megaReady = '';

  const desktop = window.matchMedia('(min-width: 1024px)');
  const canHover = window.matchMedia('(hover: hover)');
  let pinned = false;
  let timer = 0;

  const isOpen = () => !panel.hidden;
  const open = (viaClick = false) => {
    window.clearTimeout(timer);
    pinned = viaClick;
    panel.hidden = false;
    root.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    if (!desktop.matches) {
      document.body.classList.add('has-storefront-modal');
      q('.mega__close', panel)?.focus();
    }
  };
  const close = (returnFocus = false) => {
    window.clearTimeout(timer);
    pinned = false;
    panel.hidden = true;
    root.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('has-storefront-modal');
    if (returnFocus) toggle.focus();
  };

  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    if (isOpen() && pinned) close(); else open(true);
  });
  toggle.addEventListener('keydown', (event) => {
    if (event.key === ' ') { event.preventDefault(); toggle.click(); }
    if (event.key === 'ArrowDown') { event.preventDefault(); open(true); q('.mega__cat', panel)?.focus(); }
  });

  // Desktop pointer: open on hover with a short delay, close shortly after the pointer leaves (unless opened by a click).
  root.addEventListener('mouseenter', () => {
    if (!desktop.matches || !canHover.matches) return;
    window.clearTimeout(timer);
    if (!isOpen()) timer = window.setTimeout(() => open(false), 90);
  });
  root.addEventListener('mouseleave', () => {
    if (!desktop.matches || !canHover.matches) return;
    window.clearTimeout(timer);
    if (isOpen() && !pinned) timer = window.setTimeout(() => close(), 200);
  });

  qa('[data-mega-close]', panel).forEach((node) => node.addEventListener('click', () => close(true)));
  document.addEventListener('click', (event) => { if (isOpen() && !root.contains(event.target)) close(); });
  root.addEventListener('focusout', (event) => {
    if (desktop.matches && isOpen() && event.relatedTarget && !root.contains(event.relatedTarget)) close();
  });
  document.addEventListener('keydown', (event) => {
    if (!isOpen()) return;
    if (event.key === 'Escape') { close(true); return; }
    if (event.key === 'Tab' && !desktop.matches) {
      const items = qa(FOCUSABLE, panel).filter((node) => node.offsetParent !== null);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  const onViewportChange = () => { if (isOpen()) close(); };
  if (desktop.addEventListener) desktop.addEventListener("change", onViewportChange); else desktop.addListener(onViewportChange);

  // Mobile accordion: each top-level category expands to its sub-categories.
  qa('[data-mega-expand]', panel).forEach((button) => {
    button.addEventListener('click', () => {
      const group = button.closest('.mega__group');
      const expanded = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      group?.classList.toggle('is-expanded', expanded);
    });
  });
}

function initSwitchers() {
  const all = qa('[data-switcher]');
  if (!all.length) return;
  all.forEach((details) => {
    details.addEventListener('toggle', () => {
      if (details.open) all.forEach((other) => { if (other !== details) other.open = false; });
    });
  });
  document.addEventListener('click', (event) => {
    all.forEach((details) => { if (details.open && !details.contains(event.target)) details.open = false; });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    all.forEach((details) => {
      if (!details.open) return;
      details.open = false;
      q('summary', details)?.focus();
    });
  });
  all.forEach((details) => details.addEventListener('focusout', (event) => {
    if (details.open && event.relatedTarget && !details.contains(event.relatedTarget)) details.open = false;
  }));
}

function boot() {
  try { initMegaMenu(); } catch (_) { /* the plain catalog link keeps working */ }
  try { initSwitchers(); } catch (_) { /* plain links keep working */ }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
