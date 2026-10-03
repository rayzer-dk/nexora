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
    const provider = page?.querySelector('[data-ai-provider]');
    const status = panel?.querySelector('[data-ai-status]');
    if (!page || !panel || !provider || !status) return;
    const target = panel.dataset.locale || '';
    button.disabled = true;
    let done = 0;
    try {
      for (const field of panel.querySelectorAll('[data-translate-field]')) {
        const name = field.getAttribute('name');
        const text = translateSource(page, field);
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
    button.disabled = true;
    let failed = false;
    try {
      for (const panel of page.querySelectorAll('[data-translate-panel]:not([data-source])')) {
        for (const field of panel.querySelectorAll('[data-translate-field]')) {
          const text = translateSource(page, field);
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
