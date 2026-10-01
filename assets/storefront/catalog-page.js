// Catalog / category filters: instant apply on desktop, bottom sheet with an explicit Apply button on mobile.
const form = document.querySelector('[data-catalog-filter-form]');

if (form instanceof HTMLFormElement) {
    const panel = document.querySelector('[data-filters-panel]');
    const backdrop = document.querySelector('[data-filters-backdrop]');
    const openButtons = document.querySelectorAll('[data-filters-open]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const DEFAULTS = { sort: 'newest', per_page: '24' };
    let timer = 0;

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
        const fields = [...form.elements, ...document.querySelectorAll('[form="catalog-filters"]')];
        fields.forEach((field) => {
            if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;
            if (!field.name || field.type === 'range') return;
            if ((field.type === 'radio' || field.type === 'checkbox') && !field.checked) return;
            if (field.value === '' || DEFAULTS[field.name] === field.value) field.disabled = true;
        });
    });

    // Fields disabled by the submit handler must come back enabled after history navigation.
    window.addEventListener('pageshow', () => { [...form.elements, ...document.querySelectorAll('[form="catalog-filters"]')].forEach((field) => { if ('disabled' in field) field.disabled = false; }); });

    const submitNow = () => { window.clearTimeout(timer); form.requestSubmit(); };
    const submitSoon = () => { window.clearTimeout(timer); timer = window.setTimeout(submitNow, 450); };

    form.addEventListener('change', (event) => {
        if (!desktop.matches) return;
        const target = event.target;
        if (target instanceof HTMLInputElement && target.type === 'range') return;
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
