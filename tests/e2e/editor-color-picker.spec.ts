import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoServerError, openProductTab, loginAdmin } from './helpers';

// Editor colour panel: spectrum + hue strip, HEX field with preview, one row of popular and one row of recent colours.

test.describe.configure({ retries: 0 });

async function openEditor(page: Page, theme: 'light' | 'dark' = 'light'): Promise<Locator> {
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.evaluate((value) => {
    try { localStorage.removeItem('mc_editor_recent_colors'); } catch { /* storage blocked */ }
    document.documentElement.dataset.theme = value;
  }, theme);
  await openProductTab(page, 'general');
  const editor = page.locator('.rich-editor').first();
  await editor.scrollIntoViewIfNeeded();
  await expect(editor.locator('.rich-editor__toolbar')).toBeVisible();
  return editor;
}

const nextFrames = (page: Page): Promise<void> => page.evaluate(() => new Promise<void>((done) => { requestAnimationFrame(() => requestAnimationFrame(() => done())); }));

async function selectSomeText(editor: Locator, page: Page): Promise<void> {
  const content = editor.locator('.rich-editor__content');
  // the editor restores focus a frame after a colour is applied: let that settle before selecting
  await nextFrames(page);
  await content.click();
  if ((await content.innerText()).trim() === '') await page.keyboard.type('Colour sample');
  await expect(async () => {
    await content.evaluate((node) => {
      const range = document.createRange();
      range.selectNodeContents(node);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
    });
    await nextFrames(page);
    expect(await page.evaluate(() => String(window.getSelection()))).toContain('Colour sample');
  }).toPass({ timeout: 5000 });
}

const textButton = (editor: Locator) => editor.locator('.rich-editor__colorbtn').first();
const bgButton = (editor: Locator) => editor.locator('.rich-editor__colorbtn').nth(1);
const panelOf = (editor: Locator) => editor.locator('.rich-editor__colorpanel');

async function applyHex(editor: Locator, value: string): Promise<void> {
  const hex = panelOf(editor).locator('.rich-editor__hex');
  await hex.fill(value);
  await hex.press('Enter');
}

test('panel layout: spectrum, hue, HEX with preview, one popular row, one recent row, no colour button', async ({ page }) => {
  await loginAdmin(page);
  const editor = await openEditor(page);
  await selectSomeText(editor, page);
  await textButton(editor).click();
  const panel = panelOf(editor);
  await expect(panel).toBeVisible();
  await expect(panel.locator('.rich-editor__plane')).toBeVisible();
  await expect(panel.locator('.rich-editor__hue')).toBeVisible();
  await expect(panel.locator('.rich-editor__hexpreview')).toBeVisible();
  await expect(panel.locator('.rich-editor__hex')).toBeVisible();
  await expect(panel.locator('.rich-editor__hexok')).toHaveText('Застосувати');
  await expect(panel.locator('.rich-editor__swatchrow--popular .rich-editor__swatch')).toHaveCount(10);
  await expect(panel.locator('.rich-editor__swatchrow--recent')).toContainText('Тут з\'являться останні кольори');
  await expect(panel.getByRole('button', { name: 'Без кольору' })).toBeVisible();
  // the old 24-swatch grid and the native colour input are gone
  await expect(panel.locator('.rich-editor__swatchgrid, input[type="color"]')).toHaveCount(0);
  await expect(panel.locator('.rich-editor__swatch')).toHaveCount(10);
});

