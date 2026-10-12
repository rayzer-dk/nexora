// Popovers built on <details> (help tips, switchers, menus): a click or tap outside, Escape, or opening another one closes them,
// so they behave like the help tips that have their own button. One delegated listener serves the whole page and works for content added later.
const SELECTOR = 'details.seo-tip, details.fx-pop, details.store-switcher, details.admin-user-menu, details.admin-column-picker, details.admin-save-view, details.dash-notes';

function openPopovers() {
  return Array.from(document.querySelectorAll(SELECTOR)).filter((el) => el.open);
}

document.addEventListener('pointerdown', (event) => {
  const target = event.target instanceof Element ? event.target : null;
  for (const el of openPopovers()) {
    if (!target || !el.contains(target)) el.open = false;
  }
});

document.addEventListener('toggle', (event) => {
  const el = event.target;
  if (!(el instanceof HTMLDetailsElement) || !el.open || !el.matches(SELECTOR)) return;
  for (const other of openPopovers()) {
    if (other !== el && !other.contains(el) && !el.contains(other)) other.open = false;
  }
}, true);

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  const open = openPopovers();
  if (open.length === 0) return;
  const last = open[open.length - 1];
  last.open = false;
  last.querySelector('summary')?.focus();
});
