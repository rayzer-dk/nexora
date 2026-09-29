import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
}

async function submitAndWait(page: Page, formSelector: string, urlPart: string): Promise<void> {
  const form = page.locator(formSelector);
  const responsePromise = page.waitForResponse((response) =>
    response.url().includes(urlPart) && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').last().click();
  const response = await responsePromise;
  expect(response.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await expect(page.locator('.store-notice.is-error,.admin-notice.is-error')).toHaveCount(0);
}

test('site capability changes alter the real storefront and can be restored', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating storefront contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-editor-form[data-dirty-guard]');
  const forum = form.locator('input[name="feature_forum"]');
  await expect(forum).toBeVisible();
  const original = await forum.isChecked();

  try {
    if (original) await forum.uncheck(); else await forum.check();
    await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const forumLinks = page.locator('a[href="/forum"]');
    if (original) {
      await expect(forumLinks).toHaveCount(0);
      const disabledForum = await page.goto('/forum', { waitUntil: 'domcontentloaded' });
      expect(disabledForum?.status()).toBe(404);
    } else {
      await expect(forumLinks.first()).toBeVisible();
      const enabledForum = await page.goto('/forum', { waitUntil: 'domcontentloaded' });
      expect(enabledForum?.status()).toBeLessThan(400);
    }
  } finally {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    const restoreForm = page.locator('form.admin-editor-form[data-dirty-guard]');
    const restoreForum = restoreForm.locator('input[name="feature_forum"]');
    if (original) await restoreForum.check(); else await restoreForum.uncheck();
    await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');
  }

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  if (original) await expect(page.locator('a[href="/forum"]').first()).toBeVisible();
  else await expect(page.locator('a[href="/forum"]')).toHaveCount(0);
});

test('appearance settings change computed storefront design tokens and brand subtitle', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating storefront contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-storefront-form');
  const subtitle = form.locator('input[name="brand_subtitle"]');
  const primary = form.locator('input[name="theme_primary"]');
  const radius = form.locator('input[name="theme_radius"]');
  const heroTitle = form.locator('input[name="hero_title"]');
  const showProducts = form.locator('input[name="show_products"]');
  const showPromos = form.locator('input[name="show_promos"]');
  const originalSubtitle = await subtitle.inputValue();
  const originalPrimary = await primary.inputValue();
  const originalRadius = await radius.inputValue();
  const originalHeroTitle = await heroTitle.inputValue();
  const originalShowProducts = await showProducts.isChecked();
  const originalShowPromos = await showPromos.isChecked();

  const marker = `E2E storefront contract ${Date.now()}`;
  const qaPrimary = originalPrimary.toUpperCase() === '#123456' ? '#654321' : '#123456';
  const qaRadius = originalRadius === '23' ? '22' : '23';

  try {
    await subtitle.fill(marker);
    await heroTitle.fill(marker);
    await showProducts.uncheck();
    await showPromos.uncheck();
    await primary.fill(qaPrimary);
    await radius.evaluate((element, value) => {
      const input = element as HTMLInputElement;
      input.value = String(value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }, qaRadius);
    await submitAndWait(page, 'form.admin-storefront-form', '/admin/appearance/storefront');

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.reference-brand small')).toHaveText(marker);
    await expect(page.locator('.demo-hero-card h1,.reference-hero h1,.home-hero h1').first()).toHaveText(marker);
    await expect(page.locator('.product-grid.demo-product-grid')).toHaveCount(0);
    await expect(page.locator('.demo-promo-grid,.reference-products-layout')).toHaveCount(0);
    const tokens = await page.evaluate(() => {
      const style = getComputedStyle(document.documentElement);
      return {
        primary: style.getPropertyValue('--mc-color-primary').trim().toUpperCase(),
        radius: style.getPropertyValue('--mc-radius-lg').trim(),
      };
    });
    expect(tokens.primary).toBe(qaPrimary.toUpperCase());
    expect(tokens.radius).toBe(`${qaRadius}px`);
  } finally {
    await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
    const restore = page.locator('form.admin-storefront-form');
    await restore.locator('input[name="brand_subtitle"]').fill(originalSubtitle);
    await restore.locator('input[name="hero_title"]').fill(originalHeroTitle);
    const restoreProducts = restore.locator('input[name="show_products"]');
    const restorePromos = restore.locator('input[name="show_promos"]');
    if (originalShowProducts) await restoreProducts.check(); else await restoreProducts.uncheck();
    if (originalShowPromos) await restorePromos.check(); else await restorePromos.uncheck();
    await restore.locator('input[name="theme_primary"]').fill(originalPrimary);
    await restore.locator('input[name="theme_radius"]').evaluate((element, value) => {
      const input = element as HTMLInputElement;
      input.value = String(value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }, originalRadius);
    await submitAndWait(page, 'form.admin-storefront-form', '/admin/appearance/storefront');
  }
});

test('rich product description editor saves through the standard form and renders on storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating storefront contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editLink = page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first();
  await expect(editLink).toBeVisible();
  const editUrl = await editLink.getAttribute('href');
  expect(editUrl).toBeTruthy();

  await page.goto(editUrl!, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.rich-editor')).toBeVisible();
  const description = page.locator('textarea[name="description"]');
  await expect(description).toBeHidden();
  const originalDescription = await description.inputValue();
  const slug = await page.locator('input[name="slug"]').inputValue();
  expect(slug).not.toBe('');

  const marker = `E2E_DESCRIPTION_${Date.now()}`;
  const editable = page.locator('.rich-editor__content').first();
  await editable.click();
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
  await page.keyboard.type(marker);
  await expect(description).toHaveValue(new RegExp(marker));
  await submitAndWait(page, 'form.admin-editor-form', editUrl!);

  await page.goto('/' + slug.replace(/^\/+/, ''), { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.detail-card .rich-text')).toContainText(marker);

  await page.goto(editUrl!, { waitUntil: 'domcontentloaded' });
  await page.locator('textarea[name="description"]').evaluate((element, value) => {
    const textarea = element as HTMLTextAreaElement;
    textarea.value = String(value);
  }, originalDescription);
  await submitAndWait(page, 'form.admin-editor-form', editUrl!);
});


test('custom header navigation created in admin appears on storefront and can be removed', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating storefront contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const marker = `E2E Nav ${Date.now()}`;
  await page.goto('/admin/appearance/navigation?menu=header', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action="/admin/appearance/navigation/save"]');
  await expect(form).toBeVisible();
  await form.locator('select[name="item_type"]').selectOption('custom');
  await form.locator('input[name="url"]').fill('/catalog');
  const label = form.locator('input[name^="label["]').first();
  await label.fill(marker);
  const responsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/navigation/save') && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').click();
  expect((await responsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error,.store-notice.is-error')).toHaveCount(0);

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.reference-category-nav').getByText(marker, { exact: true })).toBeVisible();

  await page.goto('/admin/appearance/navigation?menu=header', { waitUntil: 'domcontentloaded' });
  const row = page.locator('table.admin-table tbody tr').filter({ hasText: marker });
  await expect(row).toBeVisible();
  const deleteForm = row.locator('form[action*="/delete"]');
  const deleteResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/navigation/') && response.url().endsWith('/delete') && response.request().method() === 'POST'
  );
  await deleteForm.locator('button').click();
  expect((await deleteResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.reference-category-nav').getByText(marker, { exact: true })).toHaveCount(0);
});

test('admin search synonym changes real catalog search and deletion removes the configured group', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating storefront contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').first();
  await expect(card).toBeVisible();
  const productName = (await card.locator('h2 a').innerText()).trim();
  const skuText = await card.locator('.catalog-card__meta span').first().innerText();
  const sku = skuText.replace(/^SKU\s*/i, '').trim();
  expect(sku).not.toBe('');

  await loginAdmin(page);
  await page.goto('/admin/catalog/search', { waitUntil: 'domcontentloaded' });
  const fakeTerm = `e2esynonym${Date.now()}`;
  const label = `E2E Synonym ${Date.now()}`;
  const form = page.locator('form[action="/admin/catalog/search/synonyms/create"]');
  await form.locator('input[name="label"]').fill(label);
  await form.locator('textarea[name="terms"]').fill(`${fakeTerm}\n${sku}`);
  const createResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/catalog/search/synonyms/create') && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').click();
  expect((await createResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);

  await page.goto('/catalog?q=' + encodeURIComponent(fakeTerm), { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-product-card]').filter({ hasText: productName }).first()).toBeVisible();

  await page.goto('/admin/catalog/search', { waitUntil: 'domcontentloaded' });
  const group = page.locator('.admin-editor-card').filter({ hasText: label });
  await expect(group).toBeVisible();
  const deleteForm = group.locator('form[action*="/synonyms/"][action$="/delete"]');
  const deleteResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/catalog/search/synonyms/') && response.url().endsWith('/delete') && response.request().method() === 'POST'
  );
  await deleteForm.locator('button').click();
  expect((await deleteResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.getByText(label, { exact: true })).toHaveCount(0);
});


test('published Home Builder layout changes SSR storefront and can be restored', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-builder-list] .mc-builder-block').first()).toBeVisible();

  const layoutField = page.locator('textarea[data-layout-json]');
  const originalLayout = await layoutField.inputValue();
  const heroRow = page.locator('[data-builder-list] [data-block-id]').filter({ hasText: 'hero' }).first();
  await expect(heroRow).toBeVisible();
  await heroRow.locator('[data-select]').click();
  const titleField = page.locator('[data-inspector] input[data-prop="props.title"]');
  await expect(titleField).toBeVisible();
  const marker = `E2E Home Builder ${Date.now()}`;

  try {
    await titleField.fill(marker);
    expect(await layoutField.inputValue()).toContain(marker);

    const publishResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/appearance/builder/home') && response.request().method() === 'POST'
    );
    await page.locator('button[form="builder-form"][name="builder_action"][value="publish"]').click();
    expect((await publishResponsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.admin-flash--error,.store-notice.is-error')).toHaveCount(0);
    expect(await page.locator('textarea[data-layout-json]').inputValue()).toContain(marker);

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.getByRole('heading', { name: marker })).toBeVisible();
  } finally {
    await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
    const restoreField = page.locator('textarea[data-layout-json]');
    await restoreField.evaluate((element, value) => {
      const textarea = element as HTMLTextAreaElement;
      textarea.value = String(value);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }, originalLayout);
    const restoreResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/appearance/builder/home') && response.request().method() === 'POST'
    );
    await page.locator('button[form="builder-form"][name="builder_action"][value="publish"]').click();
    expect((await restoreResponsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');
  }
});


test('disabling catalog removes catalog runtime, demo catalog blocks and search structured data', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating site-capability contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-editor-form[data-dirty-guard]');
  const originalMode = await form.locator('input[name="mode"]:checked').inputValue();
  const originalFeatures = await form.locator('input[type="checkbox"][name^="feature_"]').evaluateAll((nodes) =>
    Object.fromEntries(nodes.map((node) => {
      const input = node as HTMLInputElement;
      return [input.name, input.checked];
    }))
  );

  const catalog = form.locator('input[name="feature_catalog"]');
  await expect(catalog).toBeChecked();
  await catalog.uncheck();
  await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');

  const catalogResponse = await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  expect(catalogResponse?.status()).toBe(404);

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('a[href="/catalog"]')).toHaveCount(0);
  await expect(page.locator('[data-product-card]')).toHaveCount(0);
  const jsonLd = await page.locator('script[type="application/ld+json"]').allTextContents();
  expect(jsonLd.join('\n')).not.toContain('SearchAction');

  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const restore = page.locator('form.admin-editor-form[data-dirty-guard]');
  await restore.locator(`input[name="mode"][value="${originalMode}"]`).check();
  for (const [name, checked] of Object.entries(originalFeatures)) {
    const input = restore.locator(`input[name="${name}"]`);
    if (checked) await input.check(); else await input.uncheck();
  }
  await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');
});


test('promotion created in admin changes checkout totals and disabling it stops the discount', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating promotion contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = Date.now();
  const code = `E2E${String(suffix).slice(-10)}`;
  const name = `E2E Promotion ${suffix}`;

  await loginAdmin(page);
  await page.goto('/admin/commerce/promotions', { waitUntil: 'domcontentloaded' });
  const promotionForm = page.locator('form.admin-form-grid').first();
  await expect(promotionForm).toBeVisible();
  await promotionForm.locator('input[name="name"]').fill(name);
  await promotionForm.locator('select[name="trigger_type"]').selectOption('coupon');
  await promotionForm.locator('input[name="code"]').fill(code);
  await promotionForm.locator('select[name="discount_type"]').selectOption('percent');
  await promotionForm.locator('input[name="discount_value"]').fill('10');
  const createResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/commerce/promotions') && response.request().method() === 'POST'
  );
  await promotionForm.locator('button[type="submit"]').click();
  expect((await createResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  const promotionRow = page.locator('table.admin-data-table tbody tr').filter({ hasText: name });
  await expect(promotionRow).toBeVisible();

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  await expect(card).toBeVisible();
  const addResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await addResponsePromise).status()).toBeLessThan(500);

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-checkout-form]')).toBeVisible();
  const coupon = page.locator('[data-coupon-code]');
  await expect(coupon).toBeVisible();
  await coupon.fill(code);
  const previewResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/checkout/promotion/preview') && response.request().method() === 'POST'
  );
  await page.locator('[data-coupon-apply]').click();
  const previewResponse = await previewResponsePromise;
  expect(previewResponse.status()).toBeLessThan(400);
  const preview = await previewResponse.json();
  expect(preview.ok).toBe(true);
  expect(Number(preview.discount_minor)).toBeGreaterThan(0);
  expect(Number(preview.total_minor)).toBeLessThan(Number(preview.subtotal_minor));
  await expect(page.locator('[data-coupon-message]')).toHaveAttribute('data-state', 'success');

  await page.goto('/admin/commerce/promotions', { waitUntil: 'domcontentloaded' });
  const activeRow = page.locator('table.admin-data-table tbody tr').filter({ hasText: name });
  const toggle = activeRow.locator('form[action*="/toggle"]');
  const toggleResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/commerce/promotions/') && response.url().endsWith('/toggle') && response.request().method() === 'POST'
  );
  await toggle.locator('button[type="submit"]').click();
  expect((await toggleResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('table.admin-data-table tbody tr').filter({ hasText: name })).toContainText('disabled');

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-coupon-code]').fill(code);
  const disabledPreviewPromise = page.waitForResponse((response) =>
    response.url().endsWith('/checkout/promotion/preview') && response.request().method() === 'POST'
  );
  await page.locator('[data-coupon-apply]').click();
  const disabledPreview = await (await disabledPreviewPromise).json();
  expect(disabledPreview.ok).toBe(false);
  expect(Number(disabledPreview.discount_minor || 0)).toBe(0);
});


