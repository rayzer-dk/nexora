const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };

// --- AI insertion feedback -------------------------------------------------------------------------------------------
// Every AI button writes into form fields. These helpers write only into the fields of the box's own form, count what was really
// written, show the generated text inside the box and let the person jump to the field (opening its tab, scrolling, flashing).
const AI_PREVIEW_LIMIT = 300;

const aiScope = (box) => box?.closest('form') || document;

// A selector may be broken (a template typo): that is "field not found", never an exception.
function aiFind(scope, selector) {
  try {
    const found = scope.querySelector(selector);
    if (found) return found;
    // ids are unique on a page: a reply box outside its field's form (or on a page without a form) still finds it
    return scope !== document && /^#[\w-]+$/.test(selector) ? document.querySelector(selector) : null;
  } catch {
    return null;
  }
}

// The visible name of a field: the label's own text (without hints), then aria-label, then the field name.
function aiFieldLabel(field, fallback = '') {
  const own = field.closest('label')?.querySelector(':scope > span');
  if (own) {
    const copy = own.cloneNode(true);
    copy.querySelectorAll('small, em, svg').forEach((node) => node.remove());
    const text = (copy.textContent || '').replace(/\s+/g, ' ').trim();
    if (text) return text;
  }
  return (field.getAttribute('aria-label') || '').trim() || field.getAttribute('name') || fallback;
}

// mapping: [{selector, value, label}] -> what was written into the form, which selectors found nothing, which values came back empty.
function insertAiFields(scope, mapping) {
  const written = []; const missing = []; const empty = [];
  for (const item of mapping) {
    const field = aiFind(scope, item.selector);
    const label = field ? aiFieldLabel(field, item.label || '') : (item.label || item.selector);
    const value = typeof item.value === 'string' ? item.value : '';
    if (value.trim() === '') { empty.push(label); continue; }
    if (!field) { missing.push(item.selector); continue; }
    field.value = value;
    // the input event also carries the text into the visual editor and any live counters
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    written.push({ field, label, value, selector: item.selector });
  }
  return { written, missing, empty };
}

// Open every tab / folded block that hides the field, scroll to it and flash it (the visual editor when the field has one).
function revealField(field) {
  if (!(field instanceof Element)) return;
  for (let panel = field.closest('[data-product-tab], [data-tab]'); panel; panel = panel.parentElement?.closest('[data-product-tab], [data-tab]') || null) {
    if (!panel.hidden) continue;
    let button = null;
    if (panel.matches('[data-product-tab]')) {
      button = Array.from(document.querySelectorAll('.admin-tab[data-tab-target]')).find((b) => b.dataset.tabTarget === panel.dataset.productTab && !b.closest('[data-tabs]')) || null;
    } else if (panel.parentElement?.matches('[data-tabs]')) {
      button = Array.from(panel.parentElement.querySelectorAll(':scope > .admin-tabs .admin-tab[data-tab-target]')).find((b) => b.dataset.tabTarget === panel.dataset.tab) || null;
    }
    button?.click();
  }
  for (let details = field.closest('details'); details; details = details.parentElement?.closest('details') || null) details.open = true;
  const editor = field.matches('[data-rich-editor]') && field.nextElementSibling?.classList.contains('rich-editor-mount') ? field.nextElementSibling : null;
  const target = editor || field;
  target.scrollIntoView({ block: 'center', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  target.classList.add('is-ai-flash');
  window.setTimeout(() => target.classList.remove('is-ai-flash'), 2000);
  if (!editor && typeof field.focus === 'function') field.focus({ preventScroll: true });
}

async function copyAiText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.append(area);
    area.select();
    try {
      return document.execCommand('copy');
    } catch {
      return false;
    } finally {
      area.remove();
    }
  }
}

