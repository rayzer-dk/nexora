const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
const q = (selector, root = document) => root.querySelector(selector);
const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function toast(message, type = 'success', timeout = 4200) {
  if (!message) return;
  let stack = q('[data-storefront-toast-stack]');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'storefront-toast-stack';
    stack.dataset.storefrontToastStack = '';
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);
  }
  const item = document.createElement('div');
  item.className = `storefront-toast ${type === 'error' ? 'is-error' : ''}`;
  const text = document.createElement('span');
  text.textContent = message;
  const close = document.createElement('button');
  close.type = 'button';
  close.setAttribute('aria-label', t('js_close'));
  close.title = t('js_close');
  close.textContent = '×';
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

function initStoreNotices() {
  qa('[data-store-toast-source], .global-notice').forEach((source) => {
    const message = source.textContent.trim();
    if (!message) return;
    const type = source.classList.contains('is-error') ? 'error' : 'success';
    toast(message, type, type === 'error' ? 7000 : 4200);
    source.classList.add('is-toast-mirrored');
  });
}

function initForumCompose() {
  const modal = q('[data-forum-compose]');
  if (!modal) return;
  const openers = qa('[data-forum-compose-open]');
  const closers = qa('[data-forum-compose-close]', modal);
  const firstInput = q('input:not([type="hidden"]),textarea', modal);
  const open = () => {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-storefront-modal');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    window.setTimeout(() => firstInput?.focus(), 30);
  };
  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-storefront-modal');
    window.setTimeout(() => { modal.hidden = true; }, 180);
  };
  openers.forEach((button) => button.addEventListener('click', open));
  closers.forEach((button) => button.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
}