test('storefront order is fully operable from admin lifecycle actions', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating order lifecycle contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  await expect(card).toBeVisible();
  const productName = (await card.locator('h2 a').innerText()).trim();
  const addResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await addResponsePromise).status()).toBeLessThan(500);

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const checkout = page.locator('[data-checkout-form]');
  await expect(checkout).toBeVisible();
  await checkout.locator('[name="name"]').fill('E2E Admin Lifecycle');
  const checkoutEmail = checkout.locator('[name="email"]');
  if (await checkoutEmail.count()) await checkoutEmail.fill(`order-lifecycle-${Date.now()}@example.test`);
  await checkout.locator('[name="phone"]').fill('+380501112233');

  const city = checkout.locator('[data-delivery-city]');
  if (await city.count()) {
    await city.fill('Київ');
    const manual = checkout.locator('[name="delivery_manual"]');
    if (await manual.count()) {
      await manual.evaluate((element) => {
        const input = element as HTMLInputElement;
        input.value = 'Київ, E2E відділення';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }

  const bank = checkout.locator('input[name="payment_method"][value="bank_transfer"]');
  const cod = checkout.locator('input[name="payment_method"][value="cash_on_delivery"]');
  if (await bank.count()) await bank.check();
  else await cod.check();

  await Promise.all([
    page.waitForURL(/\/checkout\/success\/([0-9a-f-]{36})/i, { timeout: 20_000 }),
    checkout.locator('button.place-order').click(),
  ]);
  await expectNoServerError(page);
  const match = page.url().match(/\/checkout\/success\/([0-9a-f-]{36})/i);
  expect(match).toBeTruthy();
  const orderId = match![1];

  await loginAdmin(page);
  await page.goto(`/admin/orders/${orderId}`, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText(productName);

  const noteMarker = `E2E internal note ${Date.now()}`;
  const noteForm = page.locator(`form[action="/admin/orders/${orderId}/note"]`);
  await expect(noteForm).toBeVisible();
  await noteForm.locator('textarea[name="note"]').fill(noteMarker);
  const noteResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/orders/${orderId}/note`) && response.request().method() === 'POST'
  );
  await noteForm.locator('button[type="submit"]').click();
  expect((await noteResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('body')).toContainText(noteMarker);

  const paidForm = page.locator(`form[action="/admin/orders/${orderId}/mark-paid"]`);
  await expect(paidForm).toBeVisible();
  const paidResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/orders/${orderId}/mark-paid`) && response.request().method() === 'POST'
  );
  await paidForm.locator('button[type="submit"]').click();
  expect((await paidResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-runtime__metrics')).toContainText('paid');

  const fulfillmentForm = page.locator(`form[action="/admin/orders/${orderId}/fulfillment"]`);
  await expect(fulfillmentForm).toBeVisible();
  await fulfillmentForm.locator('select[name="status"]').selectOption('delivered');
  const fulfillmentResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/orders/${orderId}/fulfillment`) && response.request().method() === 'POST'
  );
  await fulfillmentForm.locator('button[type="submit"]').click();
  expect((await fulfillmentResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-runtime__metrics')).toContainText('delivered');

  const completeForm = page.locator(`form[action="/admin/orders/${orderId}/complete"]`);
  await expect(completeForm).toBeVisible();
  const completeResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/orders/${orderId}/complete`) && response.request().method() === 'POST'
  );
  await completeForm.locator('button[type="submit"]').click();
  expect((await completeResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-runtime__metrics')).toContainText('completed');
});


test('system information page content and SEO fields render exactly on storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating content contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/content/pages/about', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const contentForm = page.locator('form.admin-runtime__panel.admin-form');
  const token = await contentForm.locator('input[name="_token"]').inputValue();
  const original = {
    title: await page.locator('input[name="title"]').inputValue(),
    excerpt: await page.locator('textarea[name="excerpt"]').inputValue(),
    body_html: await page.locator('textarea[name="body_html"]').inputValue(),
    meta_title: await page.locator('input[name="meta_title"]').inputValue(),
    meta_description: await page.locator('textarea[name="meta_description"]').inputValue(),
    status: await page.locator('select[name="status"]').inputValue(),
  };
  const marker = `E2E information page ${Date.now()}`;
  const metaMarker = `E2E meta ${Date.now()}`;

  const save = await page.request.post('/admin/content/pages/about', {
    maxRedirects: 0,
    form: {
      _token: token,
      title: marker,
      excerpt: marker,
      body_html: `<p><strong>${marker}</strong></p>`,
      meta_title: metaMarker,
      meta_description: `${metaMarker} description`,
      status: 'published',
    },
  });
  expect(save.status()).toBeGreaterThanOrEqual(300);
  expect(save.status()).toBeLessThan(400);

  const publicResponse = await page.goto('/about-us', { waitUntil: 'domcontentloaded' });
  expect(publicResponse?.status()).toBe(200);
  await expectNoServerError(page);
  await expect(page.locator('.content-page h1')).toHaveText(marker);
  await expect(page.locator('.content-page .rich-content')).toContainText(marker);
  await expect(page).toHaveTitle(metaMarker);
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', `${metaMarker} description`);

  await page.goto('/admin/content/pages/about', { waitUntil: 'domcontentloaded' });
  const restoreToken = await page.locator('input[name="_token"]').inputValue();
  const restore = await page.request.post('/admin/content/pages/about', {
    maxRedirects: 0,
    form: { _token: restoreToken, ...original },
  });
  expect(restore.status()).toBeGreaterThanOrEqual(300);
  expect(restore.status()).toBeLessThan(400);
});


test('generated JSON feed matches published storefront catalog data', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating feed contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').first();
  await expect(card).toBeVisible();
  const skuText = await card.locator('.catalog-card__meta span').first().innerText();
  const sku = skuText.replace(/^SKU\s*/i, '').trim();
  expect(sku).not.toBe('');

  await loginAdmin(page);
  await page.goto('/admin/commerce/feeds', { waitUntil: 'domcontentloaded' });
  const generateForm = page.locator('form[action="/admin/commerce/feeds/json/generate"]');
  await expect(generateForm).toBeVisible();
  const generateResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/admin/commerce/feeds/json/generate') && response.request().method() === 'POST'
  );
  await generateForm.locator('button[type="submit"]').click();
  expect((await generateResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);

  const urlField = page.locator('input[readonly][value*="/feeds/"][value*="/json?locale="]');
  await expect(urlField).toBeVisible();
  const feedUrl = await urlField.inputValue();
  const feedResponse = await page.request.get(feedUrl);
  expect(feedResponse.status()).toBe(200);
  expect(feedResponse.headers()['content-type']).toContain('application/json');
  const payload = await feedResponse.json();
  expect(Array.isArray(payload.products)).toBe(true);
  expect(payload.products.length).toBeGreaterThan(0);
  expect(Number(feedResponse.headers()['x-feed-items'] || -1)).toBe(payload.products.length);
  expect(JSON.stringify(payload.products)).toContain(sku);
});


