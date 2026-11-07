import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const adminUrl = process.env.ADMIN_URL;
const user = process.env.TEST_ADMIN_EMAIL;
const password = process.env.TEST_ADMIN_PASSWORD;
if (!adminUrl || !user || !password) throw new Error('Missing ephemeral browser test configuration');
const browser = await chromium.launch({headless:true});
const page = await browser.newPage({viewport:{width:1440,height:1000}});
try {
  await page.goto('http://localhost:8080/admin-dev/', {waitUntil:'domcontentloaded',timeout:45000});
  const email = page.locator('input[name="email"], input[type="email"]').first();
  await email.waitFor({timeout:20000});
  await email.fill(user);
  await page.locator('input[name="passwd"], input[type="password"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto(adminUrl, {waitUntil:'domcontentloaded',timeout:45000});
  await page.getByText('Greek public transport routes', {exact:false}).first().waitFor({timeout:20000});
  assert.match(await page.locator('body').innerText(), /DEMO rows are assumptions/);
  const editLink = page.getByRole('link', {name:'Edit'}).first();
  await editLink.click();
  const form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.equal(await form.locator('select[name="demo"]').inputValue(), '1', 'assumed data must remain explicitly marked');
  await form.locator('input[name="price"]').fill('16.25');
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  assert.match(await page.locator('body').innerText(), /16\.25/);
  assert.equal((await page.locator('table.table tbody tr').first().locator('td').nth(9).innerText()).trim(), '1', 'price edit must not mark demo route as verified');
  console.log('Browser admin route editing passed');
} catch (error) {
  console.error('Browser acceptance failed:', error);
  console.error('Current path:', new URL(page.url()).pathname);
  console.error('Inputs:', await page.locator('input').evaluateAll(xs=>xs.map(x=>x.name)).catch(()=>[]));
  console.error('Page text:', (await page.locator('body').innerText().catch(()=>'' )).slice(0,3000));
  await page.screenshot({path:'/tmp/prestashop-browser-failure.png',fullPage:true}).catch(()=>{});
  throw error;
} finally {
  await browser.close();
}
