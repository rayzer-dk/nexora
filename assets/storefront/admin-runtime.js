const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
const q = (selector, root = document) => root.querySelector(selector);
const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function toast(message, type = 'info', timeout = 5200) {
  let stack = q('[data-admin-toast-stack]');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'admin-toast-stack';
    stack.dataset.adminToastStack = '';
    document.body.appendChild(stack);
  }
  const item = document.createElement('div');
  item.className = `admin-toast is-${type}`;
  item.setAttribute('role', type === 'error' ? 'alert' : 'status');
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'admin-toast__close';
  close.textContent = '×';
  close.setAttribute('aria-label', t('js_close'));
  close.addEventListener('click', () => item.remove());
  item.append(text, close);
  stack.appendChild(item);
  requestAnimationFrame(() => item.classList.add('is-visible'));
  if (timeout > 0) {
    window.setTimeout(() => {
      item.classList.remove('is-visible');
      window.setTimeout(() => item.remove(), 220);
    }, timeout);
  }
}

function initFlashToasts() {
  qa('[data-toast-source], .admin-notice, .store-notice').forEach((source) => {
    const message = source.textContent.trim();
    if (!message) return;
    const type = source.classList.contains('is-error') ? 'error' : source.classList.contains('is-warning') ? 'warning' : source.classList.contains('is-success') ? 'success' : 'info';
    toast(message, type);
    source.classList.add('is-toast-mirrored');
  });
}

function ensureConfirmDialog() {
  let modal = q('[data-admin-confirm]');
  if (modal) return modal;
  modal = document.createElement('div');
  modal.className = 'admin-confirm';
  modal.dataset.adminConfirm = '';
  modal.hidden = true;
  modal.innerHTML = '<div class="admin-confirm__backdrop" data-confirm-cancel></div><section class="admin-confirm__dialog" role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title"><span class="admin-confirm__icon">!</span><h2 id="admin-confirm-title"></h2><p data-confirm-message></p><div class="admin-confirm__actions"><button type="button" class="admin-ghost-button" data-confirm-cancel></button><button type="button" class="admin-danger-button" data-confirm-accept></button></div></section>';
  q('#admin-confirm-title', modal).textContent = t('js_confirm_title');
  q('[data-confirm-cancel].admin-ghost-button', modal).textContent = t('js_cancel');
  q('[data-confirm-accept]', modal).textContent = t('js_continue');
  document.body.appendChild(modal);
  return modal;
}

function initConfirmations() {
  const modal = ensureConfirmDialog();
  const message = q('[data-confirm-message]', modal);
  const accept = q('[data-confirm-accept]', modal);
  let pending = null;

  const close = () => {
    modal.hidden = true;
    modal.classList.remove('is-open');
    document.body.classList.remove('has-admin-modal');
    pending = null;
  };
  qa('[data-confirm-cancel]', modal).forEach((button) => button.addEventListener('click', close));
  accept.addEventListener('click', () => {
    const target = pending;
    close();
    if (!target) return;
    if (target.tagName === 'A' && target.href) {
      window.location.assign(target.href);
      return;
    }
    const form = target.form || target.closest('form');
    if (form) {
      form.dataset.confirmedSubmit = '1';
      form.requestSubmit(target.matches('button,input[type="submit"]') ? target : undefined);
    }
  });

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;
    const form = trigger.form || trigger.closest('form');
    if (form?.dataset.confirmedSubmit === '1') {
      delete form.dataset.confirmedSubmit;
      return;
    }
    event.preventDefault();
    pending = trigger;
    message.textContent = trigger.dataset.confirm || t('js_confirm_question');
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add('is-open'));
    document.body.classList.add('has-admin-modal');
    accept.focus();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
}

function initDirtyGuard() {
  qa('form[data-dirty-guard]').forEach((form) => {
    let dirty = false;
    const mark = () => { dirty = true; };
    form.addEventListener('input', mark, { passive: true });
    form.addEventListener('change', mark, { passive: true });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
  });
}