test('locale visibility saved in admin changes the storefront language selector', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating localization contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action="/admin/system/localization/locales"]');
  const candidate = form.locator('input[type="checkbox"][name^="locale["][name$="[enabled]"]:not(:disabled)').first();
  await expect(candidate).toBeVisible();
  const name = await candidate.getAttribute('name');
  expect(name).toBeTruthy();
  const localeCode = name!.match(/^locale\[([^\]]+)\]\[enabled\]$/)?.[1];
  expect(localeCode).toBeTruthy();
  const original = await candidate.isChecked();

  if (original) await candidate.uncheck(); else await candidate.check();
  const saveResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/admin/system/localization/locales') && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').click();
  expect((await saveResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const option = page.locator(`select[name="lang"] option[value="${localeCode}"]`);
  if (original) await expect(option).toHaveCount(0);
  else await expect(option).toHaveCount(1);

  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const restoreForm = page.locator('form[action="/admin/system/localization/locales"]');
  const restore = restoreForm.locator(`input[name="locale[${localeCode}][enabled]"]`);
  if (original) await restore.check(); else await restore.uncheck();
  const restoreResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/admin/system/localization/locales') && response.request().method() === 'POST'
  );
  await restoreForm.locator('button[type="submit"]').click();
  expect((await restoreResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
});


test('disabled commerce and content capabilities remove both routes and misleading storefront UI', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating capability contract test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const productUrl = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  expect(productUrl).toBeTruthy();

  await loginAdmin(page);
  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-editor-form[data-dirty-guard]');
  const originalMode = await form.locator('input[name="mode"]:checked').inputValue();
  const originalFeatures = await form.locator('input[type="checkbox"][name^="feature_"]').evaluateAll((nodes) =>
    Object.fromEntries(nodes.map((node) => {
      const input = node as HTMLInputElement;
      return [input.name, input.checked];
    }))
  );

  const restore = async () => {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    const restoreForm = page.locator('form.admin-editor-form[data-dirty-guard]');
    await restoreForm.locator(`input[name="mode"][value="${originalMode}"]`).check();
    for (const [name, checked] of Object.entries(originalFeatures)) {
      const input = restoreForm.locator(`input[name="${name}"]`);
      if (checked) await input.check(); else await input.uncheck();
    }
    await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');
  };

  try {
    await form.locator('input[name="feature_catalog"]').check();
    await form.locator('input[name="feature_cart"]').uncheck();
    await form.locator('input[name="feature_checkout"]').uncheck();
    await form.locator('input[name="feature_content"]').uncheck();
    await form.locator('input[name="feature_reviews"]').uncheck();
    await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');

    const cartResponse = await page.goto('/cart', { waitUntil: 'domcontentloaded' });
    expect(cartResponse?.status()).toBe(404);
    const checkoutResponse = await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
    expect(checkoutResponse?.status()).toBe(404);
    const aboutResponse = await page.goto('/about-us', { waitUntil: 'domcontentloaded' });
    expect(aboutResponse?.status()).toBe(404);

    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('form[data-card-add-to-cart]')).toHaveCount(0);
    await expect(page.locator('a[href="/about-us"],a[href="/contact"],a[href="/shipping"]')).toHaveCount(0);
    await expect(page.locator('.catalog-card__rating')).toHaveCount(0);

    await page.goto(productUrl!, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('form[data-buy-actions]')).toHaveCount(0);
    await expect(page.locator('#reviews,.rating-summary')).toHaveCount(0);
    const productJsonLd = (await page.locator('script[type="application/ld+json"]').allTextContents()).join('\n');
    expect(productJsonLd).not.toContain('aggregateRating');
    expect(productJsonLd).not.toContain('reviewCount');
  } finally {
    await restore();
  }
});