// result: what insertAiFields returned; fields: [{label, value, selector?}] everything the model produced.
// Renders [data-ai-result] inside the box: the text of each field (300 characters, "show all"), where it went, or a copy button.
function showAiResult(box, result, fields) {
  if (!box) return;
  box.querySelector('[data-ai-result]')?.remove();
  const items = fields.filter((item) => typeof item.value === 'string' && item.value.trim() !== '');
  if (items.length === 0) return;
  const root = document.createElement('div');
  root.className = 'admin-ai-result';
  root.dataset.aiResult = '';
  const list = document.createElement('ul');
  for (const item of items) {
    const hit = item.selector ? result.written.find((w) => w.selector === item.selector) : undefined;
    const row = document.createElement('li');
    row.dataset.aiResultItem = item.label;
    const title = document.createElement('strong');
    title.textContent = item.label;
    const text = document.createElement('div');
    text.className = 'admin-ai-result__text';
    text.dataset.aiResultText = '';
    const long = item.value.length > AI_PREVIEW_LIMIT;
    text.textContent = long ? `${item.value.slice(0, AI_PREVIEW_LIMIT)}…` : item.value;
    row.append(title, text);
    if (long) {
      const more = document.createElement('button');
      more.type = 'button';
      more.className = 'admin-ai-result__link';
      more.dataset.aiResultMore = '';
      more.textContent = t('admin.ai.show_full');
      let open = false;
      more.addEventListener('click', () => {
        open = !open;
        text.textContent = open ? item.value : `${item.value.slice(0, AI_PREVIEW_LIMIT)}…`;
        more.textContent = t(open ? 'admin.ai.show_less' : 'admin.ai.show_full');
      });
      row.append(more);
    }
    const foot = document.createElement('div');
    foot.className = 'admin-ai-result__foot';
    if (hit) {
      const where = document.createElement('small');
      where.dataset.aiResultWhere = '';
      where.textContent = t('admin.ai.inserted_in', { field: hit.label });
      const show = document.createElement('button');
      show.type = 'button';
      show.className = 'admin-ai-result__link';
      show.dataset.aiRevealField = '';
      show.textContent = t('admin.ai.show_field');
      show.addEventListener('click', () => revealField(hit.field));
      foot.append(where, show);
    } else {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'admin-ai-result__link';
      copy.dataset.aiCopy = '';
      copy.textContent = t('admin.ai.copy');
      copy.addEventListener('click', async () => {
        const ok = await copyAiText(item.value);
        copy.textContent = t(ok ? 'admin.ai.copied' : 'admin.ai.copy_failed');
        window.setTimeout(() => { copy.textContent = t('admin.ai.copy'); }, 2000);
      });
      foot.append(copy);
    }
    row.append(foot);
    list.append(row);
  }
  root.append(list);
  box.append(root);
}

// The one honest sentence for the status line: success only when something was written, otherwise why not.
function aiStatusText(result) {
  const notes = result.empty.map((label) => t('admin.ai.empty_field', { field: label }));
  if (result.written.length === 0) return [result.missing.length > 0 ? t('admin.ai.insert_failed') : '', ...notes].filter(Boolean).join(' ') || t('admin.ai.insert_failed');
  if (result.missing.length > 0) notes.push(t('admin.ai.insert_failed'));
  return [t('admin.ai.draft_inserted'), ...notes].join(' ');
}

// Settle one AI answer: write the fields, show the text, set the status. Returns true when something was written.
function applyAiFields(box, status, scope, mapping) {
  const result = insertAiFields(scope, mapping);
  showAiResult(box, result, mapping.map((item) => ({ label: aiMappingLabel(scope, item), value: item.value, selector: item.selector })));
  status.textContent = aiStatusText(result);
  status.classList.toggle('is-error', result.written.length === 0);
  return result.written.length > 0;
}

function aiMappingLabel(scope, item) {
  const field = aiFind(scope, item.selector);
  return field ? aiFieldLabel(field, item.label || '') : (item.label || item.selector);
}

