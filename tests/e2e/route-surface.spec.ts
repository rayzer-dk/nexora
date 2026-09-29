import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

type RouterEntry = {
  path?: string;
  methods?: string[];
};

type RouteProbe = {
  path: string;
  status: number;
  body: string;
};

function staticGetPaths(prefix: 'admin' | 'public'): string[] {
  const raw = execFileSync('php', ['bin/console', 'debug:router', '--format=json'], {
    encoding: 'utf8',
    env: process.env,
  });
  const routes = JSON.parse(raw) as Record<string, RouterEntry>;
  const paths = new Set<string>();

  for (const [name, route] of Object.entries(routes)) {
    const path = String(route.path || '');
    const methods = Array.isArray(route.methods) ? route.methods : [];
    if (!path.startsWith('/') || path.includes('{') || /logout/i.test(name + path)) continue;
    if (methods.length && !methods.includes('GET') && !methods.includes('HEAD')) continue;
    if (prefix === 'admin' && !path.startsWith('/admin')) continue;
    if (prefix === 'public' && path.startsWith('/admin')) continue;
    if (path.startsWith('/_')) continue;
    paths.add(path);
  }

  return [...paths].sort();
}

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function probeRoutes(page: Page, paths: string[]): Promise<RouteProbe[]> {
  return page.evaluate(async (routePaths) => {
    const results: RouteProbe[] = [];
    for (const path of routePaths) {
      try {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 7000);
        const response = await fetch(path, {
          method: 'GET',
          credentials: 'same-origin',
          redirect: 'follow',
          signal: controller.signal,
          headers: { 'X-Nexora-Route-Audit': '1' },
        });
        window.clearTimeout(timer);
        const body = (await response.text()).slice(0, 1000);
        results.push({ path, status: response.status, body });
      } catch (error) {
        results.push({ path, status: 0, body: error instanceof Error ? error.message : String(error) });
      }
    }
    return results;
  }, paths);
}

function failuresFor(results: RouteProbe[]): string[] {
  const failures: string[] = [];
  for (const result of results) {
    if (result.status === 0 || result.status >= 500) {
      failures.push(`${result.path}: HTTP ${result.status}`);
      continue;
    }
    if (/Internal Server Error|Fatal error|Uncaught .*Exception|SQLSTATE\[/i.test(result.body)) {
      failures.push(`${result.path}: rendered runtime error`);
    }
  }
  return failures;
}

test('all static admin GET routes are live after installation', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full route audit runs once per installed CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const results = await probeRoutes(page, staticGetPaths('admin'));
  const failures = failuresFor(results);
  expect(failures, failures.join('\n')).toEqual([]);
});

test('all static public GET routes avoid server errors', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full route audit runs once per installed CI database.');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const results = await probeRoutes(page, staticGetPaths('public'));
  const failures = failuresFor(results);
  expect(failures, failures.join('\n')).toEqual([]);
});