test('recovery snapshot can be created, verified, downloaded and deleted from admin', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Recovery lifecycle contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/stability', { waitUntil: 'domcontentloaded' });
  const createForm = page.locator('form[action="/admin/system/recovery/create"]');
  await expect(createForm).toBeVisible();
  await createForm.locator('select[name="profile"]').selectOption('database');
  const createResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/admin/system/recovery/create') && response.request().method() === 'POST'
  );
  await createForm.locator('button').click();
  expect((await createResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.store-notice.is-error')).toHaveCount(0);

  const row = page.locator('table.admin-table tbody tr').filter({ hasText: 'manual-admin-database' }).first();
  await expect(row).toBeVisible();
  const download = row.locator('a[href*="/admin/system/recovery/"][href$="/download"]');
  const verify = row.locator('form[action$="/verify"]');
  const remove = row.locator('form[action$="/delete"]');
  await expect(download).toBeVisible();
  await expect(verify).toBeVisible();
  await expect(remove).toBeVisible();

  const verifyResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/recovery/') && response.url().endsWith('/verify') && response.request().method() === 'POST'
  );
  await verify.locator('button[type="submit"]').click();
  expect((await verifyResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.store-notice.is-success')).toBeVisible();

  const verifiedRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'manual-admin-database' }).first();
  const downloadHref = await verifiedRow.locator('a[href$="/download"]').getAttribute('href');
  expect(downloadHref).toBeTruthy();
  const downloadResponse = await page.request.get(downloadHref!);
  expect(downloadResponse.status()).toBe(200);
  expect(downloadResponse.headers()['content-disposition'] || '').toContain('.zip');
  expect((await downloadResponse.body()).byteLength).toBeGreaterThan(100);

  const deleteForm = verifiedRow.locator('form[action$="/delete"]');
  const deleteResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/recovery/') && response.url().endsWith('/delete') && response.request().method() === 'POST'
  );
  await deleteForm.locator('button').click();
  expect((await deleteResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('table.admin-table tbody tr').filter({ hasText: 'manual-admin-database' })).toHaveCount(0);
});


