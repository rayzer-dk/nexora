import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoBrokenImages, expectNoServerError } from './helpers';

test.describe.configure({ timeout: 180_000, retries: 0 });

type RouteDump = Record<string, { path: string; method: string }>;

function routes(): RouteDump {
  return JSON.parse(execFileSync('php', ['bin/console', 'debug:router', '--format=json'], {
    encoding: 'utf8',
    env: process.env,
  })) as RouteDump;
}

/** An assertion message can embed the whole rendered page; keep a failed route to a few readable lines. */
function briefError(error: unknown): string {
  const message = error instanceof Error ? error.message : String(error);
  return message.split('\n').map((line) => line.trim()).filter(Boolean).slice(0, 4).join(' | ').slice(0, 400);
}

function escapeRegex(value: string): string {
  const special = '\\.^$*+?()[]{}|';
  let result = '';
  for (const char of value) {
    result += special.includes(char) ? '\\' + char : char;
  }
  return result;
}

function routePatterns(): Array<{ name: string; path: string; methods: Set<string>; regex: RegExp }> {
  return Object.entries(routes()).map(([name, route]) => {
    const path = route.path.replaceAll('\\/', '/');
    const methods = new Set((route.method || '').split('|').filter(Boolean));
    const pattern = path
      .split('/')
      .map((segment) => segment.includes('{') ? '[^/]+' : escapeRegex(segment))
      .join('/');
    return { name, path, methods, regex: new RegExp('^' + pattern + '$') };
  });
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
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
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
      failures.push(route.name + ' ' + route.path + ': HTTP ' + status);
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
          failures.push(route.name + ' ' + route.path + ': JS ' + runtimeErrors.join(' | '));
        }
      }
    } catch (error) {
      failures.push(route.name + ' ' + route.path + ': ' + briefError(error));
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
    // Guarded JSON endpoints intentionally return 404 without a token or feature flag.
    '/__health/core-update',
    '/.well-known/ucp',
    // The OAuth callback answers 404 while Google sign-in is not configured.
    '/account/login/google/callback',
  ]);
  const failures: string[] = [];
  let runtimeErrors: string[] = [];
  page.on('pageerror', (error) => runtimeErrors.push(error.message));

  for (const route of staticHtmlRoutes('/').filter((route) => !route.path.startsWith('/admin') && !route.path.startsWith('/api/') && !excluded.has(route.path))) {
    runtimeErrors = [];
    const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    if (status >= 500 || status === 0) {
      failures.push(route.name + ' ' + route.path + ': HTTP ' + status);
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
          failures.push(route.name + ' ' + route.path + ': JS ' + runtimeErrors.join(' | '));
        }
      }
    } catch (error) {
      failures.push(route.name + ' ' + route.path + ': ' + briefError(error));
    }
  }

  expect(failures, failures.join('\n')).toEqual([]);
});

test('rendered admin links and form actions resolve to declared router contracts', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin interaction route contract runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const pages = staticHtmlRoutes('/admin');
  const patterns = routePatterns();
  const failures: string[] = [];

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

      const matches = patterns.filter((candidate) =>
        candidate.regex.test(url.pathname) &&
        (candidate.methods.size === 0 || candidate.methods.has(item.method) || (item.method === 'GET' && candidate.methods.has('HEAD')))
      );
      if (matches.length === 0) {
        failures.push(route.path + ' renders ' + item.method + ' ' + url.pathname + ', but no router entry accepts it');
      }
    }
  }

  expect(failures, failures.join('\n')).toEqual([]);
});
