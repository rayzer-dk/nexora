// Calendar and time picker for <input type="date">, <input type="time"> and <input type="datetime-local">.
// A styled popover instead of the browser's own one; the value keeps the native format (2026-10-13, 14:30, 2026-10-13T14:30), so forms,
// validation and the server do not change. Typing in the field still works. Add data-native-picker to an input to keep the browser's picker.
const SELECTOR = 'input[type="date"], input[type="time"], input[type="datetime-local"]';
const t = (key, fallback) => String(window.MC_I18N?.[key] ?? fallback);
const pad = (n) => String(n).padStart(2, '0');
const lang = () => document.documentElement.lang || 'en';

let pop = null;
let current = null; // { input, kind, view: {y, m}, sel: {y, m, d} | null, hh, mm }

function parse(value, kind) {
  const out = { date: null, hh: 12, mm: 0 };
  const [datePart, timePart] = kind === 'datetime-local' ? String(value || '').split('T') : kind === 'date' ? [String(value || ''), ''] : ['', String(value || '')];
  const d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(datePart || '');
  if (d) out.date = { y: Number(d[1]), m: Number(d[2]) - 1, d: Number(d[3]) };
  const tm = /^(\d{2}):(\d{2})/.exec(timePart || '');
  if (tm) { out.hh = Number(tm[1]); out.mm = Number(tm[2]); }
  return out;
}

function format(state) {
  const date = state.sel ? `${state.sel.y}-${pad(state.sel.m + 1)}-${pad(state.sel.d)}` : '';
  const time = `${pad(state.hh)}:${pad(state.mm)}`;
  if (state.kind === 'date') return date;
  if (state.kind === 'time') return time;
  return date ? `${date}T${time}` : '';
}

function bound(input, name) {
  const raw = String(input.getAttribute(name) || '');
  const m = /^(\d{4}-\d{2}-\d{2})/.exec(raw);
  return m ? m[1] : '';
}

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function commit(close) {
  if (!current) return;
  const { input } = current;
  const value = format(current);
  if (input.value !== value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
  if (close) closePicker(true);
  else render();
}

function render() {
  if (!pop || !current) return;
  const { kind, input } = current;
  const children = [];
  if (kind !== 'time') {
    const { y, m } = current.view;
    const head = el('div', 'mc-dp__head');
    const prev = el('button', 'mc-dp__nav', '‹');
    prev.type = 'button';
    prev.setAttribute('aria-label', t('js_datepicker_prev', 'Previous month'));
    prev.addEventListener('click', () => { current.view = m === 0 ? { y: y - 1, m: 11 } : { y, m: m - 1 }; render(); });
    const next = el('button', 'mc-dp__nav', '›');
    next.type = 'button';
    next.setAttribute('aria-label', t('js_datepicker_next', 'Next month'));
    next.addEventListener('click', () => { current.view = m === 11 ? { y: y + 1, m: 0 } : { y, m: m + 1 }; render(); });
    const title = el('strong', 'mc-dp__title', new Intl.DateTimeFormat(lang(), { month: 'long', year: 'numeric' }).format(new Date(y, m, 1)));
    head.append(prev, title, next);
    children.push(head);

    const grid = el('div', 'mc-dp__grid');
    grid.setAttribute('role', 'grid');
    for (let i = 0; i < 7; i += 1) {
      grid.append(el('span', 'mc-dp__dow', new Intl.DateTimeFormat(lang(), { weekday: 'short' }).format(new Date(2024, 0, 1 + i))));
    }
    const first = (new Date(y, m, 1).getDay() + 6) % 7; // the week starts on Monday
    const days = new Date(y, m + 1, 0).getDate();
    const min = bound(input, 'min');
    const max = bound(input, 'max');
    const today = new Date();
    for (let i = 0; i < first; i += 1) grid.append(el('span', 'mc-dp__blank'));
    for (let d = 1; d <= days; d += 1) {
      const iso = `${y}-${pad(m + 1)}-${pad(d)}`;
      const button = el('button', 'mc-dp__day', String(d));
      button.type = 'button';
      if (current.sel && current.sel.y === y && current.sel.m === m && current.sel.d === d) { button.classList.add('is-selected'); button.setAttribute('aria-pressed', 'true'); }
      if (today.getFullYear() === y && today.getMonth() === m && today.getDate() === d) button.classList.add('is-today');
      if ((min && iso < min) || (max && iso > max)) button.disabled = true;
      button.addEventListener('click', () => {
        current.sel = { y, m, d };
        commit(kind === 'date');
      });
      grid.append(button);
    }
    children.push(grid);
  }
  if (kind !== 'date') {
    const row = el('div', 'mc-dp__time');
    row.append(el('span', 'mc-dp__time-label', t('js_datepicker_time', 'Time')));
    const hours = el('select', 'mc-dp__select');
    const minutes = el('select', 'mc-dp__select');
    hours.setAttribute('aria-label', t('js_datepicker_hours', 'Hours'));
    minutes.setAttribute('aria-label', t('js_datepicker_minutes', 'Minutes'));
    for (let h = 0; h < 24; h += 1) { const o = el('option', '', pad(h)); o.value = String(h); o.selected = h === current.hh; hours.append(o); }
    for (let mm = 0; mm < 60; mm += 1) { const o = el('option', '', pad(mm)); o.value = String(mm); o.selected = mm === current.mm; minutes.append(o); }
    hours.addEventListener('change', () => { current.hh = Number(hours.value); commit(false); });
    minutes.addEventListener('change', () => { current.mm = Number(minutes.value); commit(false); });
    row.append(hours, el('span', 'mc-dp__colon', ':'), minutes);
    children.push(row);
  }
  const foot = el('div', 'mc-dp__foot');
  const now = el('button', 'mc-dp__link', kind === 'time' ? t('js_datepicker_now', 'Now') : t('js_datepicker_today', 'Today'));
  now.type = 'button';
  now.addEventListener('click', () => {
    const d = new Date();
    if (kind !== 'time') { current.sel = { y: d.getFullYear(), m: d.getMonth(), d: d.getDate() }; current.view = { y: d.getFullYear(), m: d.getMonth() }; }
    if (kind !== 'date') { current.hh = d.getHours(); current.mm = d.getMinutes(); }
    commit(kind === 'date');
  });
  foot.append(now);
  if (!input.required && input.value) {
    const clear = el('button', 'mc-dp__link', t('js_datepicker_clear', 'Clear'));
    clear.type = 'button';
    clear.addEventListener('click', () => { current.sel = null; input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true })); closePicker(true); });
    foot.append(clear);
  }
  if (kind !== 'date') {
    const done = el('button', 'mc-dp__done', t('js_datepicker_done', 'Done'));
    done.type = 'button';
    done.addEventListener('click', () => { if (kind === 'datetime-local' && !current.sel) { const d = new Date(); current.sel = { y: d.getFullYear(), m: d.getMonth(), d: d.getDate() }; } commit(true); });
    foot.append(done);
  }
  children.push(foot);
  pop.replaceChildren(...children);
  place();
}

