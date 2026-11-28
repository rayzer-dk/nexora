import { expect, test } from '@playwright/test';

test('adaptive motion, navigation opt-in and rendering diagnostics', async ({browser,baseURL},testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop' || process.env.E2E_PERFORMANCE !== '1', 'Dedicated rendering audit');
  const reports: unknown[]=[];
  for(const low of [false,true]){
    const context=await browser.newContext({baseURL,viewport:{width:390,height:844},reducedMotion:'no-preference',extraHTTPHeaders:low?{'Save-Data':'on'}:{}});
    try{
      await context.addInitScript(({low})=>{
        Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
        Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
        Object.defineProperty(navigator,'connection',{get:()=>({saveData:low,effectiveType:low?'3g':'4g'})});
        localStorage.setItem('nx_motion_preference','full');
      },{low});
      const page=await context.newPage();
      await page.goto('/catalog',{waitUntil:'load'});
      await expect(page.locator('html')).toHaveAttribute('data-motion-budget',low?'low':'normal');
      const rule=await page.locator('#mc-navigation-transition').textContent();
      const supported=await page.evaluate(()=>typeof document.startViewTransition==='function');
      expect(Boolean(rule)).toBe(!low&&supported);
      const metrics=await page.evaluate(async()=>{
        const card=document.querySelector('.catalog-card');
        if(!card) throw new Error('Demo catalog empty');
        const times:number[]=[];
        const longTasks:number[]=[];
        const observer=PerformanceObserver.supportedEntryTypes.includes('longtask')?new PerformanceObserver(list=>{for(const entry of list.getEntries())longTasks.push(entry.duration);}):null;
        observer?.observe({entryTypes:['longtask']});
        document.dispatchEvent(new CustomEvent('mc:toast',{detail:{message:'Render audit',type:'success'}}));
        const start=performance.now();
        await new Promise<void>(resolve=>{const frame=(now:number)=>{times.push(now);if(now-start<700)requestAnimationFrame(frame);else resolve();};requestAnimationFrame(frame);});
        observer?.disconnect();
        const gaps=times.slice(1).map((t,i)=>t-times[i]).sort((a,b)=>a-b);
        return {frames:gaps.length,meanFps:gaps.length*1000/(times.at(-1)!-times[0]),p95FrameMs:gaps[Math.ceil(gaps.length*.95)-1],maxFrameMs:gaps.at(-1),longTasks};
      });
      reports.push({low,...metrics});
      expect(metrics.frames).toBeGreaterThan(0);
      await page.emulateMedia({reducedMotion:'reduce'});
      await expect(page.locator('#mc-navigation-transition')).toHaveText('');
      await page.goto('/cart');
      await expect(page.locator('html')).toHaveAttribute('data-motion-budget',low?'low':'normal');
    }finally{await context.close();}
  }
  console.log('MOTION_MEASUREMENTS '+JSON.stringify(reports));
  await testInfo.attach('motion-rendering.json',{body:JSON.stringify(reports,null,2),contentType:'application/json'});
});
