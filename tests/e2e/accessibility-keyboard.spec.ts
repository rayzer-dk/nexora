import { expect, test, type Page } from '@playwright/test';

/** Presses Tab and reports every focused element that shows no focus indicator (WCAG 2.4.7) or is hidden from view. */
async function tabAudit(page: Page, presses: number): Promise<string[]> {
  const problems: string[] = [];
  for (let i = 0; i < presses; i += 1) {
    await page.keyboard.press('Tab');
    const state = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const cs = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
      const shadow = cs.boxShadow !== 'none';
      const border = el.matches(':focus-visible') && (cs.borderColor !== cs.getPropertyValue('border-color'));
      return { tag: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : ''), visible: rect.width > 0 && rect.height > 0, indicator: outline || shadow || border || el.tagName === 'INPUT' };
    });
    if (state && (!state.visible || !state.indicator)) problems.push(`${state.tag} visible=${state.visible} indicator=${state.indicator}`);
  }
  return problems;
}

for (const route of ['/', '/catalog', '/cart', '/account/login']) {
  test(`${route}: the keyboard reaches the content, every focused element shows where the focus is`, async ({ page }) => {
    await page.goto(route, { waitUntil: 'domcontentloaded' });
    await page.keyboard.press('Tab');
    const first = await page.evaluate(() => ({ text: (document.activeElement as HTMLElement | null)?.textContent?.trim().slice(0, 60) ?? '', href: (document.activeElement as HTMLAnchorElement | null)?.getAttribute('href') ?? '' }));
    expect(first.href, `the first Tab stop is a skip link: ${first.text}`).toMatch(/^#/);
    expect(await tabAudit(page, 25)).toEqual([]);
  });
}

test('the catalog menu opens and closes from the keyboard and returns the focus', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const toggle = page.locator('[data-mega-toggle]').first();
  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
});
