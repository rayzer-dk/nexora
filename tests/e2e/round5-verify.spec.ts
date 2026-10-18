import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError } from './helpers';

// Round 5 items that were done before this build: each page opens, shows the promised control and highlights its menu item.
test.describe.configure({ retries: 0, mode: 'serial' });

const shots = process.env.R5_SHOTS ?? '';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function shot(page: Page, name: string): Promise<void> {
  if (shots === '') return;
  await page.screenshot({ path: `${shots}/${name}-${page.viewportSize()?.width ?? 0}.png`, fullPage: true });
}

/** Opens an admin page and checks the left menu marks exactly one item as the current section. */
async function openAdmin(page: Page, url: string): Promise<void> {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-sidebar a[aria-current="page"]')).toHaveCount(1);
  await expectNoHorizontalOverflow(page);
}

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once against the audit copy.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

for (const width of [1440, 390]) {
  test(`round 5 admin pages at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await loginAdmin(page);

    await openAdmin(page, '/admin/system/store');
    const country = page.locator('select[name="country_code"]');
    await expect(country).toBeVisible();
    expect(await country.locator('option').count()).toBeGreaterThan(20);
    await expect(page.locator('main')).not.toContainText('Домени магазину');
    await shot(page, 'store');

    await openAdmin(page, '/admin/system/migration');
    const oc = page.locator('#opencart form');
    await oc.locator('input[name="host"]').fill('127.0.0.1');
    await oc.locator('input[name="port"]').fill('65000');
    await oc.locator('input[name="database"]').fill('old_shop');
    await oc.locator('input[name="db_user"]').fill('nobody');
    await oc.locator('button[name="mode"][value="check"]').click();
    await expectNoServerError(page);
    await expect(page.locator('.admin-notice.is-error')).toHaveCount(1);
    await expect(page.locator('.admin-notice.is-error')).not.toBeEmpty();
    await shot(page, 'migration-bad-credentials');

    await openAdmin(page, '/admin/system/prro');
    for (const field of ['q', 'status', 'from', 'to']) await expect(page.locator(`#receipts [name="${field}"]`)).toBeVisible();
    await expect(page.locator('button[name="action"][value="test"]')).toBeVisible();
    await shot(page, 'prro');

    await openAdmin(page, '/admin/system/seo-indexnow');
    await expect(page.locator('a[href$=".txt"] code')).toBeVisible();
    await shot(page, 'indexnow');

    await openAdmin(page, '/admin/system/bots');
    await page.locator('input[name="test_ua"]').fill('Mozilla/5.0 (compatible; GPTBot/1.0)');
    await page.locator('input[name="test_ip"]').fill('203.0.113.7');
    await page.locator('input[name="test_ip"]').press('Enter');
    await expectNoServerError(page);
    await expect(page.locator('.admin-notice.is-success, .admin-notice.is-warning')).toHaveCount(1);
    await shot(page, 'bots-test');

    await openAdmin(page, '/admin/system/captcha');
    await expect(page.locator('select[name="builtin_style"] option')).toHaveCount(2);
    await shot(page, 'captcha-admin');

    await openAdmin(page, '/admin/system/push');
    await expect(page.locator('.push-check li')).toHaveCount(5);
    await shot(page, 'push');

    await openAdmin(page, '/admin/system/stability');
    for (const cell of await page.locator('td.is-actions .admin-inline-actions').all()) {
      const icons = await cell.locator('.admin-icon-button').evaluateAll((items) => items.map((el) => Math.round(el.getBoundingClientRect().top)));
      expect(new Set(icons).size, 'action icons stay on one line').toBeLessThanOrEqual(1);
    }
    await shot(page, 'stability');

    await openAdmin(page, '/admin/system/quality');
    const fixable = page.locator('[data-quality-autofix]');
    if (await fixable.count() > 0) await expect(fixable.first()).toBeVisible();
    await shot(page, 'quality');

    await openAdmin(page, '/admin/system/integrations/connections/marketplaces');
    await shot(page, 'marketplaces');
  });
}

test('built-in captcha shows both styles on the storefront', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  const settings = page.locator('[data-captcha-settings]');
  const apply = async (provider: string, style: string, forms: string[]): Promise<void> => {
    await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
    await settings.locator('select[name="provider"]').selectOption(provider);
    await settings.locator('select[name="builtin_style"]').selectOption(style);
    for (const box of await settings.locator('input[name="forms[]"]').all()) {
      if (forms.includes((await box.getAttribute('value'))!)) await box.check(); else await box.uncheck();
    }
    await settings.locator('button[type="submit"]').click();
    await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  };

  await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
  const before = {
    provider: await settings.locator('select[name="provider"]').inputValue(),
    style: await settings.locator('select[name="builtin_style"]').inputValue(),
    forms: await settings.locator('input[name="forms[]"]:checked').evaluateAll((boxes) => boxes.map((box) => (box as HTMLInputElement).value)),
  };
  try {
    for (const [style, selector] of [['slider', '.captcha__range'], ['choice', '[data-captcha-choice]']] as const) {
      await apply('builtin', style, ['newsletter']);
      const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: { width: 390, height: 844 } });
      const shop = await ctx.newPage();
      await shop.goto('/', { waitUntil: 'networkidle' });
      const form = shop.locator('form.newsletter-signup__form');
      await expect(form.locator(selector)).toHaveCount(1);
      await form.scrollIntoViewIfNeeded();
      if (shots !== '') await form.screenshot({ path: `${shots}/captcha-${style}-390.png` });
      await ctx.close();
    }
  } finally {
    await apply(before.provider, before.style, before.forms);
  }
});
