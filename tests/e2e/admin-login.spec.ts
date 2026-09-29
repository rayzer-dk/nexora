import { expect, test } from '@playwright/test';

test('sign-in and other session pages are never cacheable by shared caches', async ({ request }) => {
  for (const path of ['/admin/login', '/account/login', '/cart']) {
    const response = await request.get(path);
    expect(response.status(), path).toBeLessThan(400);
    expect(response.headers()['cache-control'] ?? '', path).toContain('no-store');
  }
});

test('a wrong password shows an error, a correct one reaches the dashboard', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once: sign-in attempts are rate limited.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill('definitely-wrong-password');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-error[role="alert"]')).toHaveCount(1);
  await expect(page).toHaveURL(/\/admin\/login/);

  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expect(page).not.toHaveURL(/\/admin\/login/);
  await expect(page.locator('aside a[href^="/admin"]').first()).toBeVisible();
});
