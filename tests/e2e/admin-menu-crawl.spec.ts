import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Opens every link of the admin left menu (the nav rendered from themes/default/templates/admin/catalog/_shell.html.twig)
// as a signed-in admin: HTTP status below 400, no server-error text on the page, no uncaught exception.
test('every left-menu link of the admin opens without an error', async ({ page }) => {
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(480_000);

  const exceptions: string[] = [];
  let current = '/admin';
  page.on('pageerror', (error) => exceptions.push(`${current}: ${error.message.slice(0, 200)}`));

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const hrefs = await page.locator('nav.admin-nav a.admin-nav__link').evaluateAll((els) => els.map((el) => el.getAttribute('href') ?? ''));
  const links = [...new Set(hrefs.filter((href) => href.startsWith('/admin')))];
  expect(links.length, 'menu links found').toBeGreaterThan(30);

  const problems: string[] = [];
  for (const href of links) {
    current = href;
    const response = await page.goto(href, { waitUntil: 'load' }).catch((error) => { problems.push(`${href} navigation: ${String(error).slice(0, 120)}`); return null; });
    if (response === null) continue;
    if (response.status() >= 400) problems.push(`${href} HTTP ${response.status()}`);
    await page.waitForTimeout(150);
    try {
      await expectNoServerError(page);
    } catch {
      problems.push(`${href} shows server error text`);
    }
  }
  expect(problems, problems.join('\n')).toEqual([]);
  expect(exceptions, exceptions.join('\n')).toEqual([]);
});