test('Product Builder publication changes the real product page and restores cleanly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating product builder contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const productUrl = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  expect(productUrl).toBeTruthy();

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('input[name="_csrf_token"]').inputValue();
  const originalRaw = await page.locator('textarea[name="layout_json"]').inputValue();
  const layout = JSON.parse(originalRaw);
  const description = layout.blocks?.find((row: { component?: string }) => row.component === 'product_description');
  test.skip(!description, 'Current product layout has no description component to exercise.');
  description.enabled = false;

  const save = await page.request.post('/admin/appearance/builder/product', {
    maxRedirects: 0,
    form: { _csrf_token: token, layout_json: JSON.stringify(layout), builder_action: 'publish' },
  });
  expect(save.status()).toBeGreaterThanOrEqual(300);
  expect(save.status()).toBeLessThan(400);

  try {
    await page.goto(productUrl!, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('.product-block--description')).toHaveCount(0);
  } finally {
    await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
    const restoreToken = await page.locator('input[name="_csrf_token"]').inputValue();
    const restore = await page.request.post('/admin/appearance/builder/product', {
      maxRedirects: 0,
      form: { _csrf_token: restoreToken, layout_json: originalRaw, builder_action: 'publish' },
    });
    expect(restore.status()).toBeGreaterThanOrEqual(300);
    expect(restore.status()).toBeLessThan(400);
  }
});

