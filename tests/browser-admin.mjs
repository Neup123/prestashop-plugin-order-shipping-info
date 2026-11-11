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
  // Live PrestaShop 9.2.1 admin DOM/visual acceptance, not a synthetic
  // renderer fixture. Coverage: desktop 1440px, default BO theme, one admin.
  // Excludes mobile/touch, other BO themes and concurrent employee changes.
  // #51/#52: Real BO must make the global route scope and the risk of
  // sellable Available demo rates visible where routes are managed.
  const demoWarning = page.locator('.grtrans-demo-seed-warning');
  assert.ok(await demoWarning.isVisible(), 'Sellable demo warning must be visible in BO');
  assert.match(await demoWarning.innerText(), /Seeded demo routes start Available and can immediately quote/);
  assert.match(await demoWarning.innerText(), /mark demo routes Draft or replace them/);
  const multistoreNote = page.locator('.grtrans-scope-info');
  assert.ok(await multistoreNote.isVisible(), 'Shared-shop scope must be visible in BO');
  assert.match(await multistoreNote.innerText(), /route records are shared by all shops/);
  console.log('LIVE ADMIN POLICY: Available demo rates clearly warned; global multistore route scope visible');
  assert.match(await page.getByRole('status').first().innerText(), /Transport carrier ready/);
  const grid = page.locator('.grtrans-route-grid');
  const firstRow = grid.locator('tbody tr').first();
  const secondRow = grid.locator('tbody tr').nth(1);
  const firstId = (await firstRow.locator('td').first().innerText()).trim();
  const secondId = (await secondRow.locator('td').first().innerText()).trim();
  const secondStation = (await secondRow.locator('td').nth(3).innerText()).trim();
  assert.equal((await firstRow.locator('td').nth(7).innerText()).trim(), 'Yes');
  assert.equal((await firstRow.locator('td').nth(8).innerText()).trim(), 'Available');
  assert.equal((await firstRow.locator('td').nth(9).innerText()).trim(), 'Unverified estimate');
  assert.ok((await firstRow.getAttribute('class')).includes('grtrans-row-demo'),
    'Unverified demo row must have distinctive styling');
  assert.equal(await firstRow.locator('.grtrans-actions .btn').count(), 2);
  assert.ok((await firstRow.locator('button[name="grtrans_delete"]').getAttribute('class')).includes('btn-danger'),
    'Delete needs distinct PrestaShop destructive button styling');
  const firstDemoColor = await firstRow.locator('td').first().evaluate(td => getComputedStyle(td).backgroundColor);
  assert.ok(!['rgba(0, 0, 0, 0)', 'rgb(255, 255, 255)'].includes(firstDemoColor),
    'Demo must have a real, non-white computed cell background in PrestaShop BO');
  const gridWidth = await grid.evaluate(el => {
    const panel = el.closest('.panel');
    return {
      gridWidth: el.getBoundingClientRect().width,
      panelWidth: panel.getBoundingClientRect().width,
      overflow: getComputedStyle(el).overflowX,
    };
  });
  assert.ok(gridWidth.gridWidth <= gridWidth.panelWidth + 2
    && ['auto', 'scroll'].includes(gridWidth.overflow),
    'Wide route grid should scroll inside its panel, never overflow it');
  console.log('LIVE GRID: Available / Yes / Unverified estimate; demo cell color=' + firstDemoColor
    + '; scroll container=' + JSON.stringify(gridWidth));

  // Edit must navigate and visibly scroll to the pre-populated editing form.
  await firstRow.getByRole('link', {name:'Edit'}).click();
  let form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  const editor = page.locator('#grtrans-route-editor');
  assert.equal(new URL(page.url()).hash, '#grtrans-route-editor');
  assert.match(await page.locator('#grtrans-editor-title').innerText(), new RegExp('Editing route #' + firstId));
  assert.ok((await editor.getAttribute('class')).includes('is-editing'));
  assert.equal(await form.locator('input[name="id_route"]').count(), 0, 'Edit form must not offer editable Route ID');
  assert.equal(await form.locator('input[name="grtrans_edit_proof"]').count(), 1,
    'Edit intent must be bound to the opened row');
  assert.equal(await form.locator('input[name="demo"][type="checkbox"]').isChecked(), true);
  assert.equal((await form.locator('label[for="grtrans-field-route_status"]').innerText()).trim(), 'Availability');
  assert.equal((await form.locator('label[for="grtrans-field-demo"]').innerText()).trim(), 'Unverified estimate');
  const viewport = await editor.evaluate(el => {
    const r = el.getBoundingClientRect();
    return {top:r.top,bottom:r.bottom,height:innerHeight,scrollY:scrollY};
  });
  assert.ok(viewport.top < viewport.height && viewport.bottom > 0,
    'Edit link should bring populated editor into viewport');
  console.log('LIVE EDIT: heading + highlighted editor; scroll=' + JSON.stringify(viewport));

  // Invalid edit: multiple named fields, exact submitted values retained,
  // inline validation, and the form/error is visible after POST navigation.
  await form.locator('select[name="origin_name"]').selectOption('Athens Kifisos KTEL');
  await form.locator('select[name="station_name"]').selectOption('');
  await form.locator('input[name="postal_prefix"]').fill('12A');
  await form.locator('input[name="price"]').fill('16.23');
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route not saved.', {exact:false}).first().waitFor({timeout:20000});
  form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.equal(new URL(page.url()).hash, '#grtrans-route-editor');
  assert.equal(await form.locator('select[name="origin_name"]').inputValue(), 'Athens Kifisos KTEL');
  assert.equal(await form.locator('select[name="station_name"]').inputValue(), '');
  assert.equal(await form.locator('input[name="postal_prefix"]').inputValue(), '12A');
  assert.equal(await form.locator('input[name="price"]').inputValue(), '16.23');
  assert.match(await page.locator('#grtrans-form-errors').innerText(),
    /Destination pickup station cannot be empty/);
  assert.match(await page.locator('#grtrans-form-errors').innerText(),
    /Postal prefix must contain only digits/);
  assert.equal(await form.locator('select[name="station_name"]').getAttribute('aria-invalid'), 'true');
  assert.equal(await form.locator('input[name="postal_prefix"]').getAttribute('aria-invalid'), 'true');
  assert.ok(await page.locator('#grtrans-form-errors').isVisible());
  const errorViewport = await editor.evaluate(el => {
    const r = el.getBoundingClientRect();
    return {top:r.top,bottom:r.bottom,height:innerHeight};
  });
  assert.ok(errorViewport.top < errorViewport.height && errorViewport.bottom > 0,
    'Invalid route save must return user to error and form');
  assert.equal((await grid.locator('tbody tr').first().locator('td').nth(3).innerText()).trim(),
    'Patras KTEL station', 'Failed validation must not update existing route');
  console.log('LIVE FAILED EDIT: preserved station blank, prefix 12A, origin and fare; two named errors and in-view alert');

  // #46: On the actual PrestaShop BO editor a malformed comma amount
  // must show a field-named error, while a genuine decimal comma is saved
  // to the same numeric EUR field (no browser-side conversion shortcut).
  await form.locator('select[name="station_name"]').selectOption('Patras KTEL station');
  await form.locator('input[name="postal_prefix"]').fill('262');
  await form.locator('input[name="price"]').fill('12,5,0');
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route not saved.', {exact:false}).first().waitFor({timeout:20000});
  form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.match(await page.locator('#grtrans-form-errors').innerText(), /Transport price EUR/);
  assert.equal(await form.locator('input[name="price"]').inputValue(), '12,5,0');
  assert.equal(await form.locator('input[name="price"]').getAttribute('aria-invalid'), 'true');
  console.log('LIVE PRICE INVALID: double-comma fare rejected with named field and typed value retained');

  // Correct and save the original route using the European decimal comma.
  await form.locator('input[name="price"]').fill('16,25');
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  assert.match((await grid.locator('tbody tr').first().innerText()), /16\.25/);
  console.log('LIVE PRICE COMMA: admin saved 16,25 as numeric 16.25 on real route');
  assert.equal((await grid.locator('tbody tr').first().locator('td').nth(9).innerText()).trim(),
    'Unverified estimate', 'Editing a demo fare must not mark it operator-confirmed');

  // Attempt to inject a replacement primary key into an already opened edit.
  // The server must reject it and keep the unrelated route completely intact.
  await page.goto(adminUrl, {waitUntil:'domcontentloaded'});
  await page.locator('.grtrans-route-grid tbody tr').first().getByRole('link',{name:'Edit'}).click();
  form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  await form.locator('input[name="price"]').fill('987.65');
  await form.evaluate((el, id) => {
    const fake = document.createElement('input');
    fake.type = 'hidden'; fake.name = 'id_route'; fake.value = id; el.appendChild(fake);
  }, secondId);
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route ID cannot be changed in the form', {exact:false}).first().waitFor({timeout:20000});
  assert.equal((await page.locator('.grtrans-route-grid tbody tr').nth(1).locator('td').nth(3).innerText()).trim(),
    secondStation, 'Changing submitted ID must not change another route');
  assert.doesNotMatch(await page.locator('.grtrans-route-grid tbody tr').first().innerText(), /987\.65/);

  // Also attempt to retarget the edit URL while reusing the original form's
  // signature. The authorization-bound route intent must not follow it.
  await page.goto(adminUrl, {waitUntil:'domcontentloaded'});
  await page.locator('.grtrans-route-grid tbody tr').first().getByRole('link',{name:'Edit'}).click();
  form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  await form.locator('input[name="price"]').fill('887.65');
  await form.evaluate((el, id) => {
    const target = new URL(el.action);
    target.searchParams.set('grtrans_edit', id);
    el.action = target.toString();
  }, secondId);
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route edit target changed', {exact:false}).first().waitFor({timeout:20000});
  assert.equal((await page.locator('.grtrans-route-grid tbody tr').nth(1).locator('td').nth(3).innerText()).trim(),
    secondStation);
  assert.doesNotMatch(await page.locator('.grtrans-route-grid tbody tr').nth(1).innerText(), /887\.65/);
  console.log('LIVE EDIT TARGET: injected ID and mismatched signed target both rejected without altering another route');

  // Add form is ID-free and must retain values and named errors on failure.
  await page.goto(adminUrl, {waitUntil:'domcontentloaded',timeout:45000});
  let addForm = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.equal(await addForm.locator('input[name="id_route"]').count(), 0,
    'Add form must not expose Route ID, not even as a zero-valued input');
  assert.equal(await addForm.locator('input[name="grtrans_edit_proof"]').count(), 0);
  await addForm.locator('select[name="mode"]').selectOption('ferry');
  await addForm.locator('select[name="origin_name"]').selectOption('Port of Lavrio');
  await addForm.locator('select[name="station_name"]').selectOption('Kea Korissia Port');
  await addForm.locator('input[name="city"]').fill('CI Town');
  await addForm.locator('input[name="postal_prefix"]').fill('ABC');
  await addForm.locator('input[name="price"]').fill('6.75');
  await addForm.locator('input[name="pickup_only"][type="checkbox"]').check();
  await addForm.locator('select[name="route_status"]').selectOption('available');
  await addForm.locator('input[name="demo"][type="checkbox"]').check();
  await addForm.locator('textarea[name="notice"]').fill('CI customer collection note');
  await addForm.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route not saved.', {exact:false}).first().waitFor({timeout:20000});
  addForm = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  assert.match(await page.locator('#grtrans-form-errors').innerText(), /Postal prefix must contain only digits/);
  assert.equal(await addForm.locator('select[name="station_name"]').inputValue(), 'Kea Korissia Port');
  assert.equal(await addForm.locator('textarea[name="notice"]').inputValue(), 'CI customer collection note');
  assert.equal(await addForm.locator('input[name="postal_prefix"]').inputValue(), 'ABC');
  assert.equal(await addForm.locator('input[name="id_route"]').count(), 0);
  console.log('LIVE FAILED ADD: preserved all typed route data, postal prefix error shown, no Route ID control');

  await addForm.locator('input[name="postal_prefix"]').fill('99998');
  await addForm.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  let inserted = page.locator('.grtrans-route-grid tbody tr').filter({hasText:'99998'});
  assert.equal(await inserted.count(), 1, 'New route should appear in admin table');
  assert.equal((await inserted.locator('td').nth(3).innerText()).trim(), 'Kea Korissia Port');
  assert.equal((await inserted.locator('td').nth(8).innerText()).trim(), 'Available');
  assert.equal((await inserted.locator('td').nth(9).innerText()).trim(), 'Unverified estimate');
  const deletedId = (await inserted.locator('td').first().innerText()).trim();

  // Make the test route suspended and operator-confirmed. The rendered
  // background and badges must visibly change, not just the stored flags.
  await inserted.getByRole('link',{name:'Edit'}).click();
  form = page.locator('form').filter({has:page.locator('button[name="grtrans_save"]')}).first();
  await form.locator('select[name="route_status"]').selectOption('suspended');
  await form.locator('input[name="demo"][type="checkbox"]').uncheck();
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  inserted = page.locator('.grtrans-route-grid tbody tr').filter({hasText:'99998'});
  assert.equal((await inserted.locator('td').nth(8).innerText()).trim(), 'Suspended');
  assert.equal((await inserted.locator('td').nth(9).innerText()).trim(), 'Operator-confirmed');
  assert.ok((await inserted.getAttribute('class')).includes('grtrans-row-suspended'));
  const suspendedColor = await inserted.locator('td').first().evaluate(td=>getComputedStyle(td).backgroundColor);
  assert.notEqual(suspendedColor, firstDemoColor,
    'Suspended row must have a visually distinct background from the demo row');
  console.log('LIVE SUSPENDED: Suspended / Operator-confirmed; cell color=' + suspendedColor);

  page.once('dialog', async dialog => {
    assert.match(dialog.message(), /Delete route #/);
    await dialog.accept();
  });
  await inserted.locator('button[name="grtrans_delete"]').click();
  await page.getByText('Route dataset updated', {exact:false}).first().waitFor({timeout:20000});
  assert.equal(await page.locator('.grtrans-route-grid tbody tr').filter({hasText:'99998'}).count(), 0,
    'Deleted rule should not appear in admin route list');

  // The existing stale-delete rejection check still exercises a real route.
  const staleForm = page.locator('.grtrans-route-grid tbody tr form').first();
  await staleForm.locator('input[name="id_route"]').evaluate((input,id)=>{input.value=id;},deletedId);
  page.once('dialog', async dialog => await dialog.accept());
  await staleForm.locator('button[name="grtrans_delete"]').click();
  await page.getByText('Route does not exist', {exact:false}).first().waitFor({timeout:20000});
  assert.doesNotMatch(await page.locator('body').innerText(), /Route dataset updated\./);
  console.log('Browser admin grid, edit scroll, failed-save retention, edit-target protection, add/delete and stale delete passed');

  // #43 — actual persisted PrestaShop 9.2.1 order admin surface.
  // The test's order was created by the existing native PaymentModule
  // smoke, not from synthetic HTML or a stubbed HTTP POST. Covers dialog
  // dismissal (no write request) and consent (unchanged repair handler).
  // Does not cover other browsers, browsers with JavaScript disabled, or
  // deliberate raw HTTP requests bypassing the UI confirmation.
  const orderId = Number(process.env.TEST_ORDER_ID);
  assert.ok(Number.isSafeInteger(orderId) && orderId > 0,
    'Browser acceptance requires a real persisted transport order ID');
  // Symfony protects AdminOrders with a controller-specific token. Follow
  // the authenticated BO's real Orders link instead of guessing an untrusted
  // direct URL (which PrestaShop redirects to /security/compromised).
  const ordersIndex = await page.locator('a[href]').evaluateAll(anchors =>
    anchors.map(a => a.href).find(h =>
      h.includes('/sell/orders/') && /[?&](?:token|_token)=/.test(h)
    ) ?? null);
  assert.ok(ordersIndex, 'Authenticated PrestaShop BO did not expose a tokenized Orders link');
  await page.goto(ordersIndex, {waitUntil:'domcontentloaded', timeout:45000});
  const orderDetailLink = await page.locator('a[href]').evaluateAll((anchors, id) =>
    anchors.map(a => a.href).find(h => h.includes('/sell/orders/' + id + '/view')) ?? null,
    String(orderId));
  const orderView = orderDetailLink ?? (() => {
    const uri = new URL(ordersIndex);
    uri.pathname = '/admin-dev/index.php/sell/orders/' + orderId + '/view';
    return uri.toString();
  })();
  await page.goto(orderView, {waitUntil:'domcontentloaded', timeout:45000});
  const regenerate = page.locator('button[name="grtrans_repair_order"]').first();
  await regenerate.waitFor({timeout:30000});
  assert.equal(await regenerate.innerText(), 'Regenerate original pickup snapshot');
  assert.equal(await regenerate.getAttribute('value'), String(orderId));
  const regenForm = regenerate.locator('xpath=ancestor::form[1]');
  const confirmationJs = await regenForm.getAttribute('onsubmit');
  assert.match(confirmationJs ?? '', /return confirm\(/);
  assert.match(confirmationJs ?? '', /overwrite the saved historical pickup snapshot/);
  assert.match(confirmationJs ?? '', /original checkout quote/);
  const targetAction = new URL(await regenForm.getAttribute('action'), page.url()).pathname;
  let repairPosts = 0;
  page.on('request', request => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === targetAction) {
      repairPosts++;
    }
  });
  let canceledDialog = '';
  page.once('dialog', async dialog => {
    canceledDialog = dialog.message();
    assert.match(canceledDialog, /order #\d+/);
    assert.match(canceledDialog, /overwrite the saved historical pickup snapshot/);
    await dialog.dismiss();
  });
  await regenerate.click();
  assert.match(canceledDialog, /original checkout quote/);
  await page.waitForTimeout(200);
  assert.equal(repairPosts, 0, 'Cancel must stop the overwrite before any POST');
  assert.equal(await regenerate.count(), 1,
    'Canceled regeneration must leave the stored order card in view');
  console.log('LIVE ORDER CANCEL: original historical snapshot remains, confirmation dismissed and zero POST requests');

  let acceptedDialog = '';
  page.once('dialog', async dialog => {
    acceptedDialog = dialog.message();
    await dialog.accept();
  });
  await regenerate.click();
  assert.equal(acceptedDialog, canceledDialog,
    'Accepted prompt must describe the same overwrite consequence');
  await page.getByText('Order pickup snapshot regenerated from its original cart quote.', {exact:false})
    .first().waitFor({timeout:30000});
  assert.equal(repairPosts, 1, 'Confirm must submit through the existing handler exactly once');
  console.log('LIVE ORDER CONFIRM: one confirmed POST and original-quote snapshot regeneration success');

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
