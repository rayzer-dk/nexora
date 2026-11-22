// User-triggered motion only. No animation or timers run on idle pages.
export const motionEnabled = () =>
  document.documentElement.dataset.motion !== 'off' &&
  !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function playMotion(element, frames, options = {}) {
  if (!motionEnabled() || !(element instanceof Element) || typeof element.animate !== 'function') return null;
  return element.animate(frames, { duration: 240, easing: 'ease-out', ...options });
}

export function celebrateWishlist(button) {
  if (!(button instanceof HTMLElement)) return;
  button.classList.add('is-added');
  button.setAttribute('aria-pressed', 'true');
  const icon = button.querySelector('.ui-icon');
  if (icon instanceof Element && icon.matches('svg')) {
    icon.style.fill = 'currentColor';
    playMotion(icon, [
      { transform: 'scale(1)', opacity: 0.7 },
      { transform: 'scale(1.25)', opacity: 1, offset: 0.5 },
      { transform: 'scale(1)', opacity: 1 },
    ], { duration: 360 });
  }
}

// The countdown represents the actual toast timeout; it is absent for permanent notices.
export function addToastCountdown(item, timeout) {
  if (!motionEnabled() || !(item instanceof HTMLElement) || !(timeout > 0)) return;
  item.style.position = 'relative';
  item.style.overflow = 'hidden';
  const bar = document.createElement('span');
  bar.className = 'mc-toast-countdown';
  bar.setAttribute('aria-hidden', 'true');
  Object.assign(bar.style, {
    position: 'absolute', bottom: '0', left: '0', width: '100%', height: '2px',
    background: 'currentColor', opacity: '0.55', transformOrigin: 'left', pointerEvents: 'none',
  });
  item.append(bar);
  playMotion(bar, [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
    { duration: timeout, easing: 'linear', fill: 'forwards' });
}