function place() {
  if (!pop || !current) return;
  const rect = current.input.getBoundingClientRect();
  const width = pop.offsetWidth || 280;
  const height = pop.offsetHeight || 320;
  const below = window.innerHeight - rect.bottom;
  const top = below >= height + 8 || rect.top < height + 8 ? rect.bottom + 6 : rect.top - height - 6;
  pop.style.top = `${Math.max(8, Math.min(top, window.innerHeight - height - 8))}px`;
  pop.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
}

function closePicker(returnFocus) {
  if (!pop) return;
  const input = current?.input;
  pop.remove();
  pop = null;
  current = null;
  document.removeEventListener('keydown', onKey, true);
  window.removeEventListener('scroll', place, true);
  window.removeEventListener('resize', place);
  if (returnFocus && input) input.focus({ preventScroll: true });
}

function onKey(event) {
  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closePicker(true); }
}

function openPicker(input) {
  if (current && current.input === input) return;
  closePicker(false);
  const kind = input.type;
  const parsed = parse(input.value, kind);
  const now = new Date();
  current = { input, kind, sel: parsed.date, view: parsed.date ? { y: parsed.date.y, m: parsed.date.m } : { y: now.getFullYear(), m: now.getMonth() }, hh: parsed.hh, mm: parsed.mm };
  pop = el('div', 'mc-dp');
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', input.getAttribute('aria-label') || input.name || '');
  document.body.append(pop);
  render();
  document.addEventListener('keydown', onKey, true);
  window.addEventListener('scroll', place, true);
  window.addEventListener('resize', place);
}

export function initDatePickers() {
  if (window.__mcDatePickers) return;
  window.__mcDatePickers = true;
  document.addEventListener('pointerdown', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (pop && !pop.contains(target) && !(current && current.input === target)) closePicker(false);
  }, true);
  document.addEventListener('click', (event) => {
    const input = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
    if (!input || input.disabled || input.readOnly || input.hasAttribute('data-native-picker')) return;
    event.preventDefault();
    openPicker(input);
  });
  document.addEventListener('keydown', (event) => {
    const input = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
    if (!input || input.hasAttribute('data-native-picker')) return;
    if (event.key === 'ArrowDown' && event.altKey) { event.preventDefault(); openPicker(input); }
  });
}
