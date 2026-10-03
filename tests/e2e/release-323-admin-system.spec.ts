import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);
}

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once on desktop (admin sign-in is rate limited).');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('cron page shows absolute commands, status, web trigger and runs now', async ({ page, request }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/cron', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const line = (await page.locator('#cron-line').innerText()).trim();
  expect(line).toMatch(/^\*\/5 \* \* \* \* \/\S*php\S* \/\S+\/bin\/console commerce:cron:run >\/dev\/null 2>&1$/);
  await expect(page.locator('#cron-command')).toContainText('commerce:cron:run');
  await expect(page.locator('[data-cron-status]')).toBeVisible();
  const url = (await page.locator('#cron-web-url').innerText()).trim();
  expect(url).toMatch(/\/cron\/[a-f0-9]{40}$/);
  expect((await request.get(url.replace(/^https?:\/\/[^/]+/, ''))).status()).toBe(200);
  expect((await request.get('/cron/' + '0'.repeat(40))).status()).toBe(404);
  await page.locator('form[action$="/admin/system/cron/run"] button.is-primary').click();
  await expectNoServerError(page);
  await expect(page.locator('[data-cron-status]')).toBeVisible();
  await expect(page.locator('.admin-cron-output')).toBeVisible();
});

test('AI page offers refreshing the model list and a model select', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('select[name="model"]').first()).toBeVisible();
  expect(await page.locator('select[name="model"]').first().locator('option').count()).toBeGreaterThan(2);
  await expect(page.locator('a.admin-hint__link[href^="https://"]').first()).toBeVisible();
});

test('checkboxes show a clear checked state and the safe-mode button has hover styling', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
  const box = page.locator('input[type="checkbox"]').first();
  await expect(box).toBeVisible();
  const before = await box.evaluate((el) => getComputedStyle(el).backgroundColor);
  await box.check();
  const after = await box.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(after).not.toBe(before);
  expect(await box.evaluate((el) => getComputedStyle(el, '::after').opacity)).toBe('1');
  await page.goto('/admin/system/stability', { waitUntil: 'domcontentloaded' });
  const safe = page.locator('form[action*="isolate"] button');
  await expect(safe).toHaveClass(/admin-button/);
  const idle = await safe.evaluate((el) => getComputedStyle(el).backgroundColor);
  await safe.hover();
  await page.waitForTimeout(250);
  expect(await safe.evaluate((el) => getComputedStyle(el).backgroundColor)).not.toBe(idle);
});

test('help hints with where-to-get links exist and the guide tab lists services', async ({ page }) => {
  await loginAdmin(page);
  for (const path of ['/admin/system/tracking', '/admin/system/captcha', '/admin/system/store']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    expect(await page.locator('.admin-hint').count()).toBeGreaterThan(2);
  }
  await page.goto('/admin/system/integrations', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-guide__card').first()).toBeAttached();
  expect(await page.locator('.admin-guide__card').count()).toBeGreaterThan(10);
  expect(await page.locator('.admin-guide__card a[href^="https://"]').count()).toBeGreaterThan(10);
});

test('sidebar scroll position survives navigation', async ({ page }) => {
  await loginAdmin(page);
  await page.setViewportSize({ width: 1440, height: 600 });
  await page.goto('/admin/system/tracking', { waitUntil: 'domcontentloaded' });
  const nav = page.locator('.admin-nav');
  await page.waitForTimeout(300);
  const before = await nav.evaluate((el) => el.scrollTop);
  expect(before).toBeGreaterThan(0);
  await page.locator('.admin-nav a[href="/admin/system/captcha"]').click();
  await page.waitForURL(/system\/captcha/);
  await page.waitForTimeout(300);
  const after = await nav.evaluate((el) => el.scrollTop);
  expect(after).toBeGreaterThan(0);
  expect(Math.abs(after - before)).toBeLessThan(80);
});

test('media settings are a simple preset list with an advanced section', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.media-preset')).toHaveCount(4);
  await expect(page.locator('[data-media-advanced]')).toBeAttached();
  await page.locator('[data-media-advanced] > summary').click();
  await page.locator('[data-media-advanced] select[name="format"]').selectOption('jpeg');
  await expect(page.locator('input[name="preset"][value="custom"]')).toBeChecked();
});

test('no hosting provider branding in admin pages', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/cron', { waitUntil: 'domcontentloaded' });
  expect((await page.content()).toLowerCase()).not.toContain('mirohost');
});
