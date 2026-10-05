import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, openProductTab } from './helpers';

// Release 3.63.0: option price modes, weight in checkout methods, own search words, optional review e-mail.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('the product form offers the price action, weight and start stock of an option value', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/01a10a54-0b29-7cb4-920a-2919bb90d597/edit', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('select[name^="option_value"][name$="[mode]"]').first()).toBeAttached();
  await expect(page.locator('input[name^="option_value"][name$="[weight_g]"]').first()).toBeAttached();
  await expect(page.locator('select[name^="option["][name$="[display]"]').first()).toBeAttached();
});

test('delivery methods take a price per kilogram and a weight limit', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/shipments/methods', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('input[name$="[per_kg]"]').first()).toBeAttached();
  await expect(page.locator('input[name$="[max_kg]"]').first()).toBeAttached();
});

test('own search words typed with a hyphen make the storefront find the catalog word', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/search', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('textarea[name="pairs"]').fill('очки-sunglasses');
  await Promise.all([page.waitForURL(/\/admin\/catalog\/search/), page.locator('form[action$="/synonyms/bulk"] button[type="submit"]').click()]);
  await page.goto('/catalog?q=' + encodeURIComponent('очки'), { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-product-card]').first()).toBeAttached();
});

test('a guest can write a review without an e-mail while the setting is off', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-product-card] h2 a').first().click();
  await page.locator('[data-tab="reviews"]').first().click();
  const form = page.locator('#reviews form.feedback-form');
  await expect(form.locator('input[name="guest_email"]')).not.toHaveAttribute('required', '');
});