test('Checkout Builder publication changes the real checkout page and restores cleanly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating checkout builder contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  await expect(card).toBeVisible();
  const addResponse = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await addResponse).status()).toBeLessThan(400);

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/checkout', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('input[name="_csrf_token"]').inputValue();
  const originalRaw = await page.locator('textarea[name="layout_json"]').inputValue();
  const layout = JSON.parse(originalRaw);
  const comment = layout.blocks?.find((row: { component?: string }) => row.component === 'checkout_comment');
  test.skip(!comment, 'Current checkout layout has no optional comment component to exercise.');
  comment.enabled = false;

  const save = await page.request.post('/admin/appearance/builder/checkout', {
    maxRedirects: 0,
    form: { _csrf_token: token, layout_json: JSON.stringify(layout), builder_action: 'publish' },
  });
  expect(save.status()).toBeGreaterThanOrEqual(300);
  expect(save.status()).toBeLessThan(400);

  try {
    const checkout = await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
    expect(checkout?.status()).toBeLessThan(400);
    await expectNoServerError(page);
    await expect(page.locator('[data-checkout-block="comment"],textarea[name="customer_comment"]')).toHaveCount(0);
  } finally {
    await page.goto('/admin/appearance/builder/checkout', { waitUntil: 'domcontentloaded' });
    const restoreToken = await page.locator('input[name="_csrf_token"]').inputValue();
    const restore = await page.request.post('/admin/appearance/builder/checkout', {
      maxRedirects: 0,
      form: { _csrf_token: restoreToken, layout_json: originalRaw, builder_action: 'publish' },
    });
    expect(restore.status()).toBeGreaterThanOrEqual(300);
    expect(restore.status()).toBeLessThan(400);
  }
});