test('HEX applies to the selection with Enter and the button; bad values are flagged and not applied', async ({ page }) => {
  await loginAdmin(page);
  const editor = await openEditor(page);
  await selectSomeText(editor, page);
  await textButton(editor).click();
  const panel = panelOf(editor);
  const hex = panel.locator('.rich-editor__hex');

  await hex.fill('zzz');
  await expect(hex).toHaveClass(/is-invalid/);
  await hex.press('Enter');
  await panel.locator('.rich-editor__hexok').click();
  await expect(panel).toBeVisible();
  await expect(editor.locator('.rich-editor__content span[style*="color"]')).toHaveCount(0);

  await hex.fill('#12345');
  await panel.locator('.rich-editor__hexok').click();
  await expect(hex).toHaveClass(/is-invalid/);
  await expect(editor.locator('.rich-editor__content span[style*="color"]')).toHaveCount(0);

  // short form without "#": Enter applies, the panel closes
  await hex.fill('f00');
  await expect(hex).not.toHaveClass(/is-invalid/);
  await expect(panel.locator('.rich-editor__hexfill')).toHaveCSS('background-color', 'rgb(255, 0, 0)');
  await hex.press('Enter');
  await expect(panel).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="color"]').first()).toHaveCSS('color', 'rgb(255, 0, 0)');

  // long form with "#" through the button
  await selectSomeText(editor, page);
  await textButton(editor).click();
  await panel.locator('.rich-editor__hex').fill('#0a64c8');
  await panel.locator('.rich-editor__hexok').click();
  await expect(panel).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="color"]').first()).toHaveCSS('color', 'rgb(10, 100, 200)');
});

test('spectrum works with mouse and keyboard, HEX and preview follow; hue strip changes the spectrum colour', async ({ page }) => {
  await loginAdmin(page);
  const editor = await openEditor(page);
  await selectSomeText(editor, page);
  await bgButton(editor).click();
  const panel = panelOf(editor);
  const plane = panel.locator('.rich-editor__plane');
  const hue = panel.locator('.rich-editor__hue');
  const hex = panel.locator('.rich-editor__hex');
  const fill = panel.locator('.rich-editor__hexfill');

  // hue: click a third of the way -> green; the plane takes the pure hue, its top-right corner is that colour
  const hueBox = (await hue.boundingBox())!;
  await page.mouse.click(hueBox.x + hueBox.width / 3, hueBox.y + hueBox.height / 2);
  await expect.poll(async () => (await plane.evaluate((node) => getComputedStyle(node).backgroundColor)).replace(/\s/g, '')).toMatch(/^rgb\((\d|[1-9]\d),(25[0-5]|24\d),(\d|[1-9]\d)\)$/);
  const box = (await plane.boundingBox())!;
  await page.mouse.click(box.x + box.width - 1, box.y + 1);
  const toRgb = (value: string): string => { const n = parseInt(value.slice(1), 16); return `rgb(${n >> 16}, ${(n >> 8) & 255}, ${n & 255})`; };
  const corner = await hex.inputValue();
  expect(parseInt(corner.slice(3, 5), 16)).toBeGreaterThanOrEqual(240);
  expect(parseInt(corner.slice(1, 3), 16)).toBeLessThanOrEqual(30);
  await expect(fill).toHaveCSS('background-color', toRgb(corner));

  // drag to the bottom-left corner -> black
  await page.mouse.move(box.x + box.width - 2, box.y + 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 4 });
  await page.mouse.move(box.x - 30, box.y + box.height + 30, { steps: 4 }); // outside the square: the pointer is captured and clamped to the corner
  await page.mouse.up();
  await expect(hex).toHaveValue('#000000');

  // keyboard: arrows move the pointer (right = more saturation, up = brighter)
  await plane.focus();
  await page.keyboard.press('Shift+ArrowUp');
  await page.keyboard.press('Shift+ArrowUp');
  const afterUp = await hex.inputValue();
  expect(afterUp).not.toBe('#000000');
  await page.keyboard.press('Shift+ArrowRight');
  await page.keyboard.press('Shift+ArrowRight');
  const afterRight = await hex.inputValue();
  expect(afterRight).not.toBe(afterUp);
  expect(afterRight).toMatch(/^#([0-9a-f]{2}){3}$/);
  await hue.focus();
  await page.keyboard.press('Shift+ArrowRight');
  await expect(hex).not.toHaveValue(afterRight);

  // typing a HEX moves the pointer too: the spectrum shows the typed colour's hue
  await hex.fill('#0000ff');
  await expect(plane).toHaveCSS('background-color', 'rgb(0, 0, 255)');
  await panel.locator('.rich-editor__hexok').click();
  await expect(panel).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="background-color"]').first()).toHaveCSS('background-color', 'rgb(0, 0, 255)');
});