const checkAll = document.querySelector('[data-check-all]');
if (checkAll) checkAll.addEventListener('change', () => document.querySelectorAll('[data-row-check]').forEach((box) => { box.checked = checkAll.checked; }));

const aiDraftButton = document.querySelector('[data-ai-product-draft]');
if (aiDraftButton) {
  aiDraftButton.addEventListener('click', async () => {
    const form = aiDraftButton.closest('form');
    const box = aiDraftButton.closest('.admin-ai-helper');
    const status = box?.querySelector('[data-ai-status]');
    const provider = box?.querySelector('[data-ai-provider]');
    const token = box?.querySelector('[data-ai-token]');
    if (!form || !box || !status || !provider || !token) return;
    const name = form.querySelector('[name="name"]');
    const sku = form.querySelector('[name="sku"]');
    const shortDescription = form.querySelector('[name="short_description"]');
    const description = form.querySelector('[name="description"]');
    aiDraftButton.disabled = true;
    status.classList.remove('is-error');
    status.textContent = t('admin.ai.generating');
    try {
      const brandSelect = form.querySelector('[name="brand_id"]');
      const brand = brandSelect && brandSelect.value !== '0' ? (brandSelect.selectedOptions[0]?.textContent || '').trim() : '';
      const categories = Array.from(form.querySelectorAll('[name="category_ids[]"]:checked')).map((checkbox) => checkbox.closest('label')?.textContent?.trim() || '').filter(Boolean).join(', ');
      const attributes = Array.from(form.querySelectorAll('[name^="attribute["]')).map((input) => {
        const value = input instanceof HTMLSelectElement ? (input.value === '' ? '' : (input.selectedOptions[0]?.textContent || '').trim()) : String(input.value || '').trim();
        const label = input.closest('label')?.querySelector('span')?.textContent?.trim() || '';
        return value !== '' && label !== '' ? `${label}: ${value}` : '';
      }).filter(Boolean).join('; ');
      const body = new URLSearchParams({_token: token.value, provider: provider.value, name: name?.value || '', sku: sku?.value || '', description: description?.value || '', short: shortDescription?.value || '', brand, categories, attributes});
      const response = await fetch('/admin/api/ai/product-draft', {method:'POST', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'AI request failed');
      applyAiFields(box, status, form, [
        { selector: '[name="short_description"]', value: data.short_description, label: 'short_description' },
        { selector: '[name="description"]', value: data.description, label: 'description' },
      ]);
    } catch (error) {
      status.classList.add('is-error');
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      aiDraftButton.disabled = false;
    }
  });
}

// Generic AI assistant buttons: read the mapped inputs, ask the server, put the draft into the mapped fields. Nothing is saved automatically.
document.querySelectorAll('[data-ai-task]').forEach((button) => {
  button.addEventListener('click', async () => {
    const box = button.closest('[data-ai-box]');
    const status = box?.querySelector('[data-ai-status]');
    const provider = box?.querySelector('[data-ai-provider]');
    if (!box || !status || !provider) return;
    let inputs; let outputs;
    try { inputs = JSON.parse(button.dataset.aiIn || '{}'); outputs = JSON.parse(button.dataset.aiOut || '{}'); } catch { return; }
    const scope = aiScope(box);
    const body = new URLSearchParams({ _token: box.dataset.aiToken || '', task: button.dataset.aiTask || '', provider: provider.value });
    for (const [name, selector] of Object.entries(inputs)) body.append(`fields[${name}]`, aiFind(scope, selector)?.value || '');
    button.disabled = true;
    status.classList.remove('is-error');
    status.textContent = t('admin.ai.generating');
    try {
      const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
      // Several outputs may go into one field (a category has one text for the intro and the SEO part): joined in order.
      const bySelector = new Map();
      for (const [name, selector] of Object.entries(outputs)) {
        const value = typeof data.fields?.[name] === 'string' ? data.fields[name] : '';
        const entry = bySelector.get(selector) || { selector, parts: [], label: name };
        if (value.trim() !== '') entry.parts.push(value);
        bySelector.set(selector, entry);
      }
      applyAiFields(box, status, scope, Array.from(bySelector.values(), (entry) => ({ selector: entry.selector, value: entry.parts.join('\n'), label: entry.label })));
    } catch (error) {
      status.classList.add('is-error');
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      button.disabled = false;
    }
  });
});

// Product card from a photo: the picture goes to the model, the draft fills name, texts and notes; the person reviews and saves.
document.querySelectorAll('[data-ai-photo]').forEach((box) => {
  const run = box.querySelector('[data-ai-photo-run]');
  const status = box.querySelector('[data-ai-photo-status]');
  run?.addEventListener('click', async () => {
    const scope = aiScope(box);
    const file = box.querySelector('[data-ai-photo-file]')?.files?.[0] || aiFind(scope, '[data-ai-photo-source]')?.files?.[0];
    status.classList.remove('is-error');
    if (!file) { status.textContent = t('admin.ai.photo_choose'); return; }
    const body = new FormData();
    body.append('_token', box.dataset.aiPhotoToken || '');
    body.append('provider', box.querySelector('[data-ai-photo-provider]')?.value || '');
    body.append('hint', box.querySelector('[data-ai-photo-hint]')?.value || '');
    body.append('photo', file);
    run.disabled = true;
    status.textContent = t('admin.ai.generating');
    try {
      const response = await fetch(box.dataset.aiPhotoUrl || '', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
      // Only the fields of this form (a name field elsewhere on the page must never receive the text).
      const mapping = [
        { selector: '[name="name"]', value: data.fields?.name, label: 'name' },
        { selector: '[name="short_description"]', value: data.fields?.short_description, label: 'short_description' },
        { selector: '[name="description"]', value: data.fields?.description, label: 'description' },
      ];
      const result = insertAiFields(scope, mapping);
      const brand = data.fields?.brand ? t('admin.ai.photo_brand') + ': ' + data.fields.brand : '';
      const extra = [brand, data.fields?.attributes || ''].filter(Boolean).join('\n');
      const notes = box.querySelector('[data-ai-photo-notes]');
      if (notes && extra) { notes.textContent = extra; notes.hidden = false; }
      const shown = mapping.map((item) => ({ label: aiMappingLabel(scope, item), value: item.value, selector: item.selector }));
      // brand and attributes have no form field: they are shown (with a copy button) next to the written fields
      if (extra) shown.push({ label: t('admin.ai.photo_notes'), value: extra });
      showAiResult(box, result, shown);
      status.textContent = aiStatusText(result);
      status.classList.toggle('is-error', result.written.length === 0);
    } catch (error) {
      status.classList.add('is-error');
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      run.disabled = false;
    }
  });
});

// Free-form assistant on the AI settings page (reply drafts, translation, SEO text).
const aiPlayground = document.querySelector('[data-ai-playground]');
if (aiPlayground) {
  const run = aiPlayground.querySelector('[data-ai-play-run]');
  run?.addEventListener('click', async () => {
    const task = aiPlayground.querySelector('[data-ai-play-task]')?.value || 'reply';
    const text = aiPlayground.querySelector('[data-ai-play-input]')?.value || '';
    const target = aiPlayground.querySelector('[data-ai-play-target]')?.value || 'en';
    const status = aiPlayground.querySelector('[data-ai-status]');
    const output = aiPlayground.querySelector('[data-ai-play-output]');
    const body = new URLSearchParams({ _token: aiPlayground.dataset.aiToken || '', task, provider: aiPlayground.querySelector('[data-ai-provider]')?.value || '' });
    if (task === 'reply') body.append('fields[message]', text);
    else if (task === 'translate') { body.append('fields[text]', text); body.append('fields[target]', target); }
    else { body.append('fields[title]', text.split('\n')[0].slice(0, 200)); body.append('fields[body]', text); }
    run.disabled = true;
    if (status) { status.classList.remove('is-error'); status.textContent = t('admin.ai.generating'); }
    try {
      const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
      const answer = Object.values(data.fields || {}).filter((value) => typeof value === 'string' && value.trim() !== '').join('\n\n');
      if (output && answer !== '') output.value = answer;
      if (status) {
        const written = Boolean(output) && answer !== '';
        status.classList.toggle('is-error', !written);
        status.textContent = written ? t('admin.ai.draft_inserted') : t(output ? 'admin.ai.empty_answer' : 'admin.ai.insert_failed');
      }
    } catch (error) {
      if (status) { status.classList.add('is-error'); status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed'); }
    } finally {
      run.disabled = false;
    }
  });
}

// Translation editor: fills one language panel from the default-language panel through the AI translate task. Nothing is saved until the form is sent.
document.querySelectorAll('[data-translate-from]').forEach((button) => {
  button.addEventListener('click', async () => {
    const page = button.closest('[data-translate-page]');
    const panel = button.closest('[data-translate-panel]');
    const provider = page?.querySelector('[data-ai-provider]');
    const status = panel?.querySelector('[data-ai-status]');
    if (!page || !panel || !provider || !status) return;
    const target = panel.dataset.locale || '';
    const jobs = translateJobs(page, [panel]);
    if (jobs.length === 0) { status.textContent = t('admin.ai.nothing_to_translate'); return; }
    button.disabled = true;
    let done = 0;
    try {
      for (const { field, text } of jobs) {
        status.textContent = t('admin.ai.generating') + ' ' + field.getAttribute('name');
        field.value = await translateText(page, text, target);
        field.dispatchEvent(new Event('input', { bubbles: true }));
        done += 1;
      }
      status.textContent = done > 0 ? t('admin.ai.draft_inserted') : '';
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      button.disabled = false;
    }
  });
});

// Saves a form with fetch and leaves the page alone: the server answers JSON for XHR, the status line next to the button shows the result.
async function saveFormInPlace(form) {
  const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || !data.ok) throw new Error(data?.message || t('admin.ai.draft_failed'));
  return data;
}
document.querySelectorAll('form[data-ajax-save]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = form.querySelector('[data-ai-status]');
    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    if (status) status.textContent = t('admin.ai.saving');
    try {
      const data = await saveFormInPlace(form);
      if (status) status.textContent = data.message || '';
    } catch (error) {
      if (status) status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      if (submit) submit.disabled = false;
    }
  });
});