function initImagePreviews() {
  qa('input[type="file"][data-image-upload], input[type="file"][name="product_images[]"]').forEach((input) => {
    input.addEventListener('change', () => {
      const targetSelector = input.dataset.imagePreview;
      const target = targetSelector ? q(targetSelector) : q('[data-image-preview]', input.closest('form'));
      if (!target) return;
      target.innerHTML = '';
      Array.from(input.files || []).slice(0, 12).forEach((file) => {
        if (!file.type.startsWith('image/')) return;
        const card = document.createElement('div');
        card.className = 'admin-upload-preview__item';
        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        const label = document.createElement('small');
        label.textContent = file.name;
        card.append(image, label);
        target.appendChild(card);
      });
    });
  });
}

function initSidebar() {
  const toggle = q('[data-sidebar-toggle]');
  const sidebar = q('[data-admin-sidebar]');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', () => {
    const open = sidebar.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', (event) => {
    if (window.innerWidth > 900 || !sidebar.classList.contains('is-open')) return;
    if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
    sidebar.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
  });
}

function initCommandPalette() {
  const palette = q('[data-command-palette]');
  const input = q('[data-command-input]', palette || document);
  const results = q('[data-command-results]', palette || document);
  if (!palette || !input || !results) return;
  const links = qa('[data-command-source] a').map((link) => ({ label: link.textContent.trim(), href: link.href })).filter((item) => item.label);
  const render = (query = '') => {
    const needle = query.trim().toLocaleLowerCase('uk-UA');
    const matches = links.filter((item) => !needle || item.label.toLocaleLowerCase('uk-UA').includes(needle)).slice(0, 12);
    results.innerHTML = '';
    matches.forEach((item, index) => {
      const link = document.createElement('a');
      link.href = item.href;
      const label = document.createElement('span');
      label.textContent = item.label;
      const key = document.createElement('kbd');
      key.textContent = String(index + 1);
      link.append(label, key);
      results.appendChild(link);
    });
    if (!matches.length) { const empty = document.createElement('p'); empty.textContent = t('js_nothing_found'); results.replaceChildren(empty); }
  };
  const open = () => {
    render('');
    palette.hidden = false;
    palette.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-admin-modal');
    requestAnimationFrame(() => palette.classList.add('is-open'));
    window.setTimeout(() => input.focus(), 30);
  };
  const close = () => {
    palette.classList.remove('is-open');
    palette.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-admin-modal');
    window.setTimeout(() => { palette.hidden = true; input.value = ''; }, 180);
  };
  input.addEventListener('input', () => render(input.value));
  qa('[data-command-close]', palette).forEach((node) => node.addEventListener('click', close));
  qa('[data-command-open]').forEach((node) => node.addEventListener('click', open));
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (palette.hidden) open(); else close();
    } else if (event.key === 'Escape' && !palette.hidden) close();
  });
}

function initAutoSubmit() {
  qa('[data-autosubmit]').forEach((control) => {
    control.addEventListener('change', () => {
      const form = control.closest('form');
      if (form) form.requestSubmit();
    });
  });
}

function initCopyControls() {
  qa('[data-copy-value], [data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const targetSelector = button.dataset.copyTarget;
      const target = targetSelector ? q(targetSelector) : null;
      const value = button.dataset.copyValue ?? target?.textContent?.trim() ?? '';
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value);
        toast(t('js_copied'), 'success', 1800);
      } catch (_) {
        const fallback = document.createElement('textarea');
        fallback.value = value;
        fallback.setAttribute('readonly', '');
        fallback.style.position = 'fixed';
        fallback.style.opacity = '0';
        document.body.appendChild(fallback);
        fallback.select();
        const copied = document.execCommand('copy');
        fallback.remove();
        if (copied) toast(t('js_copied'), 'success', 1800);
        else toast(t('js_copy_failed'), 'error', 4000);
      }
    });
  });
}