test('admin-created coupon is applied by the real checkout promotion engine', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating promotion contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const code = `E2E${Date.now().toString().slice(-9)}`;
  await loginAdmin(page);
  await page.goto('/admin/commerce/promotions', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-form-grid').first();
  await expect(form).toBeVisible();
  await form.locator('input[name="name"]').fill(`E2E coupon ${code}`);
  await form.locator('select[name="trigger_type"]').selectOption('coupon');
  await form.locator('input[name="code"]').fill(code);
  await form.locator('select[name="discount_type"]').selectOption('percent');
  await form.locator('input[name="discount_value"]').fill('10');
  const createResponse = page.waitForResponse((response) =>
    response.url().includes('/admin/commerce/promotions') && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').click();
  expect((await createResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  const promotionRow = page.locator('table tbody tr').filter({ hasText: code });
  await expect(promotionRow).toBeVisible();

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  await expect(card).toBeVisible();
  const addResponse = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await addResponse).status()).toBeLessThan(400);

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-checkout-form]')).toBeVisible();
  const couponInput = page.locator('[data-coupon-code]');
  test.skip((await couponInput.count()) === 0, 'Checkout layout has no coupon block.');
  await couponInput.fill(code);
  const previewResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/checkout/promotion/preview') && response.request().method() === 'POST'
  );
  await page.locator('[data-coupon-apply]').click();
  const previewResponse = await previewResponsePromise;
  expect(previewResponse.status()).toBe(200);
  const preview = await previewResponse.json();
  expect(preview.ok).toBe(true);
  expect(Number(preview.discount_minor)).toBeGreaterThan(0);
  expect(Number(preview.total_minor)).toBeLessThan(Number(preview.subtotal_minor));

  await page.goto('/admin/commerce/promotions', { waitUntil: 'domcontentloaded' });
  const createdRow = page.locator('table tbody tr').filter({ hasText: code });
  const toggle = createdRow.locator('form[action$="/toggle"]');
  const disableResponse = page.waitForResponse((response) =>
    response.url().includes('/admin/commerce/promotions/') && response.url().endsWith('/toggle') && response.request().method() === 'POST'
  );
  await toggle.locator('button[type="submit"]').click();
  expect((await disableResponse).status()).toBeLessThan(400);
});