// Translation editor, one-click variants: a single field, or every language at once (then one "save all"). Nothing is saved before a save button is pressed.
async function translateText(page, text, target) {
  const provider = page.querySelector('[data-ai-provider]');
  const body = new URLSearchParams({ _token: page.dataset.aiToken || '', task: 'translate', provider: provider?.value || '' });
  body.append('fields[text]', text);
  body.append('fields[target]', target);
  // The language of the source panel, so the model (or a translation service) does not have to guess it.
  const source = page.querySelector('[data-translate-panel][data-source]')?.dataset.locale || '';
  if (source) body.append('fields[source]', source);
  const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
  return data.fields?.text || '';
}
// The text a field is translated from: the field named by data-translate-source, else the same-named field of the default-language panel.
function translateSource(page, field) {
  const selector = field.dataset.translateSource;
  if (selector) return document.querySelector(selector)?.value?.trim() || '';
  return page.querySelector('[data-translate-panel][data-source]')?.querySelector(`[data-translate-field][name="${field.getAttribute('name')}"]`)?.value?.trim() || '';
}
// What a "translate" button will do in the given panels: every field with a source text. When some of them are
// already translated, the person decides whether those are replaced; otherwise only the untranslated fields are
// filled. A field still holding a copy of the source (a fallback shown for a missing language) is untranslated.
function translateJobs(page, panels) {
  const jobs = [];
  for (const panel of panels) {
    for (const field of panel.querySelectorAll('[data-translate-field]')) {
      const text = translateSource(page, field);
      const current = String(field.value || '').trim();
      if (text) jobs.push({ panel, field, text, filled: current !== '' && current !== text });
    }
  }
  if (jobs.some((job) => job.filled) && !window.confirm(t('admin.ai.overwrite_confirm'))) return jobs.filter((job) => !job.filled);
  return jobs;
}
document.querySelectorAll('[data-translate-one]').forEach((button) => {
  button.addEventListener('click', async () => {
    const page = button.closest('[data-translate-page]');
    const panel = button.closest('[data-translate-panel]');
    const field = button.closest('label')?.querySelector('[data-translate-field]');
    const status = panel?.querySelector('[data-ai-status]');
    if (!page || !panel || !field || !status) return;
    const text = translateSource(page, field);
    if (!text) return;
    button.disabled = true;
    status.textContent = t('admin.ai.generating');
    try {
      field.value = await translateText(page, text, panel.dataset.locale || '');
      field.dispatchEvent(new Event('input', { bubbles: true }));
      status.textContent = t('admin.ai.draft_inserted');
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      button.disabled = false;
    }
  });
});
document.querySelectorAll('[data-translate-all]').forEach((button) => {
  const page = button.closest('[data-translate-page]');
  const status = page?.querySelector('[data-bulk-status]');
  const saveAll = page?.querySelector('[data-save-all]');
  if (!page || !status) return;
  button.addEventListener('click', async () => {
    const jobs = translateJobs(page, Array.from(page.querySelectorAll('[data-translate-panel]:not([data-source])')));
    if (jobs.length === 0) { status.textContent = t('admin.ai.nothing_to_translate'); return; }
    button.disabled = true;
    let failed = false;
    try {
      for (const [index, { panel, field, text }] of jobs.entries()) {
        status.textContent = t('admin.ai.translate_progress', { done: index + 1, total: jobs.length, locale: panel.dataset.locale || '', field: field.getAttribute('name') || '' });
        field.value = await translateText(page, text, panel.dataset.locale || '');
        field.dispatchEvent(new Event('input', { bubbles: true }));
      }
    } catch (error) {
      failed = true;
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      button.disabled = false;
    }
    if (!failed) {
      status.textContent = t('admin.ai.translate_all_done');
      if (saveAll) saveAll.hidden = false;
    }
  });
  saveAll?.addEventListener('click', async () => {
    saveAll.disabled = true;
    try {
      for (const panel of page.querySelectorAll('[data-translate-panel]:not([data-source])')) {
        const form = panel.querySelector('form[data-ajax-save]');
        if (!form) continue;
        status.textContent = `${t('admin.ai.saving')} ${panel.dataset.locale}`;
        await saveFormInPlace(form);
      }
      status.textContent = t('admin.ai.saved_all');
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      saveAll.disabled = false;
    }
  });
  if (new URLSearchParams(window.location.search).get('auto') === '1') button.click();
});