function initHealthCheck() {
  qa('[data-health-check]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.dataset.loading === '1') return;
      const original = button.textContent;
      button.dataset.loading = '1';
      button.disabled = true;
      button.textContent = t('js_checking');
      try {
        const response = await fetch('/admin/api/system/health', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (data.healthy) toast(t('js_health_ok'), 'success');
        else toast(t('js_health_issues', { count: data.required_failed || 0 }), 'warning', 7000);
      } catch (_) {
        toast(t('js_health_failed'), 'error', 7000);
      } finally {
        button.disabled = false;
        button.dataset.loading = '0';
        button.textContent = original;
      }
    });
  });
}

function initSiteProfilePreset() {
  const form = q('[data-site-mode-profiles]');
  if (!form) return;
  let profiles = {};
  try {
    profiles = JSON.parse(form.dataset.siteModeProfiles || '{}');
  } catch (_) {
    return;
  }
  qa('input[name="mode"]', form).forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      const features = profiles?.[radio.value]?.features || {};
      Object.entries(features).forEach(([feature, enabled]) => {
        const box = q(`input[name="feature_${feature}"]`, form);
        if (box) box.checked = Boolean(enabled);
      });
    });
  });
}

function initSecretToggles() {
  qa('[data-secret-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const selector = button.dataset.secretToggle;
      const input = selector ? q(selector) : q('input[type="password"], input[data-secret-input]', button.closest('[data-secret-field]'));
      if (!input) return;
      const visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      button.setAttribute('aria-pressed', String(!visible));
      button.setAttribute('title', visible ? t('js_show_value') : t('js_hide_value'));
    });
  });
}


function initQuickPreview() {
  const dialog = q('[data-quick-preview-dialog]');
  const body = q('[data-quick-preview-body]', dialog || document);
  if (!dialog || !body) return;
  q('[data-quick-preview-close]', dialog)?.addEventListener('click', () => dialog.close());
  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-quick-preview]');
    if (!trigger) return;
    event.preventDefault();
    body.replaceChildren(Object.assign(document.createElement('p'), { textContent: t('js_loading') }));
    if (!dialog.open) dialog.showModal();
    try {
      const response = await fetch(trigger.dataset.quickPreview, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const nodes = Array.from(parsed.body.childNodes).map((node) => document.importNode(node, true));
      body.replaceChildren(...nodes);
    } catch (_) {
      body.replaceChildren(Object.assign(document.createElement('p'), { textContent: t('js_preview_failed') }));
    }
  });
}

function initFileLabels() {
  qa('.admin-dropzone input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
      const label = input.closest('.admin-dropzone');
      const strong = q('strong', label);
      if (strong && input.files?.[0]) strong.textContent = input.files[0].name;
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initFlashToasts();
  initConfirmations();
  initDirtyGuard();
  initImagePreviews();
  initSidebar();
  initCommandPalette();
  initAutoSubmit();
  initCopyControls();
  initHealthCheck();
  initSiteProfilePreset();
  initSecretToggles();
  initFileLabels();
  initQuickPreview();
});

async function initPageFeatures() {
  const feature = document.querySelector('[data-builder]') ? 'builder' : document.querySelector('[data-appearance-media]') ? 'appearance-media' : document.querySelector('[data-media-drop]') ? 'media-library' : null;
  if (!feature) return;
  const loaders = {
    builder: () => import('../admin/features/builder.js'),
    'appearance-media': () => import('../admin/features/appearance-media.js'),
    'media-library': () => import('../admin/features/media-library.js'),
  };
  try {
    await loaders[feature]?.();
  } catch (error) {
    console.error(`Admin feature ${feature} failed to load`, error);
    toast(t('js_extra_ui_failed'), 'error', 7000);
  }
}

document.addEventListener('DOMContentLoaded', initPageFeatures, { once: true });
