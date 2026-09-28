import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoBrokenImages, expectNoServerError } from './helpers';

type RouteDump = Record<string, { path: string; method: string }>;

function routes(): RouteDump {
  return JSON.parse(execFileSync('php', ['bin/console', 'debug:router', '--format=json'], {
    encoding: 'utf8',
    env: process.env,
  })) as RouteDump;
}

function staticHtmlRoutes(prefix: string): Array<{ name: string; path: string }> {
  return Object.entries(routes())
    .map(([name, route]) => ({ name, path: route.path.replaceAll('\\/', '/'), method: route.method || '' }))
    .filter((route) =>
      route.path.startsWith(prefix) &&
      route.method.split('|').includes('GET') &&
      !route.path.includes('{') &&
      !route.path.endsWith('.json') &&
      !route.path.endsWith('.csv') &&
      !route.path.endsWith('.pdf') &&
      route.path !== '/admin/login' &&
      route.path !== '/admin/logout'
    )
    .map(({ name, path }) => ({ name, path }))
    .sort((a, b) => a.path.localeCompare(b.path));
}

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
}

test('every static admin HTML route renders after authentication', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full admin route sweep runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const failures: string[] = [];
  let runtimeErrors: string[] = [];
  page.on('pageerror', (error) => runtimeErrors.push(error.message));

  for (const route of staticHtmlRoutes('/admin')) {
    runtimeErrors = [];
    const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    if (status >= 500 || status === 0) {
      failures.push(`${route.name} ${route.path}: HTTP ${status}`);
      continue;
    }
    try {
      const contentType = response?.headers()['content-type'] || '';
      if (/text\/html|application\/xhtml\+xml/i.test(contentType)) {
        await expectNoServerError(page);
        await expect(page.locator('body')).not.toContainText('Call to undefined method');
        await expect(page.locator('body')).not.toContainText('Uncaught PHP Exception');
        await expect(page.locator('body')).not.toContainText('SQLSTATE[');
        await expectNoBrokenImages(page);
        if (runtimeErrors.length > 0) {
          failures.push(`${route.name} ${route.path}: JS ${runtimeErrors.join(' | ')}`);
        }
      }
    } catch (error) {
      failures.push(`${route.name} ${route.path}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  expect(failures, failures.join('\n')).toEqual([]);
});

test('every static public HTML route renders with no runtime failure', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full public route sweep runs once per CI database.');
  const excluded = new Set([
    '/admin',
    '/account',
    '/account/login',
    '/account/register',
    '/account/recover',
    '/account/reset',
    '/account/verification',
    '/checkout',
    '/setup.php',
    '/install',
    '/install/finish',
  ]);
  const failures: string[] = [];
  let runtimeErrors: string[] = [];
  page.on('pageerror', (error) => runtimeErrors.push(error.message));

  for (const route of staticHtmlRoutes('/').filter((route) => !route.path.startsWith('/admin') && !route.path.startsWith('/api/') && !excluded.has(route.path))) {
    runtimeErrors = [];
    const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    if (status >= 500 || status === 0) {
      failures.push(`${route.name} ${route.path}: HTTP ${status}`);
      continue;
    }
    try {
      const contentType = response?.headers()['content-type'] || '';
      if (/text\/html|application\/xhtml\+xml/i.test(contentType)) {
        await expectNoServerError(page);
        await expect(page.locator('body')).not.toContainText('Call to undefined method');
        await expect(page.locator('body')).not.toContainText('Uncaught PHP Exception');
        await expect(page.locator('body')).not.toContainText('SQLSTATE[');
        await expectNoBrokenImages(page);
        if (runtimeErrors.length > 0) {
          failures.push(`${route.name} ${route.path}: JS ${runtimeErrors.join(' | ')}`);
        }
      }
    } catch (error) {
      failures.push(`${route.name} ${route.path}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  expect(failures, failures.join('\n')).toEqual([]);
});


test('rendered admin links and form actions resolve instead of pointing to dead routes', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin interaction route contract runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const pages = staticHtmlRoutes('/admin');
  const targets = new Map<string, Set<string>>();

  for (const route of pages) {
    const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });
    if ((response?.status() ?? 500) >= 500) continue;

    const discovered = await page.locator('a[href],form[action]').evaluateAll((nodes) => nodes.map((node) => {
      const isForm = node instanceof HTMLFormElement;
      const raw = isForm ? node.getAttribute('action') : node.getAttribute('href');
      const method = isForm ? (node.getAttribute('method') || 'GET').toUpperCase() : 'GET';
      return { raw: raw || '', method };
    }));

    for (const item of discovered) {
      if (!item.raw || item.raw === '#' || item.raw.startsWith('javascript:')) continue;
      let url: URL;
      try { url = new URL(item.raw, page.url()); } catch { continue; }
      if (url.origin !== new URL(page.url()).origin || !url.pathname.startsWith('/admin')) continue;
      const key = url.pathname + url.search;
      if (!targets.has(key)) targets.set(key, new Set());
      targets.get(key)!.add(item.method);
    }
  }

  const failures: string[] = [];
  for (const [target, methods] of targets) {
    // GET against a POST-only action should return 405, which proves that the route exists without mutating data.
    const response = await page.request.get(target, { maxRedirects: 0 });
    const status = response.status();
    if (status === 404 || status >= 500) {
      failures.push(`${target} [rendered methods: ${[...methods].join(',')}]: HTTP ${status}`);
    }
  }

  expect(failures, failures.join('\n')).toEqual([]);
});
