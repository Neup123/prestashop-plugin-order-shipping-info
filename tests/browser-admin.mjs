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
  // Prove that staff can add and delete an area/station from the UI,
  // including its transport mode, fare, pickup policy and customer notice.
  await page.goto(adminUrl, {waitUntil:'domcontentloaded', timeout:45000});
  const addForm = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.equal(await addForm.locator('input[name="id_route"]').inputValue(), '0', 'expected blank add form');
  await addForm.locator('select[name="mode"]').selectOption('ferry');
  await addForm.locator('input[name="origin_name"]').fill('Port of Lavrio');
  await addForm.locator('input[name="station_name"]').fill('CI temporary port');
  await addForm.locator('input[name="city"]').fill('CI Town');
  await addForm.locator('input[name="postal_prefix"]').fill('99998');
  await addForm.locator('input[name="price"]').fill('6.75');
  await addForm.locator('select[name="pickup_only"]').selectOption('1');
  await addForm.locator('select[name="route_status"]').selectOption('available');
  await addForm.locator('select[name="demo"]').selectOption('1');
  await addForm.locator('textarea[name="notice"]').fill('CI customer collection note');
  await addForm.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  const inserted = page.locator('table.table tbody tr').filter({hasText:'99998'});
  assert.equal(await inserted.count(), 1, 'New route should appear in admin table');
  assert.equal((await inserted.locator('td').nth(3).innerText()).trim(), 'CI temporary port');
  assert.equal((await inserted.locator('td').nth(9).innerText()).trim(), '1', 'new route must remain demo');
  const deletedId = (await inserted.locator('td').first().innerText()).trim();
  page.once('dialog', async dialog => await dialog.accept());
  await inserted.locator('button[name="grtrans_delete"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  assert.equal(await page.locator('table.table tbody tr').filter({hasText:'99998'}).count(), 0,
    'Deleted rule should not appear in admin route list');
  // A stale, repeated delete must show an error, not a success toast.
  const staleForm = page.locator('table.table tbody tr form').first();
  await staleForm.locator('input[name="id_route"]').evaluate((input, id) => {
    input.value = id;
  }, deletedId);
  page.once('dialog', async dialog => await dialog.accept());
  await staleForm.locator('button[name="grtrans_delete"]').click();
  await page.getByText('Route does not exist', {exact:false}).first().waitFor({timeout:20000});
  assert.doesNotMatch(await page.locator('body').innerText(), /Route dataset updated\./);
  console.log('Browser admin route create, edit, delete and stale delete rejection passed');
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
