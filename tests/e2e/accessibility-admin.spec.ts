import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// Every screen of the admin menu and the signed-in customer account must pass WCAG 2.2 AA (read-only, once).
test('every admin menu screen has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Read-only, runs once.');
  test.setTimeout(900_000);
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'load' });
  const links = await page.$$eval('.admin-nav a[href^="/admin"]', (anchors) => Array.from(new Set(anchors.map((a) => a.getAttribute('href') || ''))));
  expect(links.length).toBeGreaterThan(20);
  const problems: string[] = [];
  for (const href of ['/admin', ...links]) {
    const response = await page.goto(href, { waitUntil: 'domcontentloaded' });
    if ((response?.status() ?? 0) >= 400) continue;
    await page.waitForTimeout(300);
    const results = await new AxeBuilder({ page }).withTags(WCAG).analyze();
    for (const v of results.violations) problems.push(`${href} ${v.id} [${v.impact}] x${v.nodes.length} ${v.nodes[0]?.html.slice(0, 110)}`);
  }
  expect(problems).toEqual([]);
});

test('the signed-in customer account has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Registers one customer, runs once.');
  test.setTimeout(120_000);
  const email = `a11y${Date.now()}@example.test`;
  const password = 'Nexora-A11y-2026-Strong!';
  await page.goto('/account/register', { waitUntil: 'load' });
  await page.fill('input[name="display_name"]', 'Тест Покупець');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.waitForTimeout(2500); // the form refuses a submit that is faster than a person
  await page.locator('form:has(input[name="display_name"]) button[type="submit"]').click();
  await page.goto('/account/login', { waitUntil: 'load' });
  await page.fill('input[name="_username"], input[name="email"]', email);
  await page.fill('input[type="password"]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(input[type="password"]) button[type="submit"]').first().click()]);
  const problems: string[] = [];
  for (const href of ['/account', '/account/verification', '/account/wishlist', '/account/saved-carts', '/account/rewards', '/compare']) {
    const response = await page.goto(href, { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 0, href).toBeLessThan(400);
    const results = await new AxeBuilder({ page }).withTags(WCAG).analyze();
    for (const v of results.violations) problems.push(`${href} ${v.id} [${v.impact}] ${v.nodes[0]?.html.slice(0, 110)}`);
  }
  expect(problems).toEqual([]);
});
