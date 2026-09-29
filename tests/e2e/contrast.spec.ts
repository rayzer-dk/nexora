import AxeBuilder from '@axe-core/playwright';
import { writeFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';

test.describe.configure({ retries: 0 });
test.setTimeout(600_000);

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

async function setScheme(page: Page, scheme: 'light' | 'dark'): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.locator('select[name="theme_color_scheme"]').selectOption(scheme);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(select[name="theme_color_scheme"]) button[type="submit"]').first().click(),
  ]);
}

let scanned: string[] = [];

async function scan(page: Page, path: string, out: string[]): Promise<void> {
  const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() >= 400) return;
  scanned.push(path);
  await page.waitForLoadState('networkidle').catch(() => undefined);
  const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
  for (const violation of results.violations) {
    for (const node of violation.nodes) {
      const data = (node.any[0]?.data ?? {}) as { fgColor?: string; bgColor?: string; contrastRatio?: number };
      out.push(`${path} | ${node.target.join(' ')} | fg ${data.fgColor} bg ${data.bgColor} ratio ${data.contrastRatio}`);
    }
  }
}

async function storefrontRoutes(page: Page): Promise<string[]> {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const product = await page.locator('[data-product-card] a.catalog-card__media').first().getAttribute('href');
  const links = await page.locator('footer a[href^="/"], header a[href^="/"]').evaluateAll((els) =>
    [...new Set(els.map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''))].filter((h) => !h.startsWith('/admin') && !h.includes('#')).slice(0, 25));
  return [...new Set(['/', '/catalog', '/cart', '/compare', '/account/login', '/checkout', '/withdrawal', '/accessibility', product ?? '/', ...links])];
}

async function adminRoutes(page: Page): Promise<string[]> {
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await page.locator('aside a[href^="/admin"]').first().waitFor({ timeout: 20_000 });
  const links = await page.locator('aside a[href^="/admin"]').evaluateAll((els) =>
    [...new Set(els.map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''))]);
  return [...new Set(['/admin', '/admin/appearance/storefront', '/admin/system/extensions', '/admin/account/security', ...links])].slice(0, 60);
}

for (const scheme of ['light', 'dark'] as const) {
  test(`contrast: storefront and admin in ${scheme} scheme`, async ({ browser, page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once per CI database.');
    test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
    await loginAdmin(page);
    await setScheme(page, scheme);
    const findings: string[] = [];
    scanned = [];
    const context = await (browser as Browser).newContext({ colorScheme: scheme, baseURL: testInfo.project.use.baseURL });
    let adminContext: Awaited<ReturnType<Browser['newContext']>> | null = null;
    try {
      const shop = await context.newPage();
      for (const route of await storefrontRoutes(shop)) await scan(shop, route, findings);
      await shop.close();
      // The admin session lives in its own browser context so the storefront cookies never interfere with it.
      adminContext = await (browser as Browser).newContext({ colorScheme: scheme, baseURL: testInfo.project.use.baseURL });
      const admin = await adminContext.newPage();
      await loginAdmin(admin);
      for (const route of await adminRoutes(admin)) await scan(admin, route, findings);
    } finally {
      await adminContext?.close();
      await context.close();
      await setScheme(page, 'light');
    }
    writeFileSync(`/tmp/contrast-${scheme}.txt`, findings.join('\n'));
    writeFileSync(`/tmp/contrast-${scheme}-routes.txt`, scanned.join('\n'));
    expect(scanned.filter((r) => r.startsWith('/admin')).length, 'admin pages scanned').toBeGreaterThan(20);
    expect(scanned.filter((r) => !r.startsWith('/admin')).length, 'storefront pages scanned').toBeGreaterThan(12);
    expect(findings, findings.slice(0, 40).join('\n')).toEqual([]);
  });
}
