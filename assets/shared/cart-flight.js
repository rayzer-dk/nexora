// Decorative animation only after a confirmed successful add-to-cart response.
// No timers, image fetching or layout changes are involved in a normal page load.
export function animateCartFlight(source) {
  if (!(source instanceof Element) || document.hidden || document.documentElement.dataset.motion === 'off'
    || document.documentElement.dataset.motion === 'minimal'
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const cart = document.querySelector('[data-cart-open] .reference-cart-icon, [data-cart-open]');
  const scope = source.closest('.quick-view, [data-product-card], .product-page, .product-hero');
  const picture = scope?.querySelector('.quick-view__media img, [data-gallery-main], .catalog-card__media img');
  if (!(picture instanceof HTMLImageElement) || !(cart instanceof Element) || !picture.complete || !picture.naturalWidth) return;
  const start = picture.getBoundingClientRect();
  const end = cart.getBoundingClientRect();
  if (start.width < 16 || start.height < 16 || end.width < 16 || end.height < 16
    || start.bottom < 0 || start.top > window.innerHeight
    || end.bottom < 0 || end.top > window.innerHeight) return;
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
  const flight = fly.animate([
    { transform: 'translate(0, 0) scale(1)', opacity: 0.9 },
    { transform: `translate(${dx * 0.45}px, ${dy * 0.35 - 20}px) scale(.65)`, opacity: 0.85, offset: 0.55 },
    { transform: `translate(${dx}px, ${dy}px) scale(.12)`, opacity: 0.05 }
  ], { duration: 650, easing: 'cubic-bezier(.18,.72,.25,1)', fill: 'forwards' });
  void flight.finished.then(() => fly.remove(), () => fly.remove());
}
