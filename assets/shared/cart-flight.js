// Decorative animation only after a confirmed successful add-to-cart response.
// No timers, image fetching or layout changes are involved in a normal page load.
export function animateCartFlight(source) {
  if (!(source instanceof Element) || document.hidden || document.documentElement.dataset.motion === 'off'
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const cart = document.querySelector('[data-cart-open] .reference-cart-icon') || document.querySelector('[data-cart-open]');
  const choice = document.body.dataset.cartEffect || 'auto';
  if (choice === 'off' || !(cart instanceof Element) || typeof cart.animate !== 'function') return;
  const mode = document.documentElement.dataset.motion;
  const pulse = (minimal = false) => {
    if (!cart.isConnected || document.hidden || ['off'].includes(document.documentElement.dataset.motion)
      || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const subtle = minimal || document.documentElement.dataset.motion === 'minimal';
    cart.animate(subtle
      ? [{ opacity: 1 }, { opacity: .65 }, { opacity: 1 }]
      : [{ transform: 'scale(1)' }, { transform: 'scale(1.18)' }, { transform: 'scale(1)' }],
      { duration: subtle ? 160 : 240, easing: 'ease-out' });
  };
  if (choice === 'minimal' || choice === 'pulse') { pulse(choice === 'minimal' || mode === 'minimal'); return; }
  if (mode === 'minimal') { if (choice !== 'auto') pulse(true); return; }
  const scope = source.closest('.quick-view, [data-product-card], .product-page, .product-hero');
  const picture = scope?.querySelector('.quick-view__media img, [data-gallery-main], .catalog-card__media img');
  if (!(picture instanceof HTMLImageElement) || !(cart instanceof Element) || !picture.complete || !picture.naturalWidth) return;
  const start = picture.getBoundingClientRect();
  const end = cart.getBoundingClientRect();
  if (start.width < 16 || start.height < 16 || end.width < 16 || end.height < 16
    || start.bottom < 0 || start.top > window.innerHeight
    || end.bottom < 0 || end.top > window.innerHeight) return;
  if (typeof picture.animate !== 'function') return;
  const dynamic = choice === 'flight' || document.body.dataset.themePreset === 'dynamic';
  const fly = document.createElement('img');
  fly.className = 'mc-motion-cart-flight';
  fly.src = picture.currentSrc || picture.src;
  fly.alt = '';
  fly.setAttribute('aria-hidden', 'true');
  fly.style.left = `${start.left}px`;
  fly.style.top = `${start.top}px`;
  fly.style.width = `${start.width}px`;
  fly.style.height = `${start.height}px`;
  document.body.append(fly);
  const dx = end.left + end.width / 2 - start.left - start.width / 2;
  const dy = end.top + end.height / 2 - start.top - start.height / 2;
  const flight = fly.animate(dynamic ? [
    { transform: 'translate(0, 0) scale(1)', opacity: .96 },
    { transform: `translate(${dx * .24}px, ${dy * .28 - 24}px) scale(.87) rotate(-9deg)`, opacity: .96, offset: .28 },
    { transform: `translate(${dx * .72}px, ${dy * .78 - 30}px) scale(.43) rotate(-5deg)`, opacity: .92, offset: .73 },
    { transform: `translate(${dx}px, ${dy}px) scale(.09)`, opacity: .08 }
  ] : [
    { transform: 'translate(0, 0) scale(1)', opacity: 0.9 },
    { transform: `translate(${dx * 0.45}px, ${dy * 0.35 - 20}px) scale(.65)`, opacity: 0.85, offset: 0.55 },
    { transform: `translate(${dx}px, ${dy}px) scale(.12)`, opacity: 0.05 }
  ], { duration: dynamic ? 720 : 650, easing: 'cubic-bezier(.18,.72,.25,1)', fill: 'forwards' });
  void flight.finished.then(() => {
    fly.remove();
    if (dynamic && cart.isConnected && !document.hidden
      && !window.matchMedia('(prefers-reduced-motion: reduce)').matches
      && !['off', 'minimal'].includes(document.documentElement.dataset.motion)) {
      pulse();
    }
  }, () => fly.remove());
}
