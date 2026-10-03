const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };

const checkAll = document.querySelector('[data-check-all]');
if (checkAll) checkAll.addEventListener('change', () => document.querySelectorAll('[data-row-check]').forEach((box) => { box.checked = checkAll.checked; }));

const aiDraftButton = document.querySelector('[data-ai-product-draft]');
if (aiDraftButton) {
  aiDraftButton.addEventListener('click', async () => {
    const form = aiDraftButton.closest('form');
    const status = form?.querySelector('[data-ai-status]');
    const provider = form?.querySelector('[data-ai-provider]');
    const token = form?.querySelector('[data-ai-token]');
    if (!form || !status || !provider || !token) return;
    const name = form.querySelector('[name="name"]');
    const sku = form.querySelector('[name="sku"]');
    const shortDescription = form.querySelector('[name="short_description"]');
    const description = form.querySelector('[name="description"]');
    aiDraftButton.disabled = true;
    status.textContent = t('admin.ai.generating');
    try {
      const brandSelect = form.querySelector('[name="brand_id"]');
      const brand = brandSelect && brandSelect.value !== '0' ? (brandSelect.selectedOptions[0]?.textContent || '').trim() : '';
      const categories = Array.from(form.querySelectorAll('[name="category_ids[]"]:checked')).map((box) => box.closest('label')?.textContent?.trim() || '').filter(Boolean).join(', ');
      const attributes = Array.from(form.querySelectorAll('[name^="attribute["]')).map((input) => {
        const value = input instanceof HTMLSelectElement ? (input.value === '' ? '' : (input.selectedOptions[0]?.textContent || '').trim()) : String(input.value || '').trim();
        const label = input.closest('label')?.querySelector('span')?.textContent?.trim() || '';
        return value !== '' && label !== '' ? `${label}: ${value}` : '';
      }).filter(Boolean).join('; ');
      const body = new URLSearchParams({_token: token.value, provider: provider.value, name: name?.value || '', sku: sku?.value || '', description: description?.value || '', short: shortDescription?.value || '', brand, categories, attributes});
      const response = await fetch('/admin/api/ai/product-draft', {method:'POST', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'AI request failed');
      if (shortDescription) shortDescription.value = data.short_description || shortDescription.value;
      if (description) description.value = data.description || description.value;
      status.textContent = t('admin.ai.draft_inserted');
    } catch (error) {
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
    const body = new URLSearchParams({ _token: box.dataset.aiToken || '', task: button.dataset.aiTask || '', provider: provider.value });
    for (const [name, selector] of Object.entries(inputs)) body.append(`fields[${name}]`, document.querySelector(selector)?.value || '');
    button.disabled = true;
    status.textContent = t('admin.ai.generating');
    try {
      const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
      for (const [name, selector] of Object.entries(outputs)) {
        const field = document.querySelector(selector);
        if (field && typeof data.fields?.[name] === 'string') { field.value = data.fields[name]; field.dispatchEvent(new Event('input', { bubbles: true })); }
      }
      status.textContent = t('admin.ai.draft_inserted');
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
    } finally {
      button.disabled = false;
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
    if (status) status.textContent = t('admin.ai.generating');
    try {
      const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
      if (output) output.value = Object.values(data.fields || {}).join('\n\n');
      if (status) status.textContent = t('admin.ai.draft_inserted');
    } catch (error) {
      if (status) status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
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
    const source = page?.querySelector('[data-translate-panel][data-source]');
    const provider = page?.querySelector('[data-ai-provider]');
    const status = panel?.querySelector('[data-ai-status]');
    if (!page || !panel || !source || !provider || !status) return;
    const target = panel.dataset.locale || '';
    button.disabled = true;
    let done = 0;
    try {
      for (const field of panel.querySelectorAll('[data-translate-field]')) {
        const name = field.getAttribute('name');
        const text = source.querySelector(`[data-translate-field][name="${name}"]`)?.value?.trim() || '';
        if (!text) continue;
        status.textContent = t('admin.ai.generating') + ' ' + name;
        const body = new URLSearchParams({ _token: page.dataset.aiToken || '', task: 'translate', provider: provider.value });
        body.append('fields[text]', text);
        body.append('fields[target]', target);
        const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
        field.value = data.fields?.text || '';
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

// Translation editor, one-click variants: a single field, or every language at once (then one "save all"). Nothing is saved before a save button is pressed.
async function translateText(page, text, target) {
  const provider = page.querySelector('[data-ai-provider]');
  const body = new URLSearchParams({ _token: page.dataset.aiToken || '', task: 'translate', provider: provider?.value || '' });
  body.append('fields[text]', text);
  body.append('fields[target]', target);
  const response = await fetch('/admin/api/ai/task', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.message || t('admin.ai.draft_failed'));
  return data.fields?.text || '';
}
function translateSource(page, name) {
  return page.querySelector('[data-translate-panel][data-source]')?.querySelector(`[data-translate-field][name="${name}"]`)?.value?.trim() || '';
}
document.querySelectorAll('[data-translate-one]').forEach((button) => {
  button.addEventListener('click', async () => {
    const page = button.closest('[data-translate-page]');
    const panel = button.closest('[data-translate-panel]');
    const field = button.closest('label')?.querySelector('[data-translate-field]');
    const status = panel?.querySelector('[data-ai-status]');
    if (!page || !panel || !field || !status) return;
    const text = translateSource(page, field.getAttribute('name') || '');
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
    button.disabled = true;
    let failed = false;
    try {
      for (const panel of page.querySelectorAll('[data-translate-panel]:not([data-source])')) {
        for (const field of panel.querySelectorAll('[data-translate-field]')) {
          const text = translateSource(page, field.getAttribute('name') || '');
          if (!text) continue;
          status.textContent = `${t('admin.ai.generating')} ${panel.dataset.locale} · ${field.getAttribute('name')}`;
          field.value = await translateText(page, text, panel.dataset.locale || '');
          field.dispatchEvent(new Event('input', { bubbles: true }));
        }
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
        const form = panel.querySelector('form');
        if (!form) continue;
        status.textContent = `${t('admin.ai.saving')} ${panel.dataset.locale}`;
        const response = await fetch(form.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form), redirect: 'follow' });
        if (!response.ok) throw new Error(t('admin.ai.draft_failed'));
      }
      window.location.reload();
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : t('admin.ai.draft_failed');
      saveAll.disabled = false;
    }
  });
  if (new URLSearchParams(window.location.search).get('auto') === '1') button.click();
});
