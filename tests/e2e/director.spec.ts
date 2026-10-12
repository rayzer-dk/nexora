import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: the store director proposes up to three actions a day; the sale price can be applied and undone.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

const sql = (q: string): string => execFileSync('php', ['bin/console', 'dbal:run-sql', q], { encoding: 'utf8', env: process.env });
const onSale = (): number => Number((sql('SELECT COUNT(*) AS n FROM mc_price WHERE compare_at_minor IS NOT NULL').match(/\b(\d+)\s*$/m) ?? ['0', '0'])[1]);

test('the director proposes actions, applies a sale price and undoes it', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates prices once.');
  test.setTimeout(300_000);
  page.setDefaultNavigationTimeout(120_000);
  sql('DELETE FROM mc_director_action');
  sql('UPDATE mc_price SET compare_at_minor=NULL');
  // the test shop is new: let its products be older than two months, so some of them count as not selling
  sql('UPDATE mc_product_variant SET created_at=LEAST(created_at, NOW() - INTERVAL 90 DAY)');
  await loginAdmin(page);

  try {
    await page.goto('/admin/director', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[action$="/director/settings"]');
    await form.locator('input[name="enabled"]').check();
    await form.locator('input[name="discount_percent"]').fill('15');
    await Promise.all([page.waitForResponse((r) => r.url().endsWith('/director/settings') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
    await page.goto('/admin/director', { waitUntil: 'domcontentloaded' });

    const cards = page.locator('[data-director-cards] [data-director-kind]');
    expect(await cards.count()).toBeGreaterThan(0);
    expect(await cards.count()).toBeLessThanOrEqual(3);

    // the list is the same on the dashboard
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-dash-director] [data-director-kind]')).toHaveCount(await cards.count());

    // a slow-movers proposal on three known products (what the rule would find on a shop with old, unsold products)
    sql('DELETE FROM mc_director_action');
    sql("INSERT INTO mc_director_action (store_id,day,kind,status,params,created_at) SELECT 1,UTC_DATE(),'slow_movers','proposed',JSON_OBJECT('count',3,'names',JSON_ARRAY('A','B','C'),'variant_ids',JSON_ARRAYAGG(id),'percent',15),NOW(6) FROM (SELECT id FROM mc_product_variant ORDER BY id LIMIT 3) t");
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    const slow = page.locator('[data-director-kind="slow_movers"]').first();
    await expect(slow).toBeVisible();
    expect(onSale()).toBe(0);
    await Promise.all([page.waitForURL(/\/admin$/), slow.locator('[data-director-apply]').click()]);
    await expect(page.locator('[data-director-kind="slow_movers"]').first()).toHaveAttribute('data-director-status', 'applied');
    const discounted = onSale();
    expect(discounted).toBeGreaterThanOrEqual(3);
    expect(sql('SELECT COUNT(*) AS n FROM mc_price WHERE compare_at_minor IS NOT NULL AND amount_minor>=compare_at_minor')).toMatch(/\b0\s*$/m);

    // applying twice does nothing more; undo gives the old prices back
    await Promise.all([page.waitForURL(/\/admin$/), page.locator('[data-director-kind="slow_movers"] [data-director-undo]').first().click()]);
    await expect(page.locator('[data-director-kind="slow_movers"]').first()).toHaveAttribute('data-director-status', 'undone');
    expect(onSale()).toBe(0);
  } finally {
    await page.goto('/admin/director', { waitUntil: 'domcontentloaded' });
    await page.locator('form[action$="/director/settings"] input[name="enabled"]').uncheck();
    await Promise.all([page.waitForResponse((r) => r.url().endsWith('/director/settings') && r.request().method() === 'POST'), page.locator('form[action$="/director/settings"] button[type="submit"]').click()]);
  }
});