test('product slug change updates canonical links and creates a direct old-URL redirect', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating SEO lifecycle contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editLink = page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first();
  const editUrl = await editLink.getAttribute('href');
  expect(editUrl).toBeTruthy();
  await page.goto(editUrl!, { waitUntil: 'domcontentloaded' });

  const name = await page.locator('input[name="name"]').inputValue();
  const slugInput = page.locator('input[name="slug"]');
  const originalSlug = (await slugInput.inputValue()).replace(/^\/+|\/+$/g, '');
  expect(originalSlug).not.toBe('');
  const qaSlug = `e2e-seo-${Date.now()}`;

  await slugInput.fill(qaSlug);
  await submitAndWait(page, 'form.admin-editor-form', editUrl!);

  try {
    const current = await page.goto('/' + qaSlug, { waitUntil: 'domcontentloaded' });
    expect(current?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText(name);
    const expectedCanonical = new URL('/' + qaSlug, page.url()).toString();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', expectedCanonical);

    const old = await page.request.get('/' + originalSlug, { maxRedirects: 0 });
    expect(old.status()).toBe(301);
    expect(old.headers()['location']).toBe('/' + qaSlug);

    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    const card = page.locator('[data-product-card]').filter({ hasText: name }).first();
    await expect(card.locator('h2 a')).toHaveAttribute('href', '/' + qaSlug);
  } finally {
    await page.goto(editUrl!, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="slug"]').fill(originalSlug);
    await submitAndWait(page, 'form.admin-editor-form', editUrl!);
  }
});


test('locale visibility in admin changes the real storefront language selector and context', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating localization contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action="/admin/system/localization/locales"]');
  const candidates = form.locator('input[type="checkbox"][name^="locale["]:not([disabled])');
  test.skip((await candidates.count()) === 0, 'No non-default locale is available.');
  const localeToggle = candidates.first();
  const name = await localeToggle.getAttribute('name');
  expect(name).toBeTruthy();
  const match = name!.match(/^locale\[([^\]]+)\]\[enabled\]$/);
  expect(match).toBeTruthy();
  const locale = match![1];
  const original = await localeToggle.isChecked();

  try {
    if (!original) await localeToggle.check();
    const responsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/system/localization/locales') && response.request().method() === 'POST'
    );
    await form.locator('button[type="submit"]').click();
    expect((await responsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    await page.goto('/?lang=' + encodeURIComponent(locale), { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
    await expect(page.locator('select[name="lang"] option[value="' + locale + '"]')).toHaveCount(1);
  } finally {
    if (!original) {
      await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
      const restoreForm = page.locator('form[action="/admin/system/localization/locales"]');
      await restoreForm.locator('input[name="locale[' + locale + '][enabled]"]').uncheck();
      const restoreResponse = page.waitForResponse((response) =>
        response.url().includes('/admin/system/localization/locales') && response.request().method() === 'POST'
      );
      await restoreForm.locator('button[type="submit"]').click();
      expect((await restoreResponse).status()).toBeLessThan(400);
    }
  }
});

test('enabled non-default currency without prices stays hidden from storefront context', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating currency invariant test runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const currencyForm = page.locator('form[action="/admin/system/localization/currencies"]');
  const rows = currencyForm.locator('tbody tr');
  let targetCode = '';
  let originalEnabled = false;

  for (let i = 0; i < await rows.count(); i++) {
    const row = rows.nth(i);
    if ((await row.locator('.admin-status-pill.is-active').count()) > 0) continue;
    const priceCount = Number((await row.locator('td').nth(2).innerText()).trim().match(/^\d+/)?.[0] ?? '-1');
    if (priceCount !== 0) continue;
    const toggle = row.locator('input[type="checkbox"][name$="[enabled]"]');
    if ((await toggle.count()) === 0) continue;
    const inputName = await toggle.getAttribute('name');
    const match = inputName?.match(/^currency\[([A-Z]{3})\]\[enabled\]$/);
    if (!match) continue;
    targetCode = match[1];
    originalEnabled = await toggle.isChecked();
    if (!originalEnabled) await toggle.check();
    const auto = row.locator('input[type="checkbox"][name$="[auto_convert]"]');
    if (await auto.count()) await auto.uncheck();
    break;
  }
  test.skip(targetCode === '', 'No non-default zero-price currency is available.');

  try {
    const saveResponse = page.waitForResponse((response) =>
      response.url().includes('/admin/system/localization/currencies') && response.request().method() === 'POST'
    );
    await currencyForm.locator('button[type="submit"]').click();
    expect((await saveResponse).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    await page.goto('/?currency=' + targetCode, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('select[name="currency"] option[value="' + targetCode + '"]')).toHaveCount(0);
    const footerContext = await page.locator('.site-footer__bottom span').last().innerText();
    expect(footerContext).not.toContain(targetCode);
  } finally {
    if (!originalEnabled) {
      await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
      const restoreForm = page.locator('form[action="/admin/system/localization/currencies"]');
      const toggle = restoreForm.locator('input[name="currency[' + targetCode + '][enabled]"]');
      if (await toggle.count()) await toggle.uncheck();
      const restoreResponse = page.waitForResponse((response) =>
        response.url().includes('/admin/system/localization/currencies') && response.request().method() === 'POST'
      );
      await restoreForm.locator('button[type="submit"]').click();
      expect((await restoreResponse).status()).toBeLessThan(400);
    }
  }
});