// Admin helpers that talk to JSON endpoints and leave the page where it is.
const adminNotify = (message, type = 'success') => {
  if (typeof window.mcAdminToast === 'function') window.mcAdminToast(message, type);
};
async function adminJson(url, body) {
  const response = await fetch(url, { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body || undefined });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.message || t('admin.ai.draft_failed'));
  return data;
}

// Campaigns: saved templates (load / save / delete), a preview of the e-mail, and "use again" / "delete" for the history. All in place.
const campaignTools = document.querySelector('[data-campaign-tools]');
const campaignForm = campaignTools?.closest('form') || null;
if (campaignTools && campaignForm) {
  const token = campaignForm.querySelector('input[name="_csrf_token"]')?.value || '';
  const status = campaignTools.querySelector('[data-campaign-status]');
  const select = campaignTools.querySelector('[data-template-select]');
  const frame = campaignForm.querySelector('[data-campaign-frame]');
  const base = campaignTools.dataset.urlTemplates || '';
  const post = (extra) => { const body = new FormData(); body.append('_csrf_token', token); for (const [k, v] of Object.entries(extra)) body.append(k, v); return body; };
  const say = (message) => { if (status) status.textContent = message; };
  const run = async (action) => { try { await action(); } catch (error) { adminNotify(error instanceof Error ? error.message : t('admin.ai.draft_failed'), 'error'); } };
  campaignTools.querySelector('[data-template-load]')?.addEventListener('click', () => run(async () => {
    if (!select?.value) return;
    const data = await adminJson(`${base}/${select.value}.json`);
    window.mcFillCampaign(data);
    say('');
  }));
  campaignTools.querySelector('[data-template-save]')?.addEventListener('click', () => run(async () => {
    const name = campaignTools.querySelector('[data-template-name]');
    const data = await adminJson(base, post({ template_name: name?.value || '', subject: campaignForm.elements.subject.value, body: campaignForm.elements.body.value, format: campaignForm.elements.format.value }));
    let option = Array.from(select?.options || []).find((o) => o.value === String(data.id));
    if (!option && select) { option = document.createElement('option'); option.value = String(data.id); select.append(option); }
    if (option) { option.textContent = data.name; if (select) select.value = option.value; }
    adminNotify(data.message);
  }));
  const del = campaignTools.querySelector('[data-template-delete]');
  del?.addEventListener('click', () => run(async () => {
    if (!select?.value || !window.confirm(del.dataset.confirmText || '')) return;
    const data = await adminJson(`${base}/${select.value}/delete`, post({}));
    select.querySelector(`option[value="${select.value}"]`)?.remove();
    select.value = '';
    adminNotify(data.message);
  }));
  campaignTools.querySelector('[data-campaign-preview]')?.addEventListener('click', () => run(async () => {
    const response = await fetch(campaignTools.dataset.urlPreview || '', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: post({ subject: campaignForm.elements.subject.value, body: campaignForm.elements.body.value, format: campaignForm.elements.format.value }) });
    if (!response.ok) throw new Error(t('admin.ai.draft_failed'));
    if (frame) { frame.srcdoc = await response.text(); frame.hidden = false; frame.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }));
  window.mcFillCampaign = (data) => {
    campaignForm.elements.subject.value = data.subject || '';
    campaignForm.elements.body.value = data.body || '';
    campaignForm.elements.format.value = data.body_format || 'text';
    campaignForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  document.querySelectorAll('[data-campaign-reuse]').forEach((button) => button.addEventListener('click', () => run(async () => {
    window.mcFillCampaign(await adminJson(button.dataset.show || ''));
  })));
  document.querySelectorAll('[data-campaign-delete]').forEach((button) => button.addEventListener('click', () => run(async () => {
    if (!window.confirm(button.dataset.confirmText || '')) return;
    const data = await adminJson(button.dataset.delete || '', post({}));
    button.closest('tr')?.remove();
    adminNotify(data.message);
  })));
}

// Any row action that deletes through JSON: data-ajax-delete="<url>" data-token="<csrf>" [data-confirm-text].
document.querySelectorAll('[data-ajax-delete]').forEach((button) => {
  button.addEventListener('click', async () => {
    if (!window.confirm(button.dataset.confirmText || '')) return;
    const body = new FormData();
    body.append('_csrf_token', button.dataset.token || '');
    button.disabled = true;
    try {
      const data = await adminJson(button.dataset.ajaxDelete || '', body);
      button.closest('tr')?.remove();
      adminNotify(data.message);
    } catch (error) {
      adminNotify(error instanceof Error ? error.message : t('admin.ai.draft_failed'), 'error');
      button.disabled = false;
    }
  });
});
