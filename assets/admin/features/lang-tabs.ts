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
    tab.setAttribute('aria-pressed', on ? 'true' : 'false');
    tab.closest('.lang-tabs__item')?.classList.toggle('is-current', on);
  }
  if (updateHash) {
    try { history.replaceState(null, '', `#${id}`); } catch { /* history may be blocked in sandboxed previews */ }
  }
  bar.dataset.langActive = id;
  bar.dispatchEvent(new CustomEvent('lang-tabs:change', { detail: id, bubbles: true }));
}

// One language may be spread over several panels (the form-wide bar puts each field in its own one): the mark takes the
// required fields of all of them and strips HTML, so an empty rich-text editor does not count as text.
function refreshMark(bar: HTMLElement, id: string): void {
  const code = id.replace(/^lang-/, '');
  const scope = bar.closest<HTMLElement>('[data-lang-scope]') ?? document;
  const panels = panelsOf(bar).filter((panel) => panel.dataset.langPanel === id);
  const required = panels.flatMap((panel) => (panel.dataset.langRequired ?? '').split(',').filter(Boolean));
  if (!code || required.length === 0) return;
  const text = (value: string): string => value.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
  const done = required.every((name) => text(scope.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[name="${name}"]`)?.value ?? '') !== '');
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
    panel.addEventListener('input', () => refreshMark(bar, panel.dataset.langPanel ?? ''));
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
    refreshMark(bar, panel.dataset.langPanel ?? '');
  }
}

type Field = HTMLInputElement | HTMLTextAreaElement;

// Form-wide bar (product, category): "copy from the main language" and "translate" fill the fields of the language on screen.
function initFormActions(bar: HTMLElement): void {
  const box = bar.closest('.lang-form-bar')?.querySelector<HTMLElement>('[data-lang-actions]');
  const scope = bar.closest<HTMLElement>('[data-lang-scope]');
  if (!box || !scope) return;
  const source = box.dataset.langSource ?? '';
  const status = box.querySelector<HTMLElement>('[data-lang-status]');
  const say = (text: string): void => { if (status) status.textContent = text; };
  const fieldsOf = (id: string): Field[] => Array.from(scope.querySelectorAll<Field>(`[data-lang-panel="${id}"] [data-lang-field]`));
  const pairs = (): Array<{ from: Field; to: Field }> => {
    const target = bar.dataset.langActive ?? '';
    const from = new Map(fieldsOf(source).map((field) => [field.dataset.langField ?? '', field]));
    return fieldsOf(target).flatMap((to) => {
      const match = from.get(to.dataset.langField ?? '');
      return match && match.value.trim() !== '' ? [{ from: match, to }] : [];
    });
  };
  const write = (to: Field, value: string): void => {
    to.value = value;
    to.dispatchEvent(new Event('input', { bubbles: true }));
  };
  const show = (): void => { box.hidden = (bar.dataset.langActive ?? source) === source; say(''); };
  bar.addEventListener('lang-tabs:change', show);
  show();

  box.querySelector('[data-lang-copy]')?.addEventListener('click', () => {
    const jobs = pairs();
    if (jobs.length === 0) { say(box.dataset.msgNothing ?? ''); return; }
    if (jobs.some(({ to }) => to.value.trim() !== '') && !window.confirm(bar.dataset.langOverwrite ?? '')) return;
    jobs.forEach(({ from, to }) => write(to, from.value));
  });

  const translate = box.querySelector<HTMLButtonElement>('[data-lang-translate]');
  translate?.addEventListener('click', async () => {
    let jobs = pairs();
    if (jobs.some(({ to }) => to.value.trim() !== '') && !window.confirm(box.dataset.msgOverwrite ?? '')) {
      jobs = jobs.filter(({ to }) => to.value.trim() === '');
    }
    if (jobs.length === 0) { say(box.dataset.msgNothing ?? ''); return; }
    const texts: Record<string, string> = {};
    jobs.forEach(({ from }, index) => { texts[`f${index}`] = from.value; });
    const body = new URLSearchParams({
      _token: box.dataset.aiToken ?? '',
      task: 'translate_fields',
      provider: box.querySelector<HTMLInputElement | HTMLSelectElement>('[data-ai-provider]')?.value ?? '',
    });
    body.append('fields[target]', (bar.dataset.langActive ?? '').replace(/^lang-/, ''));
    body.append('fields[source]', box.dataset.sourceLocale ?? '');
    body.append('fields[json]', JSON.stringify(texts));
    translate.disabled = true;
    say(box.dataset.msgWorking ?? '');
    try {
      const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json().catch(() => null) as { ok?: boolean; message?: string; fields?: Record<string, unknown> } | null;
      if (!response.ok || !data?.ok) throw new Error(data?.message || (box.dataset.msgFailed ?? ''));
      jobs.forEach(({ to }, index) => {
        const value = data.fields?.[`f${index}`];
        if (typeof value === 'string' && value !== '') write(to, value);
      });
      say(box.dataset.msgDone ?? '');
    } catch (error) {
      say(error instanceof Error ? error.message : (box.dataset.msgFailed ?? ''));
    } finally {
      translate.disabled = false;
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('[data-lang-tabs]').forEach((bar) => {
    initBar(bar);
    initFormActions(bar);
  });
});

// A required field of a language that is not on screen cannot be focused by the browser: open that language first.
document.addEventListener('invalid', (event) => {
  const field = event.target;
  if (!(field instanceof HTMLElement)) return;
  const panel = field.closest<HTMLElement>(PANEL);
  if (!panel || !panel.hidden) return;
  const bar = (panel.closest<HTMLElement>('[data-lang-scope]') ?? document).querySelector<HTMLElement>('[data-lang-tabs]');
  if (bar) activate(bar, panel.dataset.langPanel ?? '', false);
}, true);
