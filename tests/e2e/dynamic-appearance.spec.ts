import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

test('Dynamic preset selects the locally hosted Inter font without changing other presets', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Check the admin preset once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront');
  const dynamic = page.locator('input[name="theme_preset"][value="dynamic"]');
  await expect(dynamic).toHaveCount(1);
  await dynamic.check();
  await expect(page.locator('[name="theme_font"]')).toHaveValue('inter');
  await expect(page.locator('[name="theme_radius"]')).toHaveValue('8');
  await expect(page.locator('[name="theme_shadow"]')).toHaveValue('none');
  await page.locator('input[name="theme_preset"][value="modern"]').check();
  await expect(page.locator('[name="theme_font"]')).toHaveValue('system');
});

test('the current category navigation item has an accessible active marker and Dynamic underline', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Desktop category navigation.');
  await page.goto('/');
  const links = page.locator('.reference-nav-item > a[href^="/"]:not([href^="//"])');
  await expect(links.first()).toBeVisible();
  const href = await links.first().getAttribute('href');
  expect(href).toBeTruthy();
  await page.goto(href!);
  const active = page.locator('.reference-nav-item > a[aria-current="page"]').first();
  await expect(active).toBeVisible();
  // Exercise the opt-in stylesheet without persisting any global store settings.
  await page.locator('body').evaluate(body => body.dataset.themePreset = 'dynamic');
  await expect.poll(() => active.evaluate(link => getComputedStyle(link, '::after').transform)).toBe('matrix(1, 0, 0, 1, 0, 0)');
});
