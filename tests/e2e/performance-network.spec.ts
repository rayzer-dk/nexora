import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

test('measure real routes on desktop, mobile and throttled network', async ({ browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop' || process.env.E2E_PERFORMANCE !== '1', 'Dedicated Chromium network audit');
  test.setTimeout(360_000);
  const discovery = await browser.newContext({ baseURL });
  const seed = await discovery.newPage();
  await seed.goto('/catalog');
  const product = await seed.locator('[data-product-card] h2 a').first().getAttribute('href');
  expect(product).toBeTruthy();
  const routes = ['/', '/catalog', product!, '/cart'];
  await discovery.close();
  const results: unknown[] = [];
  for (const profile of [{name:'desktop', width:1440, height:1000, latency:0, down:-1, up:-1}, {name:'4g', width:390, height:844, latency:80, down:500000, up:125000}, {name:'slow-3g', width:390, height:844, latency:400, down:50000, up:50000}]) {
    for (const route of routes) {
      const context = await browser.newContext({ baseURL, viewport:{width:profile.width,height:profile.height}, serviceWorkers:'block', reducedMotion:'reduce' });
      try {
        await context.addInitScript(() => {
          const metrics = {lcp:0, cls:0};
          Object.defineProperty(window, '__auditMetrics', {value:metrics});
          new PerformanceObserver(list => { for(const entry of list.getEntries()) metrics.lcp=entry.startTime; }).observe({type:'largest-contentful-paint',buffered:true});
          new PerformanceObserver(list => { for(const entry of list.getEntries() as unknown as Array<{hadRecentInput:boolean;value:number}>) if(!entry.hadRecentInput) metrics.cls+=entry.value; }).observe({type:'layout-shift',buffered:true});
        });
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:profile.latency,downloadThroughput:profile.down,uploadThroughput:profile.up});
        for (const phase of ['cold','warm']) {
          const response = await page.goto(route,{waitUntil:'load',timeout:45000});
          expect(response?.ok(), route).toBeTruthy();
          await expect.poll(() => page.evaluate(() => (window as unknown as {__auditMetrics:{lcp:number}}).__auditMetrics.lcp),{timeout:10000}).toBeGreaterThan(0);
          const metrics = await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
            const resources = performance.getEntriesByType('resource') as PerformanceResourceTiming[];
            return {...(window as unknown as {__auditMetrics:{lcp:number;cls:number}}).__auditMetrics,ttfb:nav.responseStart-nav.startTime,load:nav.loadEventEnd||nav.duration,largestResources:resources.slice().sort((a,b)=>b.transferSize-a.transferSize).slice(0,6).map(r=>({path:new URL(r.name).pathname,bytes:r.transferSize,type:r.initiatorType})),bytes:nav.transferSize+resources.reduce((n,r)=>n+r.transferSize,0),requests:resources.length,overflow:document.documentElement.scrollWidth>innerWidth+2};
          });
          results.push({profile:profile.name,route,phase,...metrics});
          expect.soft(metrics.overflow,profile.name+' '+route).toBe(false);
          expect.soft(metrics.cls,profile.name+' '+route).toBeLessThan(0.1);
        }
      } finally { await context.close(); }
    }
  }
  console.log('PERFORMANCE_MEASUREMENTS '+JSON.stringify(results));
  await testInfo.attach('performance-network.json',{body:JSON.stringify(results,null,2),contentType:'application/json'});
});

test('Workbox caches real assets and serves only anonymous offline navigation', async ({page,browser,baseURL}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop' || process.env.E2E_PERFORMANCE !== '1', 'Dedicated isolated Workbox audit');
  test.setTimeout(90000);
  await loginAdmin(page);
  const settings='/admin/appearance/storefront';
  await page.goto(settings);
  const previous = await page.locator('[name="brand_workbox"]').isChecked();
  const set = async (enabled:boolean) => {
    await page.goto(settings);
    await page.locator('[name="brand_workbox"]').setChecked(enabled);
    await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes(settings)),page.locator('form:has([name="brand_workbox"]) button[type="submit"]').first().click()]);
    await page.waitForLoadState('domcontentloaded');
  };
  const context=await browser.newContext({baseURL});
  try {
    await set(true);
    const shop=await context.newPage();
    await shop.goto('/');
    await shop.evaluate(async()=>{await navigator.serviceWorker.ready;});
    await shop.reload({waitUntil:'load'});
    await expect.poll(()=>shop.evaluate(()=>!!navigator.serviceWorker.controller)).toBe(true);
    await expect.poll(()=>shop.evaluate(async()=>{const names=await caches.keys();for(const name of names.filter(n=>n.startsWith('nx-workbox-build-'))){if((await (await caches.open(name)).keys()).length>0)return true;}return false;})).toBe(true);
    await shop.goto('/catalog');
    await shop.goto('/cart');
    const keys=await shop.evaluate(async()=>{const result:string[]=[];for(const name of (await caches.keys()).filter(n=>n.startsWith('nx-workbox-'))){for(const req of await (await caches.open(name)).keys())result.push(new URL(req.url).pathname);}return result;});
    expect(keys.some(k=>/^\/(?:cart|checkout|account|admin|api)(?:\/|$)/.test(k))).toBe(false);
    expect(keys).not.toContain('/catalog');
    await context.setOffline(true);
    const offline=await shop.goto('/catalog');
    expect(offline?.ok()).toBeTruthy();
    expect(await shop.content()).toContain('noindex');
    await expect(shop.goto('/cart')).rejects.toThrow();
    await context.setOffline(false);
    await set(false);
    await shop.goto('/');
    await shop.evaluate(async()=>{const reg=await navigator.serviceWorker.getRegistration('/');await reg?.update();});
    await shop.close();
  } finally { await context.close(); await set(previous); }
});
