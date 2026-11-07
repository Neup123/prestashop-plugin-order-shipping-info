import { chromium } from 'playwright';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const fixture = JSON.parse(fs.readFileSync('/tmp/grtrans-browser-fixture.json','utf8'));
const password = process.env.TEST_CUSTOMER_PASSWORD;
if (!password) throw new Error('Missing ephemeral test customer password');
const browser = await chromium.launch({headless:true});
const page = await browser.newPage({viewport:{width:1366,height:900}});
try {
  await page.goto(fixture.login_url, {waitUntil:'domcontentloaded',timeout:45000});
  await page.locator('input[name="email"], #field-email, input[type="email"]').first().fill('ci-buyer@example.invalid');
  await page.locator('input[name="password"], #field-password, input[type="password"]').first().fill(password);
  await page.locator('button[data-link-action="sign-in"], button[type="submit"]').last().click();
  await page.waitForLoadState('domcontentloaded');
  if (await page.locator('input[name="password"]').count() && (await page.locator('body').innerText()).includes('Authentication failed')) {
    throw new Error('Disposable customer authentication failed');
  }
  await page.goto(fixture.product_url,{waitUntil:'domcontentloaded',timeout:45000});
  const buy = page.locator('button[data-button-action="add-to-cart"], button.add-to-cart').first();
  await buy.waitFor({timeout:20000});
  await buy.click();
  await page.waitForTimeout(1000);
  await page.goto(fixture.checkout_url,{waitUntil:'domcontentloaded',timeout:45000});
  for (let step=0;step<4;step++) {
    const html = await page.content();
    if (html.includes('Naxos Port') && html.includes('STATION PICKUP ONLY')) break;
    const next = page.locator('button[name="confirm-addresses"], button[name="confirmDeliveryOption"], button.continue').filter({visible:true}).first();
    if (!(await next.count())) break;
    await next.click();
    await page.waitForLoadState('domcontentloaded');
  }
  const source = await page.content();
  assert(source.includes('Naxos Port'), 'Checkout must name Naxos collection port');
  assert(source.includes('STATION PICKUP ONLY'), 'Checkout must explicitly say station pickup only');
  assert(!source.includes('Home delivery included'), 'Pickup is not home delivery');
  console.log('Greek ferry checkout pickup notice present in real browser');
} catch (error) {
  console.error('Checkout browser acceptance failed:',error);
  console.error('Current URL path:',new URL(page.url()).pathname);
  console.error('Checkout headings:', await page.locator('h1,h2,h3').allTextContents().catch(()=>[]));
  console.error('Checkout text:',(await page.locator('body').innerText().catch(()=>'' )).slice(0,4500));
  await page.screenshot({path:'/tmp/prestashop-checkout-failure.png',fullPage:true}).catch(()=>{});
  throw error;
} finally {
  await browser.close();
}
