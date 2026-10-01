// Content-language tabs: switch in-page language panels, copy the default language into an empty one, keep the ✓/• marker live.
const PANEL = '[data-lang-panel]';

function panelsOf(bar: HTMLElement): HTMLElement[] {
  const scope = bar.closest<HTMLElement>('[data-lang-scope]') ?? document;
  return Array.from(scope.querySelectorAll<HTMLElement>(PANEL));
}

function activate(bar: HTMLElement, id: string, updateHash: boolean): void {
  const panels = panelsOf(bar);
  if (!panels.some((panel) => panel.dataset.langPanel === id)) return;
  for (const panel of panels) panel.hidden = panel.dataset.langPanel !== id;
  for (const tab of bar.querySelectorAll<HTMLElement>('[data-lang-tab]')) {
    const on = tab.dataset.langTab === id;
    tab.setAttribute('aria-selected', on ? 'true' : 'false');
    tab.closest('.lang-tabs__item')?.classList.toggle('is-current', on);
  }
  if (updateHash) {
    try { history.replaceState(null, '', `#${id}`); } catch { /* history may be blocked in sandboxed previews */ }
  }
}

function refreshMark(bar: HTMLElement, panel: HTMLElement): void {
  const code = panel.dataset.langPanel?.replace(/^lang-/, '') ?? '';
  const required = (panel.dataset.langRequired ?? '').split(',').filter(Boolean);
  if (!code || required.length === 0) return;
  const done = required.every((name) => (panel.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[name="${name}"]`)?.value ?? '').trim() !== '');
  const item = bar.querySelector<HTMLElement>(`[data-lang-item="${code}"]`);
  if (!item) return;
  item.classList.toggle('is-done', done);
  item.classList.toggle('is-missing', !done);
  const mark = item.querySelector<HTMLElement>('[data-lang-mark]');
  if (mark) mark.textContent = done ? '✓' : '•';
}

function initBar(bar: HTMLElement): void {
  const panels = panelsOf(bar);
  if (panels.length === 0) return;
  for (const tab of bar.querySelectorAll<HTMLElement>('[data-lang-tab]')) {
    tab.addEventListener('click', () => activate(bar, tab.dataset.langTab ?? '', true));
  }
  const fromHash = window.location.hash.replace(/^#/, '');
  const initial = panels.some((p) => p.dataset.langPanel === fromHash) ? fromHash : (panels.find((p) => p.dataset.langInitial !== undefined)?.dataset.langPanel ?? panels[0]?.dataset.langPanel ?? '');
  activate(bar, initial, false);
  for (const panel of panels) {
    panel.addEventListener('input', () => refreshMark(bar, panel));
    panel.querySelector<HTMLElement>('[data-copy-default]')?.addEventListener('click', () => {
      const source = panels.find((p) => p.dataset.langSource !== undefined);
      if (!source || source === panel) return;
      const fields = Array.from(panel.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('[data-translate-field]'));
      const anyFilled = fields.some((field) => field.value.trim() !== '');
      if (anyFilled && !window.confirm(bar.dataset.langOverwrite ?? '')) return;
      for (const field of fields) {
        const from = source.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[data-translate-field][name="${field.name}"]`);
        if (from && (anyFilled || from.value !== '')) {
          field.value = from.value;
          field.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
    });
    refreshMark(bar, panel);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('[data-lang-tabs]').forEach(initBar);
});