function initLiveSearch() {
  const form = q('[data-live-search]');
  if (!form) return;
  const input = q('input[name="q"]', form);
  const results = q('[data-live-search-results]', form);
  if (!input || !results) return;

  let timer = 0;
  let controller = null;
  let generation = 0;

  const hide = () => {
    results.hidden = true;
    results.innerHTML = '';
    form.classList.remove('has-live-results');
  };

  const render = (items, query) => {
    results.innerHTML = '';
    if (!Array.isArray(items) || !items.length) {
      const empty = document.createElement('a');
      empty.className = 'live-search__all';
      empty.href = `/catalog?q=${encodeURIComponent(query)}`;
      empty.textContent = t('js_show_search_results');
      results.appendChild(empty);
    } else {
      items.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'live-search__item';
        link.href = typeof item.url === 'string' && item.url.startsWith('/') ? item.url : '/catalog';

        const image = document.createElement('img');
        image.loading = 'lazy';
        image.alt = '';
        image.src = typeof item.image === 'string' && item.image.startsWith('/') ? item.image : '/assets/product-placeholder.svg';

        const body = document.createElement('span');
        body.className = 'live-search__body';
        const name = document.createElement('strong');
        name.textContent = String(item.name || t('js_product'));
        const meta = document.createElement('small');
        meta.textContent = String(item.availability || '');
        body.append(name, meta);

        const price = document.createElement('b');
        price.textContent = String(item.price || '');
        link.append(image, body, price);
        results.appendChild(link);
      });
      const all = document.createElement('a');
      all.className = 'live-search__all';
      all.href = `/catalog?q=${encodeURIComponent(query)}`;
      all.textContent = t('js_all_results');
      results.appendChild(all);
    }
    results.hidden = false;
    form.classList.add('has-live-results');
  };

  const search = async () => {
    const query = input.value.trim();
    if (query.length < 2) {
      controller?.abort();
      hide();
      return;
    }
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    form.classList.add('is-searching');
    try {
      const response = await fetch(`/api/storefront/search/suggest?q=${encodeURIComponent(query)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (current === generation && input.value.trim() === query) render(payload.items || [], query);
    } catch (error) {
      if (error?.name !== 'AbortError') hide();
    } finally {
      if (current === generation) form.classList.remove('is-searching');
    }
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(search, 180);
  });
  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2 && results.children.length) results.hidden = false;
  });
  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) hide();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !results.hidden) hide();
  });
}

function initMobileNavigation() {
  const nav = q('.reference-category-nav');
  const row = q('.reference-header__nav-row');
  if (!nav || !row || q('[data-mobile-catalog-toggle]', row)) return;

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'reference-mobile-menu';
  toggle.dataset.mobileCatalogToggle = '';
  toggle.setAttribute('aria-expanded', 'false');
  toggle.innerHTML = '<span aria-hidden="true">☰</span><span data-mobile-menu-label></span>';
  q('[data-mobile-menu-label]', toggle).textContent = t('js_menu');
  row.appendChild(toggle);

  const drawer = document.createElement('div');
  drawer.className = 'mobile-catalog-drawer';
  drawer.hidden = true;
  drawer.innerHTML = '<div class="mobile-catalog-drawer__backdrop" data-mobile-menu-close></div><aside class="mobile-catalog-drawer__panel"><header><strong data-mobile-catalog-title></strong><button type="button" data-mobile-menu-close>×</button></header><nav></nav></aside>';
  const panel = q('.mobile-catalog-drawer__panel', drawer);
  const closeButton = q('button[data-mobile-menu-close]', drawer);
  panel.setAttribute('aria-label', t('js_navigation'));
  q('[data-mobile-catalog-title]', drawer).textContent = t('js_catalog_sections');
  closeButton.setAttribute('aria-label', t('js_close'));
  closeButton.setAttribute('title', t('js_close'));
  const drawerNav = q('nav', drawer);
  qa('a', nav).forEach((link) => drawerNav.appendChild(link.cloneNode(true)));
  const serviceLinks = qa('.reference-topbar nav a');
  if (serviceLinks.length) {
    const divider = document.createElement('span');
    divider.className = 'mobile-catalog-drawer__label';
    divider.textContent = t('js_information');
    drawerNav.appendChild(divider);
    serviceLinks.forEach((link) => drawerNav.appendChild(link.cloneNode(true)));
  }
  document.body.appendChild(drawer);

  const open = () => {
    drawer.hidden = false;
    document.body.classList.add('has-storefront-modal');
    toggle.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => drawer.classList.add('is-open'));
  };
  const close = () => {
    drawer.classList.remove('is-open');
    document.body.classList.remove('has-storefront-modal');
    toggle.setAttribute('aria-expanded', 'false');
    window.setTimeout(() => { drawer.hidden = true; }, 180);
  };
  toggle.addEventListener('click', () => drawer.hidden ? open() : close());
  qa('[data-mobile-menu-close]', drawer).forEach((node) => node.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !drawer.hidden) close();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  try { initStoreNotices(); } catch (_) {}
  try { initForumCompose(); } catch (_) {}
  try { initLiveSearch(); } catch (_) {}
  try { initMobileNavigation(); } catch (_) {}
  try { initProductCardCartActions(); } catch (_) {}
});

function initProductCardCartActions() {
  qa('[data-card-add-to-cart]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (!window.fetch) return;
      event.preventDefault();
      const button = q('button[type="submit"]', form);
      if (button?.disabled) return;
      const original = button?.innerHTML || '';
      if (button) { button.disabled = true; button.classList.add('is-loading'); button.textContent = t('js_add_ellipsis'); }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        let payload = {};
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload.ok) throw new Error(payload.message || t('js_add_failed'));
        qa('[data-cart-count]').forEach((counter) => {
          const count = Number(payload.cart?.count || 0);
          counter.textContent = String(count);
          counter.hidden = count < 1;
        });
        toast(payload.message || t('js_added_cart'));
      } catch (error) {
        toast(error?.message || t('js_add_failed'), 'error', 6500);
      } finally {
        if (button) { button.disabled = false; button.classList.remove('is-loading'); button.innerHTML = original; }
      }
    });
  });
}
