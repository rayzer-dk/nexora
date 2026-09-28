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

  if (original) await forum.uncheck(); else await forum.check();
  await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const forumLinks = page.locator('a[href="/forum"]');
  if (original) await expect(forumLinks).toHaveCount(0);
  else await expect(forumLinks.first()).toBeVisible();

  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const restoreForm = page.locator('form.admin-editor-form[data-dirty-guard]');
  const restoreForum = restoreForm.locator('input[name="feature_forum"]');
  if (original) await restoreForum.check(); else await restoreForum.uncheck();
  await submitAndWait(page, 'form.admin-editor-form[data-dirty-guard]', '/admin/system/site');

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
  const originalSubtitle = await subtitle.inputValue();
  const originalPrimary = await primary.inputValue();
  const originalRadius = await radius.inputValue();

  const marker = `E2E storefront contract ${Date.now()}`;
  const qaPrimary = originalPrimary.toUpperCase() === '#123456' ? '#654321' : '#123456';
  const qaRadius = originalRadius === '23' ? '22' : '23';
  await subtitle.fill(marker);
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
  const tokens = await page.evaluate(() => {
    const style = getComputedStyle(document.documentElement);
    return {
      primary: style.getPropertyValue('--mc-color-primary').trim().toUpperCase(),
      radius: style.getPropertyValue('--mc-radius-lg').trim(),
    };
  });
  expect(tokens.primary).toBe(qaPrimary.toUpperCase());
  expect(tokens.radius).toBe(`${qaRadius}px`);

  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const restore = page.locator('form.admin-storefront-form');
  await restore.locator('input[name="brand_subtitle"]').fill(originalSubtitle);
  await restore.locator('input[name="theme_primary"]').fill(originalPrimary);
  await restore.locator('input[name="theme_radius"]').evaluate((element, value) => {
    const input = element as HTMLInputElement;
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, originalRadius);
  await submitAndWait(page, 'form.admin-storefront-form', '/admin/appearance/storefront');
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
  await deleteForm.locator('button[type="submit"]').click();
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
  await deleteForm.locator('button[type="submit"]').click();
  expect((await deleteResponsePromise).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.getByText(label, { exact: true })).toHaveCount(0);
});
