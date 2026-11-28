// Catalog / category filters: instant apply on desktop, bottom sheet with an explicit Apply button on mobile.
const showCatalogSkeleton = (target) => {
    if (!(target instanceof HTMLElement)) return null;
    const cards = Array.from(target.querySelectorAll(':scope > .catalog-card'));
    if (!cards.length) return null;
    const geometry = cards.map(card => {
      const rect = card.getBoundingClientRect();
      const media = card.querySelector('.catalog-card__media')?.getBoundingClientRect();
      return { height: rect.height, ratio: media?.height ? media.width / media.height : 1 };
    });
    const skeleton = document.createElement('div');
    skeleton.className = target.className + ' mc-motion-catalog-skeleton';
    skeleton.setAttribute('role', 'status');
    skeleton.setAttribute('aria-label', String(window.MC_I18N?.js_loading || 'Loading'));
    for (const item of geometry) {
      const card = document.createElement('div');
      card.className = 'mc-motion-catalog-skeleton__card';
      card.setAttribute('aria-hidden', 'true');
      card.style.height = item.height + 'px';
      const image = document.createElement('span');
      image.className = 'mc-motion-catalog-skeleton__media';
      image.style.aspectRatio = String(item.ratio);
      const line = document.createElement('span');
      line.className = 'mc-motion-catalog-skeleton__line';
      card.append(image, line);
      skeleton.append(card);
    }
    target.before(skeleton);
    target.hidden = true;
    return skeleton;
};
const form = document.querySelector('[data-catalog-filter-form]');

if (form instanceof HTMLFormElement) {
    const panel = document.querySelector('[data-filters-panel]');
    const backdrop = document.querySelector('[data-filters-backdrop]');
    const openButtons = document.querySelectorAll('[data-filters-open]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const DEFAULTS = { sort: 'newest', per_page: '24' };
    let timer = 0;
    const disabledForSubmit = new Set();

    const syncMode = () => form.classList.toggle('is-instant', desktop.matches);
    syncMode();
    desktop.addEventListener?.('change', () => { syncMode(); setOpen(false); });

    function setOpen(open) {
        if (!(panel instanceof HTMLElement)) return;
        panel.classList.toggle('is-open', open);
        if (backdrop instanceof HTMLElement) backdrop.hidden = !open;
        document.body.classList.toggle('has-storefront-modal', open);
        openButtons.forEach((button) => button.setAttribute('aria-expanded', open ? 'true' : 'false'));
        if (open) panel.querySelector('summary, input, button')?.focus?.({ preventScroll: true });
        else if (!desktop.matches) openButtons[0]?.focus?.({ preventScroll: true });
    }
    openButtons.forEach((button) => button.addEventListener('click', () => setOpen(true)));
    document.querySelectorAll('[data-filters-close]').forEach((button) => button.addEventListener('click', () => setOpen(false)));
    backdrop?.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && panel?.classList.contains('is-open')) setOpen(false); });

    // Keep the URL short and shareable: empty fields and default values are not submitted.
    form.addEventListener('submit', () => {
        window.clearTimeout(timer);
        // form.elements already includes external controls associated through the form attribute.
        const fields = Array.from(form.elements);
        fields.forEach((field) => {
            if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;
            if (!field.name || field.disabled || field.type === 'range') return;
            if ((field.type === 'radio' || field.type === 'checkbox') && !field.checked) return;
            if (field.value === '' || DEFAULTS[field.name] === field.value) {
                disabledForSubmit.add(field);
                field.disabled = true;
            }
        });
        showCatalogSkeleton(document.querySelector('.product-grid:not(.mc-motion-catalog-skeleton)'));
    });

    // Fields disabled by the submit handler must come back enabled after history navigation.
    window.addEventListener('pageshow', () => {
        window.clearTimeout(timer);
        disabledForSubmit.forEach((field) => { field.disabled = false; });
        disabledForSubmit.clear();
        document.querySelectorAll('.mc-motion-catalog-skeleton').forEach((node) => node.remove());
        const grid = document.querySelector('.product-grid:not(.mc-motion-catalog-skeleton)');
        if (grid instanceof HTMLElement) grid.hidden = false;
    });

    const submitNow = () => { window.clearTimeout(timer); form.requestSubmit(); };
    const submitSoon = () => { window.clearTimeout(timer); timer = window.setTimeout(submitNow, 450); };

    form.addEventListener('change', (event) => {
        if (!desktop.matches) return;
        const target = event.target;
        if (target instanceof HTMLInputElement && target.type === 'range') return;
        if (target instanceof HTMLSelectElement && target.matches('[data-filter-auto]')) return;
        submitSoon();
    });
    document.querySelectorAll('select[data-filter-auto]').forEach((select) => select.addEventListener('change', submitNow));

    // Dual price slider kept in sync with the two number inputs.
    const range = form.querySelector('[data-price-range]');
    if (range instanceof HTMLElement) {
        const lo = Number(range.dataset.lo || 0);
        const hi = Number(range.dataset.hi || 0);
        const minRange = range.querySelector('[data-range-min]');
        const maxRange = range.querySelector('[data-range-max]');
        const minInput = form.querySelector('[data-price-min]');
        const maxInput = form.querySelector('[data-price-max]');
        const fill = range.querySelector('[data-range-fill]');
        if (minRange instanceof HTMLInputElement && maxRange instanceof HTMLInputElement && minInput instanceof HTMLInputElement && maxInput instanceof HTMLInputElement && hi > lo) {
            const paint = () => {
                const a = ((Number(minRange.value) - lo) / (hi - lo)) * 100;
                const b = ((Number(maxRange.value) - lo) / (hi - lo)) * 100;
                if (fill instanceof HTMLElement) { fill.style.insetInlineStart = `${a}%`; fill.style.insetInlineEnd = `${100 - b}%`; }
            };
            const fromRange = (changed) => {
                if (Number(minRange.value) > Number(maxRange.value)) {
                    if (changed === minRange) minRange.value = maxRange.value; else maxRange.value = minRange.value;
                }
                minInput.value = Number(minRange.value) <= lo ? '' : minRange.value;
                maxInput.value = Number(maxRange.value) >= hi ? '' : maxRange.value;
                paint();
            };
            const fromInput = () => {
                const a = minInput.value.trim() === '' ? lo : Number(minInput.value.replace(',', '.'));
                const b = maxInput.value.trim() === '' ? hi : Number(maxInput.value.replace(',', '.'));
                if (Number.isFinite(a)) minRange.value = String(Math.max(lo, Math.min(hi, a)));
                if (Number.isFinite(b)) maxRange.value = String(Math.max(lo, Math.min(hi, b)));
                paint();
            };
            minRange.addEventListener('input', () => fromRange(minRange));
            maxRange.addEventListener('input', () => fromRange(maxRange));
            // A slider drag finishes with "change": apply once, not on every step.
            minRange.addEventListener('change', () => { if (desktop.matches) submitSoon(); });
            maxRange.addEventListener('change', () => { if (desktop.matches) submitSoon(); });
            minInput.addEventListener('input', fromInput);
            maxInput.addEventListener('input', fromInput);
            paint();
        }
    }

    // Remember the sidebar scroll position across instant reloads.
    const body = form.querySelector('.cf__body');
    try {
        const saved = sessionStorage.getItem('cf_scroll');
        if (saved && body instanceof HTMLElement) body.scrollTop = Number(saved) || 0;
        form.addEventListener('submit', () => { if (body instanceof HTMLElement) sessionStorage.setItem('cf_scroll', String(body.scrollTop)); });
    } catch { /* storage unavailable */ }
}

