import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectAccessible(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  // WCAG 2.2 AA is the target, so every violation of these rules counts, not only the serious ones.
  expect(results.violations.map((v) => `${v.id} [${v.impact}]: ${v.help} (${v.nodes.length}) ${v.nodes[0]?.html.slice(0, 120)}`), label).toEqual([]);
}

const routes = ['/', '/catalog', '/cart', '/account/login', '/account/register', '/blog', '/contact', '/admin/login'];

for (const route of routes) {
  test(`${route} has no WCAG 2.2 AA violations`, async ({ page }) => {
    const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 0).toBeLessThan(500);
    await expectAccessible(page, route);
  });
}

test('a product page and the checkout have no WCAG 2.2 AA violations', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('.catalog-card a[href^="/"]').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  await expectAccessible(page, 'product ' + href);
  const add = page.locator('form.buy-actions button[type="submit"]').first();
  if (await add.count()) {
    await Promise.all([page.waitForResponse((r) => r.url().includes('/cart/add')), add.click()]);
    await page.goto('/checkout', { waitUntil: 'load' });
    await expectAccessible(page, 'checkout');
  }
});

test('the storefront has no WCAG 2.2 AA violations in the dark theme', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ baseURL, colorScheme: 'dark', locale: 'uk-UA' });
  const page = await context.newPage();
  for (const route of ['/', '/catalog', '/account/login']) {
    await page.goto(route, { waitUntil: 'domcontentloaded' });
    await expectAccessible(page, 'dark ' + route);
  }
  await context.close();
});
