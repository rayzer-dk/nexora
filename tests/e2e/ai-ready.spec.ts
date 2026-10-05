import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('llms.txt introduces the shop to AI assistants and follows the admin settings', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const marker = `Shop profile ${Date.now()}`;
  const first = await request.get('/llms.txt');
  expect(first.status()).toBe(200);
  expect(first.headers()['content-type']).toContain('text/plain');
  expect(await first.text()).toMatch(/^# .+\n\n> /);

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/system/ai-ready', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('textarea[name="description"]').fill(`${marker}\nWe sell test goods.`);
  await page.locator('textarea[name="links"]').fill('Delivery|/shipping|Terms');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
  await expectNoServerError(page);

  const body = await (await request.get('/llms.txt')).text();
  expect(body).toContain(`> ${marker}`);
  expect(body).toContain('We sell test goods.');
  expect(body).toContain('[Delivery](');
  const full = await (await request.get('/llms-full.txt')).text();
  expect(full).toContain('## Products');

  // restore the empty profile
  await page.goto('/admin/system/ai-ready', { waitUntil: 'domcontentloaded' });
  await page.locator('textarea[name="description"]').fill('');
  await page.locator('textarea[name="links"]').fill('');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
});
