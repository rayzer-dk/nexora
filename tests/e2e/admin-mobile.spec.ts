import { expect, test } from '@playwright/test';

// Every page of the admin menu must fit a phone: wide tables scroll inside their frame, the page itself never scrolls sideways.
test('admin pages fit a 390px phone screen without sideways scrolling', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Read-only, runs once.');
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
  await page.goto('/admin', { waitUntil: 'load' });
  const links = await page.$$eval('.admin-nav a[href^="/admin"]', (anchors) => Array.from(new Set(anchors.map((a) => a.getAttribute('href') || ''))));
  expect(links.length).toBeGreaterThan(20);
  const wide: string[] = [];
  for (const href of ['/admin', ...links]) {
    await page.goto(href, { waitUntil: 'load' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (overflow > 0) wide.push(`${href} (+${overflow}px)`);
  }
  expect(wide, 'admin pages wider than the phone screen').toEqual([]);
});
