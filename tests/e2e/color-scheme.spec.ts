import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function setScheme(page: Page, scheme: 'light' | 'auto' | 'dark'): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.locator('select[name="theme_color_scheme"]').selectOption(scheme);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(select[name="theme_color_scheme"]) button[type="submit"]').first().click(),
  ]);
  await expectNoServerError(page);
}

const background = (page: Page) => page.evaluate(() => getComputedStyle(document.body).backgroundColor);

test('color scheme configured in admin drives the storefront: light, auto with visitor toggle, dark', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating appearance audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  await loginAdmin(page);
  const visitor = await page.context().browser()!.newContext({ colorScheme: 'dark', baseURL: testInfo.project.use.baseURL });
  const shop = await visitor.newPage();
  try {
    // Default: light even on a dark device; the visitor still gets a toggle.
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('html')).toHaveAttribute('data-color-scheme', 'light');
    await expect(shop.locator('[data-theme-toggle]')).toBeVisible();
    const light = await background(shop);

    // Auto: follows the dark device and offers a toggle that remembers the visitor's choice.
    await setScheme(page, 'auto');
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('html')).toHaveAttribute('data-color-scheme', 'auto');
    await expect.poll(() => background(shop)).not.toBe(light);
    await expect(shop.locator('[data-theme-toggle]')).toBeVisible();
    await shop.locator('[data-theme-toggle]').click();
    await expect(shop.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect.poll(() => background(shop)).toBe(light);
    await shop.reload({ waitUntil: 'domcontentloaded' });
    await expect(shop.locator('html')).toHaveAttribute('data-theme', 'light');

    // Dark: always dark unless the visitor explicitly chose light in auto mode (the toggle is still offered).
    await setScheme(page, 'dark');
    const fresh = await visitor.newPage();
    await fresh.addInitScript(() => { try { localStorage.removeItem('mc_theme'); } catch { /* storage blocked */ } });
    await fresh.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(fresh.locator('html')).toHaveAttribute('data-color-scheme', 'dark');
    expect(await background(fresh)).not.toBe(light);
    await expect(fresh.locator('[data-theme-toggle]')).toBeVisible();
  } finally {
    await setScheme(page, 'light');
    await visitor.close();
  }
});

test('admin theme toggle switches the admin between light and dark', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  await page.emulateMedia({ colorScheme: 'light' });
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const light = await background(page);
  await page.locator('[data-theme-toggle]').click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  expect(await background(page)).not.toBe(light);
  await page.locator('[data-theme-toggle]').click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});
