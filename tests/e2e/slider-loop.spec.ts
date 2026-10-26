import { expect, test } from '@playwright/test';

// Release 3.97.24: carousels go round without a jump back to the start.

const html = `<!doctype html><meta charset="utf-8"><style>
  body{margin:0;font:16px sans-serif}
  commerce-slider{display:block;width:400px}
  .commerce-carousel{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory}
  .commerce-carousel > div{flex:none;width:150px;height:80px;scroll-snap-align:start;background:#cde}
</style>
<commerce-slider id="s"><button data-slider-prev>prev</button><button data-slider-next>next</button>
<div class="commerce-carousel" data-slider-track>${[1, 2, 3, 4, 5, 6].map((n) => `<div data-n="${n}" id="slide-${n}">${n}</div>`).join('')}</div></commerce-slider>`;

async function leftmost(page: import('@playwright/test').Page): Promise<number> {
  return page.evaluate(() => {
    const track = document.querySelector('[data-slider-track]') as HTMLElement;
    const left = track.getBoundingClientRect().left;
    let best = 0; let bestDistance = Infinity;
    track.querySelectorAll(':scope > div').forEach((el) => {
      const d = Math.abs(el.getBoundingClientRect().left - left);
      if (d < bestDistance) { bestDistance = d; best = Number((el as HTMLElement).dataset.n); }
    });
    return best;
  });
}

test('the carousel keeps going forward and backward round the circle', async ({ page }) => {
  await page.setContent(html);
  await page.addScriptTag({ path: 'assets/storefront/slider.js' });
  await page.waitForFunction(() => document.querySelector('commerce-slider')?.hasAttribute('data-looping'), null, { timeout: 5000 });
  expect(await page.locator('[data-slider-clone]').count()).toBe(12);
  expect(await page.locator('[data-slider-clone][aria-hidden="true"]').count()).toBe(12);
  expect(await page.locator('#slide-1').count()).toBe(1); // ids are not duplicated

  const forward: number[] = [await leftmost(page)];
  for (let i = 0; i < 9; i++) {
    const previous = forward[forward.length - 1];
    await page.locator('[data-slider-next]').click();
    // Observe real movement instead of assuming all engines settle in exactly 650 ms.
    await expect.poll(() => leftmost(page), { timeout: 5_000 }).not.toBe(previous);
    await page.waitForTimeout(250);
    forward.push(await leftmost(page));
  }
  // every step moves ahead by a few slides (mod 6): never back to the start in one jump
  for (let i = 1; i < forward.length; i++) {
    const delta = (forward[i] - forward[i - 1] + 6) % 6;
    expect([1, 2, 3], `step ${i}: ${forward.join(',')}`).toContain(delta);
  }

  const backward: number[] = [forward[forward.length - 1]];
  for (let i = 0; i < 9; i++) {
    const previous = backward[backward.length - 1];
    await page.locator('[data-slider-prev]').click();
    await expect.poll(() => leftmost(page), { timeout: 5_000 }).not.toBe(previous);
    await page.waitForTimeout(250);
    backward.push(await leftmost(page));
  }
  for (let i = 1; i < backward.length; i++) {
    const delta = (backward[i - 1] - backward[i] + 6) % 6;
    expect([1, 2, 3], `back step ${i}: ${backward.join(',')}`).toContain(delta);
  }
  // the arrows never switch off
  expect(await page.locator('commerce-slider[data-at-start], commerce-slider[data-at-end]').count()).toBe(0);
});

test('data-loop="off" keeps a plain strip with ends', async ({ page }) => {
  await page.setContent(html.replace('<commerce-slider id="s">', '<commerce-slider id="s" data-loop="off">'));
  await page.addScriptTag({ path: 'assets/storefront/slider.js' });
  await page.waitForTimeout(900);
  expect(await page.locator('[data-slider-clone]').count()).toBe(0);
  await expect(page.locator('commerce-slider')).toHaveAttribute('data-at-start', '');
});
