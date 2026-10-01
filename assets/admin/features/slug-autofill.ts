// New pages: while the editor types the title, fill the (still untouched) address field through the same transliteration the server uses.
function initSlugAutofill(): void {
  for (const title of document.querySelectorAll<HTMLInputElement>('input[data-slug-from]')) {
    const form = title.closest('form');
    const slug = form?.querySelector<HTMLInputElement>(`input[name="${title.dataset.slugFrom}"][data-slug-auto]`);
    if (!form || !slug) continue;
    let auto = slug.value.trim() === '';
    let timer = 0;
    slug.addEventListener('input', (event) => { if (event.isTrusted) auto = false; });
    title.addEventListener('input', () => {
      if (!auto) return;
      window.clearTimeout(timer);
      timer = window.setTimeout(async () => {
        const text = title.value.trim();
        if (text === '') { slug.value = ''; return; }
        const locale = document.querySelector<HTMLSelectElement>('.admin-context select[name="locale"]')?.value ?? 'uk-UA';
        try {
          const response = await fetch(`/admin/api/slug?text=${encodeURIComponent(text)}&locale=${encodeURIComponent(locale)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const data = (await response.json()) as { slug?: string };
          if (auto && response.ok) slug.value = data.slug ?? '';
        } catch { /* the server generates the address on save when this stays empty */ }
      }, 250);
    });
  }
}

document.addEventListener('DOMContentLoaded', initSlugAutofill);
