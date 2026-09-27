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
      const body = new URLSearchParams({_token: token.value, provider: provider.value, name: name?.value || '', sku: sku?.value || '', description: description?.value || ''});
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
