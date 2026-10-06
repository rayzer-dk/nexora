import { expect, test, type Page } from '@playwright/test';

// Walks real pages (storefront through the sitemap and links, admin through its menu plus record pages) and fails on anything a visitor
// would not see but a developer must: console errors, uncaught exceptions, failed or 4xx/5xx requests of this site, mobile sideways scroll.
const IGNORED_CONSOLE = [/favicon/i, /Blocked script execution in 'about:blank'/i, /Failed to load resource: the server responded with a status of (401|403|404)/i];

function watch(page: Page, problems: string[], origin: string, label: () => string): void {
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (IGNORED_CONSOLE.some((re) => re.test(text))) return;
    problems.push(`${label()} console: ${text.slice(0, 200)}`);
  });
  page.on('pageerror', (error) => problems.push(`${label()} exception: ${error.message.slice(0, 200)}`));
  page.on('requestfailed', (request) => {
    const failure = request.failure()?.errorText ?? '';
    if (/ERR_ABORTED|NS_BINDING_ABORTED/.test(failure)) return;
    if (request.url().startsWith(origin)) problems.push(`${label()} request failed: ${request.url()} ${failure}`);
  });
  page.on('response', (response) => {
    const url = response.url();
    if (!url.startsWith(origin) || response.status() < 400) return;
    if (/\/(favicon|apple-touch)/.test(url)) return;
    if (response.request().resourceType() === 'document' && response.url() === page.url()) return;
    problems.push(`${label()} HTTP ${response.status()}: ${url.replace(origin, '')}`);
  });
}

async function overflow(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

async function visit(page: Page, path: string, problems: string[], mobile: boolean): Promise<void> {
  const response = await page.goto(path, { waitUntil: 'load' }).catch((error) => { problems.push(`${path} navigation: ${String(error).slice(0, 120)}`); return null; });
  if (response === null) return;
  if (response.status() >= 500) problems.push(`${path} HTTP ${response.status()}`);
  await page.waitForTimeout(250);
  if (mobile && (await overflow(page))) problems.push(`${path} sideways scroll on a phone`);
}

for (const mobile of [false, true]) {
  test(`storefront pages have no console errors, failed requests${mobile ? ' or sideways scroll on a phone' : ''}`, async ({ browser, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'The sweep sets its own viewport.');
    test.setTimeout(300_000);
    const origin = new URL(baseURL ?? 'http://127.0.0.1:8000').origin;
    const context = await browser.newContext(mobile ? { viewport: { width: 390, height: 844 }, isMobile: true } : {});
    const page = await context.newPage();
    const problems: string[] = [];
    let current = '/';
    watch(page, problems, origin, () => current);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-consent-accept-all]').click({ timeout: 3000 }).catch(() => undefined);

    const paths = new Set<string>(['/', '/catalog', '/blog', '/forum', '/contact', '/about-us', '/shipping', '/returns', '/cart', '/account/login', '/account/register', '/catalog?q=test']);
    const sitemap = await page.request.get('/sitemap.xml').then((r) => r.text()).catch(() => '');
    const children = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]).filter((u) => u.endsWith('.xml') || u.includes('sitemap'));
    for (const child of children.slice(0, 6)) {
      const xml = await page.request.get(child.replace(origin, '').replace(/^https?:\/\/[^/]+/, '')).then((r) => r.text()).catch(() => '');
      for (const m of [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].slice(0, 12)) paths.add(m[1].replace(/^https?:\/\/[^/]+/, ''));
    }
    for (const m of [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].slice(0, 20)) if (!m[1].endsWith('.xml')) paths.add(m[1].replace(/^https?:\/\/[^/]+/, ''));

    for (const path of [...paths].slice(0, 90)) {
      current = path;
      await visit(page, path, problems, mobile);
    }
    await context.close();
    expect(problems, problems.join('\n')).toEqual([]);
  });
}

test('admin pages and record pages have no console errors or failed requests', async ({ browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin sweep runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(480_000);
  const origin = new URL(baseURL ?? 'http://127.0.0.1:8000').origin;
  const context = await browser.newContext();
  const page = await context.newPage();
  const problems: string[] = [];
  let current = '/admin';
  watch(page, problems, origin, () => current);
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const paths = new Set<string>(['/admin']);
  for (const start of ['/admin', '/admin/catalog/products', '/admin/orders', '/admin/commerce/customers', '/admin/content/blog']) {
    await page.goto(start, { waitUntil: 'domcontentloaded' });
    const hrefs = await page.locator('a[href^="/admin"]').evaluateAll((els) => els.map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''));
    for (const href of hrefs) {
      const clean = href.split('#')[0];
      if (!clean || /logout|delete|export|download|\/new-topic|\/rollback|\/send|\/run|\/clear|\/purge|\/disable|\/enable/.test(clean)) continue;
      paths.add(clean);
    }
  }
  for (const path of [...paths].slice(0, 220)) {
    current = path;
    await visit(page, path, problems, false);
  }
  await context.close();
  expect(problems, problems.join('\n')).toEqual([]);
});