// Paging without a full reload: the pager links stay ordinary links (they work without script and can be opened in a new tab);
// with script the product grid and the pager are swapped in place and the address is updated.
(() => {
  const grid = () => document.querySelector('.product-grid:not(.mc-motion-catalog-skeleton)');
  if (!grid() || !window.fetch || !window.history?.pushState) return;
  let pending = null; // the newest request wins: a click or Back while loading must not leave the address and the grid out of step
  let activeSkeleton = null;
  const showSkeleton = (target) => {
    activeSkeleton?.remove();
    if (target) target.hidden = false;
    activeSkeleton = showCatalogSkeleton(target);
    return activeSkeleton;
  };
  const swap = (doc) => {
    ['.product-grid:not(.mc-motion-catalog-skeleton)', 'nav.pager'].forEach((selector) => {
      const current = document.querySelector(selector);
      const next = doc.querySelector(selector);
      if (current && next) current.replaceWith(next);
      else if (current) current.remove();
    });
    document.dispatchEvent(new CustomEvent('commerce:content-updated'));
  };
  const load = async (url, push) => {
    pending?.abort();
    const request = new AbortController();
    pending = request;
    const target = grid();
    target?.setAttribute('aria-busy', 'true');
    const skeleton = showSkeleton(target);
    try {
      const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: request.signal });
      if (!response.ok) throw new Error('page');
      const html = await response.text();
      if (pending !== request || request.signal.aborted) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      if (!doc.querySelector('.product-grid')) throw new Error('page');
      swap(doc);
      if (push) history.pushState({ mcCatalog: true }, '', url);
      grid()?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.dataset.motion !== 'full' || document.documentElement.dataset.motionBudget === 'low' ? 'auto' : 'smooth' });
    } catch (error) {
      if (pending === request && error?.name !== 'AbortError') window.location.assign(url);
    } finally {
      if (pending === request) {
        pending = null;
        if (activeSkeleton === skeleton) { skeleton?.remove(); activeSkeleton = null; }
        grid()?.removeAttribute('aria-busy');
        grid()?.removeAttribute('hidden');
      }
    }
  };
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target instanceof Element ? event.target.closest('nav.pager a[href]') : null;
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    event.preventDefault();
    load(url.href, true);
  });
  window.addEventListener('popstate', () => { load(window.location.href, false); });
})();
