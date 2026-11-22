// One browser preference shared across the storefront and administration.
// The browser's prefers-reduced-motion always overrides every visual mode in CSS.
const KEY = 'nx_motion_preference';
const supported = new Set(['full', 'minimal', 'off']);
const normalize = (value) => supported.has(value) ? value : 'full';

export function initMotionPreference() {
  const root = document.documentElement;
  let mode = 'full';
  try { mode = normalize(window.localStorage.getItem(KEY)); } catch { /* Private browsing may disallow storage. */ }
  const apply = () => {
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
  apply();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
  window.addEventListener('storage', (event) => {
    if (event.key !== KEY) return;
    mode = normalize(event.newValue);
    apply();
  });
}
