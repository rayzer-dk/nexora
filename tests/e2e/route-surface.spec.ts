import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

type RouterEntry = {
  path?: string;
  methods?: string[];
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

test('all static admin GET routes are live after installation', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full route audit runs once per installed CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  const failures: string[] = [];
  for (const path of staticGetPaths('admin')) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    if (status >= 500 || status === 0) failures.push(`${path}: HTTP ${status}`);
    try {
      await expectNoServerError(page);
    } catch {
      failures.push(`${path}: rendered server error`);
    }
  }
  expect(failures, failures.join('\n')).toEqual([]);
});

test('all static public GET routes avoid server errors', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full route audit runs once per installed CI database.');

  const failures: string[] = [];
  for (const path of staticGetPaths('public')) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    if (status >= 500 || status === 0) failures.push(`${path}: HTTP ${status}`);
    try {
      await expectNoServerError(page);
    } catch {
      failures.push(`${path}: rendered server error`);
    }
  }
  expect(failures, failures.join('\n')).toEqual([]);
});
