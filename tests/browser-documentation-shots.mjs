import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

// Visual review evidence only: real installed disposable PrestaShop, not
// generated mockups, not release-approved README screenshots.
// Never record URLs, auth cookies, raw tokens, or administrator credentials.
const adminUrl = process.env.ADMIN_URL;
const email = process.env.TEST_ADMIN_EMAIL;
const password = process.env.TEST_ADMIN_PASSWORD;
if (!adminUrl || !email || !password) {
  throw new Error('Disposable screenshot capture requires CI administrator fixtures');
}
const directory='tests/ui-screenshots';
mkdirSync(directory,{recursive:true});
const browser = await chromium.launch({headless:true});
const context = await browser.newContext({
  viewport:{width:1600,height:1000},
  deviceScaleFactor:2,
  reducedMotion:'reduce',
});
const page = await context.newPage();
let captured=0;
const shot=async (name,selector) => {
  const target=page.locator(selector);
  await target.waitFor({state:'visible',timeout:20000});
  await target.scrollIntoViewIfNeeded();
  await page.screenshot({
    path:join(directory,name+'.png'),fullPage:false,
    animations:'disabled',
  });
  captured++;
  console.log('Captured real disposable interface: '+name+'.png');
  // Structured, bounded log chunks allow the reviewer to inspect and
  // approve the exact high-resolution binary before adding it to README.
  // These pages contain synthetic CI users and data only. Never log an
  // environment value, session cookie, URL, or live credential.
  if (['01-configure-map-workspace','03-grouped-operating-settings',
    '05-public-quote-check'].includes(name)) {
    const encoded=readFileSync(join(directory,name+'.png')).toString('base64');
    const size=24000;
    const count=Math.ceil(encoded.length/size);
    for (let i=0;i<count;i++) {
      console.log('GRTRANS_SCREENSHOT_B64|'+name+'|'+i+'|'+count+'|'
        +encoded.slice(i*size,(i+1)*size));
    }
  }
};
try {
  await page.goto('http://localhost:8080/admin-dev/',{
    waitUntil:'domcontentloaded',timeout:45000,
  });
  await page.locator('input[name="email"], input[type="email"]').first().fill(email);
  await page.locator('input[name="passwd"], input[type="password"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto(adminUrl,{waitUntil:'domcontentloaded',timeout:45000});
  await page.getByText('Greek public transport routes',{exact:false}).first().waitFor();
  await shot('01-configure-map-workspace','#grtrans-map-workspace');
  await page.getByRole('button',{name:'Place station',exact:true}).click();
  await shot('02-map-station-draft','#grtrans-network-editor');
  // Do not save any draft: these screenshots must never mutate records.
  await page.goto(adminUrl,{waitUntil:'domcontentloaded',timeout:45000});
  await shot('03-grouped-operating-settings','#grtrans-network-settings');
  await shot('04-quote-simulator','#grtrans-simulator');
  await page.goto('http://localhost:8080/index.php?fc=module&module=grtransportshipping&controller=quote',{
    waitUntil:'domcontentloaded',timeout:45000,
  });
  await shot('05-public-quote-check','.grtransport-quote');
  console.log('Screenshot evidence: '+captured+' viewport PNGs at 1600x1000 CSS px, scale=2');
} finally {
  await context.close();
  await browser.close();
}
