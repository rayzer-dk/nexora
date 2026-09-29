/**
 * Light/dark toggle shared by the storefront and the admin.
 * The store (or the admin shell) sets data-color-scheme="auto" on <html>; a visitor override lives in
 * localStorage and is mirrored to data-theme, which the token layer reads.
 */
export function initThemeToggle(storageKey) {
  const root = document.documentElement;
  const buttons = Array.from(document.querySelectorAll('[data-theme-toggle]'));
  if (buttons.length === 0 || root.dataset.colorScheme !== 'auto') return;
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const effective = () => root.dataset.theme || (media.matches ? 'dark' : 'light');
  const sync = () => buttons.forEach((button) => button.setAttribute('aria-pressed', effective() === 'dark' ? 'true' : 'false'));
  buttons.forEach((button) => button.addEventListener('click', () => {
    const next = effective() === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem(storageKey, next); } catch { /* private mode: the choice lasts for this page only */ }
    sync();
  }));
  media.addEventListener('change', sync);
  sync();
}
