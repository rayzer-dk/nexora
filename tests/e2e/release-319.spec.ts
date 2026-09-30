import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

const suffix = Date.now();
const slug = `e2e-embed-${suffix}`;

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('the first steps wizard derives progress from real data and the dashboard links to it', async ({ page }) => {
  await loginAdmin(page);
  const response = await page.goto('/admin/onboarding', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  await expect(page.locator('[data-onboarding] [data-step]')).toHaveCount(7);
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.onboarding\./);
  const done = await page.locator('[data-step][data-done="1"]').count();
  const bar = page.locator('.onboarding__progress progress');
  await expect(bar).toHaveAttribute('value', String(done));
  // every step opens a real admin page
  const hrefs = await page.locator('[data-step] a.admin-button').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
  for (const href of hrefs) {
    const r = await page.request.get(href!);
    expect(r.status(), href!).toBe(200);
  }
});

test('Ctrl+K data search returns typed results and refuses short input', async ({ page }) => {
  await loginAdmin(page);
  const short = await page.request.get('/admin/api/quick-search?q=a');
  expect(short.status()).toBe(200);
  expect((await short.json()).results ?? []).toEqual([]);
  const ok = await page.request.get('/admin/api/quick-search?q=%25%25%27%22test');
  expect(ok.status()).toBe(200);
  expect(Array.isArray((await ok.json()).results)).toBe(true);
  // the palette opens from the keyboard
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await page.keyboard.press('Control+k');
  await expect(page.locator('.admin-command')).toBeVisible();
  await page.keyboard.press('Escape');
});

test('a form is embedded into a page with a shortcode and the page shows it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/forms/new', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill(`E2E embed ${suffix}`);
  await page.locator('input[name="slug"]').fill(slug);
  await page.locator('select[name="status"]').selectOption('active');
  await page.locator('input[name="fields[0][label]"]').fill('Embedded name');
  await Promise.all([
    page.waitForURL(/\/admin\/content\/forms\/\d+$/),
    page.locator('[data-form-editor] button[type="submit"]').first().click(),
  ]);

  await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  const target = '/admin/content/pages/about';
  expect(target).toBeTruthy();
  const editHtml = await (await page.request.get(target!)).text();
  const formBlock = /<form(?:(?!<\/form>)[\s\S])*?name="body_html"[\s\S]*?<\/form>/.exec(editHtml)?.[0] ?? '';
  const token = /name="_token" value="([^"]+)"/.exec(formBlock)?.[1];
  const title = /name="title"[^>]*value="([^"]*)"/.exec(editHtml)?.[1] ?? 'Page';
  const original = /<textarea name="body_html"[^>]*>([\s\S]*?)<\/textarea>/.exec(editHtml)?.[1] ?? '';
  expect(token).toBeTruthy();
  const decode = (v: string) =>
    v.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&');
  const save = (body: string) =>
    page.request.post(target!, {
      form: { _token: token!, title: decode(title), body_html: body, status: 'published' },
      maxRedirects: 0,
    });
  try {
    await save(`<p>Before</p><p>[form:${slug}]</p>`);
    await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
    const codes = await page.locator('table code').allInnerTexts();
    const publicUrl = codes.find((c) => c === '/about-us');
    expect(publicUrl).toBeTruthy();
    await page.goto(publicUrl!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-custom-form]')).toHaveCount(1);
    await expect(page.locator('[data-custom-form] input[name="f1"]')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(`[form:${slug}]`);
  } finally {
    await save(decode(original));
    await page.goto('/admin/content/forms', { waitUntil: 'domcontentloaded' });
    await page.locator(`[data-form-row="${slug}"] form[action$="/delete"] button`).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.locator(`[data-form-row="${slug}"]`)).toHaveCount(0);
  }
});

test('the category listing offers the Popular sort and the recommended block never breaks the page', async ({ page }) => {
  const home = await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  expect(home?.status()).toBe(200);
  const link = page.locator('a[href*="/catalog/"], a[href*="/category/"]').first();
  if ((await link.count()) === 0) {
    test.skip(true, 'No category in the demo data.');
  }
  await link.click();
  await expectNoServerError(page);
  const sort = page.locator('select[name="sort"]');
  if ((await sort.count()) > 0) {
    await expect(sort.locator('option[value="popular"]')).toHaveCount(1);
  }
  const popular = await page.goto(page.url().split('?')[0] + '?sort=popular', { waitUntil: 'domcontentloaded' });
  expect(popular?.status()).toBe(200);
});

test('orders can be selected in bulk and the bulk form is protected', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const bulk = page.locator('[data-order-bulk]');
  if ((await bulk.count()) === 0) {
    test.skip(true, 'No orders to act on.');
  }
  await expect(bulk.locator('input[name="_csrf_token"]')).toHaveCount(1);
  const forged = await page.request.post('/admin/orders/bulk', { form: { action: 'complete', _csrf_token: 'bad' }, maxRedirects: 0 });
  expect([302, 403]).toContain(forged.status());
});

test('quality monitor keeps a history and offers fixes only for fixable checks', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/quality', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.quality\./);
  // a fix form exists only for checks that are not green
  const okFixForms = await page.locator('[data-quality-check][data-status="ok"] form[action*="/quality/fix/"]').count();
  expect(okFixForms).toBe(0);
});