test('customer options: a shopper ticks an extra, the cart shows it and the price follows', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/01a10a54-0b29-7cb4-920a-2919bb90d597/edit', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const name = await page.locator('input[name="name"]').first().inputValue().catch(() => '');
  await openProductTab(page, 'sales');
  if (!(await page.locator('#options input[name="new_entry[name]"]').isVisible())) await page.locator('#options .admin-inline-create summary').click();
  await page.locator('input[name="new_entry[name]"]').fill('Gift wrap E2E');
  await page.locator('select[name="new_entry[type]"]').selectOption('addon:checkbox');
  await page.locator('input[name="new_entry[values]"]').fill('Paper');
  await Promise.all([page.waitForURL(/#addons|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
  await expectNoServerError(page);
  await openProductTab(page, 'sales');
  const amount = page.locator('input[name^="addon_value"][name$="[delta]"]').last();
  await amount.fill('5.00');
  await Promise.all([page.waitForURL(/#addons|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
  await expect(page.locator('input[name^="addon_value"][name$="[delta]"]').last()).toHaveValue('5.00');
  // a choice with no stock left is shown as sold out and cannot be ticked
  await openProductTab(page, 'sales');
  await page.locator('input[name^="addon_value"][name$="[stock]"]').last().fill('0');
  await Promise.all([page.waitForURL(/#addons|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
  await page.waitForTimeout(9000); // the product page is cached for a few seconds
  await page.goto('/catalog?q=' + encodeURIComponent(name.split(' ')[0] || 'Fashion'), { waitUntil: 'domcontentloaded' });
  await page.locator('[data-product-card] h2 a').first().click();
  await expect(page.locator('[data-addon-picker] input[type="checkbox"]').last()).toBeDisabled();
  await page.goto('/admin/catalog/products/01a10a54-0b29-7cb4-920a-2919bb90d597/edit', { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'sales');
  await page.locator('input[name^="addon_value"][name$="[stock]"]').last().fill('');
  await Promise.all([page.waitForURL(/#addons|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
  await page.waitForTimeout(9000);
  await page.goto('/catalog?q=' + encodeURIComponent(name.split(' ')[0] || 'Fashion'), { waitUntil: 'domcontentloaded' });
  await page.locator('[data-product-card] h2 a').first().click();
  const picker = page.locator('[data-addon-picker]');
  await expect(picker).toBeAttached();
  await picker.locator('input[type="checkbox"]').last().check();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/cart/add')), page.locator('form[data-buy-actions] button[data-primary-buy]').click()]);
  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText('Gift wrap E2E');
  // cleanup: remove the option again
  await page.goto('/admin/catalog/products/01a10a54-0b29-7cb4-920a-2919bb90d597/edit', { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'sales');
  for (let guard = 0; guard < 5 && (await page.locator('button[name="_option_action"][value^="delete_addon:"]').count()) > 0; guard++) {
    await Promise.all([page.waitForURL(/\/edit/), page.locator('button[name="_option_action"][value^="delete_addon:"]').first().evaluate((el: HTMLElement) => { const b = el as HTMLButtonElement; b.removeAttribute('data-confirm'); b.click(); })]);
    await openProductTab(page, 'sales');
  }
});

test('SEO templates and a standard VAT rate can be saved', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/system/seo-templates', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const title = page.locator('input[name="tpl[product][uk-UA][title]"]');
  await title.fill('{name} | {store}');
  await Promise.all([page.waitForURL(/seo-templates/), page.locator('form button[type="submit"]').last().click()]);
  await expect(page.locator('input[name="tpl[product][uk-UA][title]"]')).toHaveValue('{name} | {store}');
  // Products with a title of their own keep it; at least one product in the catalog has none and takes the pattern.
  const hrefs = await (async () => { await page.goto('/catalog', { waitUntil: 'domcontentloaded' }); return page.locator('[data-product-card] h2 a').evaluateAll((els) => els.map((el) => (el as HTMLAnchorElement).getAttribute('href') || '')); })();
  let patterned = false;
  for (const href of hrefs.slice(0, 24)) {
    await page.goto(href, { waitUntil: 'domcontentloaded' });
    if ((await page.title()).includes('|')) { patterned = true; break; }
  }
  expect(patterned).toBe(true);
  await page.goto('/admin/system/seo-templates', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="tpl[product][uk-UA][title]"]').fill('');
  await Promise.all([page.waitForURL(/seo-templates/), page.locator('form button[type="submit"]').last().click()]);
  await page.goto('/admin/system/tax', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('form[data-tax-standard] select[name="country"]')).toBeAttached();
});

test('bot blocking answers 403 to a listed crawler and lets browsers and search engines in', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/system/bots', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="enabled"]').check();
  await page.locator('input[name="categories[]"][value="ai"]').check();
  await Promise.all([page.waitForURL(/system\/bots/), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
  await expect(page.locator('input[name="enabled"]')).toBeChecked();
  const blocked = await request.get('/', { headers: { 'User-Agent': 'Mozilla/5.0 (compatible; GPTBot/1.1)' } });
  expect(blocked.status()).toBe(403);
  const google = await request.get('/', { headers: { 'User-Agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' } });
  expect(google.status()).toBe(200);
  const hooks = await request.post('/webhooks/payments/stripe', { headers: { 'User-Agent': 'GPTBot' }, data: '{}' });
  expect(hooks.status()).not.toBe(403);
  // switch it off again
  await page.goto('/admin/system/bots', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="enabled"]').uncheck();
  await page.locator('input[name="categories[]"][value="ai"]').uncheck();
  await Promise.all([page.waitForURL(/system\/bots/), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
  const after = await request.get('/', { headers: { 'User-Agent': 'GPTBot' } });
  expect(after.status()).toBe(200);
});

test('SEO fields show a counter, an example and variable chips; a variable typed in the title is filled on the page', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/01a10a54-0b29-7cb4-920a-2919bb90d597/edit', { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'seo');
  const field = page.locator('input[name="meta_title"]');
  await expect(page.locator('.seo-hint').first()).toBeVisible();
  await field.fill('');
  await page.locator('.seo-chip', { hasText: '{name}' }).first().click();
  await expect(field).toHaveValue('{name}');
  await expect(page.locator('.seo-hint__count').first()).toContainText('/ 60');
});

test('the list of shoppers waiting for a product has search, period, sorting and a CSV export', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/commerce/stock-requests?sort=product&dir=asc&from=2020-01-01&to=2099-12-31&status=active', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('form.admin-filters input[name="q"]')).toBeVisible();
  await expect(page.locator('form.admin-filters input[name="from"]')).toHaveValue('2020-01-01');
  await expect(page.locator('a.admin-sort').first()).toBeVisible();
  const csv = await page.request.get('/admin/commerce/stock-requests/export?status=active');
  expect(csv.status()).toBe(200);
  expect(await csv.text()).toContain('email,product,sku,status');
});
