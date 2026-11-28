// One browser preference shared across the storefront and administration.
// The browser's prefers-reduced-motion always overrides every visual mode in CSS.
const KEY = 'nx_motion_preference';
const supported = new Set(['full', 'minimal', 'off']);
const normalize = (value) => supported.has(value) ? value : 'full';

export function initMotionPreference() {
  const root = document.documentElement;
  let mode = 'full';
  try { mode = normalize(window.localStorage.getItem(KEY)); } catch { /* Private browsing may disallow storage. */ }
  const connection = navigator.connection;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const apply = () => {
    const low = Boolean(connection?.saveData || /^(?:slow-2g|2g|3g)$/.test(connection?.effectiveType || '') || (navigator.deviceMemory > 0 && navigator.deviceMemory <= 2) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 2) || root.dataset.saveData === '1');
    root.dataset.motionBudget = low ? 'low' : 'normal';
    const navigation = document.getElementById('mc-navigation-transition');
    if (navigation) navigation.textContent = mode === 'full' && !low && !reduced.matches && typeof document.startViewTransition === 'function' ? '@view-transition { navigation: auto; }' : '';
    root.dataset.motion = mode;
    document.querySelectorAll('[data-motion-select]').forEach((select) => {
      if (select instanceof HTMLSelectElement) select.value = mode;
    });
  };
  const bind = () => {
    apply();
    document.querySelectorAll('[data-motion-select]').forEach((select) => {
      if (!(select instanceof HTMLSelectElement) || select.dataset.motionBound) return;
      select.dataset.motionBound = '1';
      select.addEventListener('change', () => {
        mode = normalize(select.value);
        apply();
        try { window.localStorage.setItem(KEY, mode); } catch { /* Keep the choice for this page. */ }
      });
    });
  };
  connection?.addEventListener?.('change', apply);
  reduced.addEventListener?.('change', apply);
  apply();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
  window.addEventListener('storage', (event) => {
    if (event.key !== KEY) return;
    mode = normalize(event.newValue);
    apply();
  });
}
