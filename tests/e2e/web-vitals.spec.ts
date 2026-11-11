import { expect, test } from '@playwright/test';

// Lab budgets: CLS is machine independent (the Google limit of 0.1); LCP gets 4 s here because a CI runner is slower than a real phone.
const pages = ['/', '/catalog', '/cart'];

for (const route of pages) {
  test(`${route} keeps layout shift under 0.1 and paints its largest element in time`, async ({ page }) => {
    await page.addInitScript(() => {
      const w = window as unknown as { __cwv: { lcp: number; cls: number } };
      w.__cwv = { lcp: 0, cls: 0 };
      new PerformanceObserver((list) => { for (const e of list.getEntries()) w.__cwv.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver((list) => { for (const e of list.getEntries() as unknown as Array<{ hadRecentInput: boolean; value: number }>) if (!e.hadRecentInput) w.__cwv.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    });
    await page.goto(route, { waitUntil: 'load' });
    await page.waitForTimeout(1500);
    const m = await page.evaluate(() => (window as unknown as { __cwv: { lcp: number; cls: number } }).__cwv);
    expect(m.cls, 'CLS').toBeLessThan(0.1);
    expect(m.lcp, 'LCP ms').toBeLessThan(4000);
  });
}

test('the first catalog cards load at once and the rest lazily', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const modes = await page.locator('.catalog-card img').evaluateAll((imgs) => imgs.slice(0, 8).map((i) => (i as HTMLImageElement).loading));
  expect(modes.slice(0, 2)).toEqual(['eager', 'eager']);
  expect(modes.slice(5)).toContain('lazy');
});

test('the vitals endpoint takes a sample, drops a wrong one and shows nothing back', async ({ request }) => {
  const ok = await request.post('/rum/vitals', { data: { p: 'catalog', d: 'mobile', m: { LCP: 1800, CLS: 0.01, INP: 90 } } });
  expect(ok.status()).toBe(204);
  const wrong = await request.post('/rum/vitals', { data: { p: '../x', d: 'mobile', m: { LCP: 1 } } });
  expect(wrong.status()).toBe(204);
  const big = await request.post('/rum/vitals', { data: { p: 'home', d: 'mobile', m: { LCP: 1 }, pad: 'x'.repeat(2000) } });
  expect(big.status()).toBe(413);
});