test('popular and recent rows: recent is kept per kind, newest first, at most ten; "no colour" clears; Esc and outside click close', async ({ page }) => {
  await loginAdmin(page);
  const editor = await openEditor(page);
  await selectSomeText(editor, page);

  // text: apply twelve colours through HEX, only ten stay in the recent row, newest first
  for (let index = 0; index < 12; index += 1) {
    await textButton(editor).click();
    await applyHex(editor, `#${(index + 16).toString(16)}0000`);
    await selectSomeText(editor, page);
  }
  await textButton(editor).click();
  const recent = panelOf(editor).locator('.rich-editor__swatchrow--recent .rich-editor__swatch');
  await expect(recent).toHaveCount(10);
  await expect(recent.first()).toHaveAttribute('aria-label', '#1b0000');
  const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('mc_editor_recent_colors') ?? '{}') as { text?: string[]; bg?: string[] });
  expect(stored.text).toHaveLength(10);
  expect(stored.bg ?? []).toHaveLength(0);

  // a recent swatch applies at once
  await recent.nth(1).click();
  await expect(panelOf(editor)).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="color"]').first()).toHaveCSS('color', 'rgb(26, 0, 0)');

  // background has its own, empty, recent row
  await selectSomeText(editor, page);
  await bgButton(editor).click();
  await expect(panelOf(editor).locator('.rich-editor__swatchrow--recent')).toContainText('Тут з\'являться останні кольори');

  // "no colour" removes the background only; Esc closes without changes
  await applyHex(editor, '#ffee00');
  await expect(editor.locator('.rich-editor__content span[style*="background-color"]').first()).toBeVisible();
  await selectSomeText(editor, page);
  await bgButton(editor).click();
  await panelOf(editor).getByRole('button', { name: 'Без кольору' }).click();
  await expect(panelOf(editor)).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="background-color"]')).toHaveCount(0);
  await expect(editor.locator('.rich-editor__content span[style*="color"]').first()).toBeVisible();

  await textButton(editor).click();
  await expect(panelOf(editor)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(panelOf(editor)).toHaveCount(0);
  await bgButton(editor).click();
  await expect(panelOf(editor)).toBeVisible();
  await panelOf(editor).locator('.rich-editor__hex').focus();
  await page.keyboard.press('Escape');
  await expect(panelOf(editor)).toHaveCount(0);
  await textButton(editor).click();
  await expect(panelOf(editor)).toBeVisible();
  await page.locator('h1').first().click();
  await expect(panelOf(editor)).toHaveCount(0);
});

for (const viewport of [{ name: 'desktop 1440', width: 1440, height: 1000 }, { name: 'mobile 390', width: 390, height: 844 }]) {
  for (const theme of ['light', 'dark'] as const) {
    test(`panel stays inside the editor and the screen: ${viewport.name}, ${theme} admin theme`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await loginAdmin(page);
      const editor = await openEditor(page, theme);
      await selectSomeText(editor, page);
      for (const button of [textButton(editor), bgButton(editor)]) {
        await button.click();
        const panel = panelOf(editor);
        await expect(panel).toBeVisible();
        await panel.evaluate((node) => node.scrollIntoView({ block: 'nearest' }));
        const box = (await panel.boundingBox())!;
        const frame = (await editor.boundingBox())!;
        expect(box.x, 'panel left edge').toBeGreaterThanOrEqual(Math.max(frame.x, 0) - 1);
        expect(box.x + box.width, 'panel right edge').toBeLessThanOrEqual(Math.min(frame.x + frame.width, viewport.width) + 1);
        const swatches = await panel.locator('.rich-editor__swatch').count();
        expect(swatches).toBe(10);
        const widths = await panel.locator('.rich-editor__swatch').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().width));
        expect(Math.min(...widths), 'swatch is big enough to hit').toBeGreaterThanOrEqual(14);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, 'no horizontal scroll').toBeLessThanOrEqual(1);
        // readable in the theme: the panel is not the same colour as its text
        const colours = await panel.evaluate((node) => ({ bg: getComputedStyle(node).backgroundColor, fg: getComputedStyle(node).color }));
        expect(colours.bg).not.toBe(colours.fg);
        await page.screenshot({ path: testInfo.outputPath(`panel-${viewport.width}-${theme}.png`) });
        await page.keyboard.press('Escape');
        await expect(panel).toHaveCount(0);
      }
    });
  }
}
