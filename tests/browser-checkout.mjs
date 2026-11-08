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
  // Positive control: the real Hummingbird carrier option is present for
  // 84300 and its selected pickup note is visible in the rendered UI.
  const deliveryStep = page.locator('#checkout-delivery-step');
  const ferryRow = deliveryStep.locator('.delivery-option__item').filter({
    hasText: 'Greek transport — station pickup',
  });
  assert.equal(await ferryRow.count(), 1, 'Matched Greek address must offer the station carrier');
  const carrierRadio = ferryRow.locator('input[type="radio"]');
  await carrierRadio.check();
  await page.waitForTimeout(350);
  assert.ok(await ferryRow.getByText('STATION PICKUP ONLY — NO HOME DELIVERY').isVisible(),
    'Selected station carrier must visibly disclose pickup-only shipping');
  assert.equal(await page.locator('.grtransport-no-quote:visible').count(), 0,
    'Quoted addresses must not receive a false unavailable warning');
  console.log('LIVE MATCHED SHIPPING: transport option selectable; pickup warning visible; no no-quote alert');

  // Hummingbird lets customers return to the address selector during
  // checkout. The previously selected carrier must disappear when the
  // delivery address changes to a Greek postcode with no matching route.
  // Assertions target visible DOM nodes, not raw hook HTML or a stub.
  const addressStep = page.locator('#checkout-addresses-step');
  // Hummingbird renders previous steps as collapsed/hidden panels. Use its
  // actual "Back to Addresses" navigation button rather than trying to click
  // a hidden step header.
  await deliveryStep.locator('button.js-back[data-step="checkout-addresses-step"]').click();
  await addressStep.waitFor({state:'visible',timeout:20000});
  const unmatchedRadio = addressStep.locator(
    'input[name="id_address_delivery"][value="' + fixture.unmatched_address_id + '"]'
  );
  await unmatchedRadio.waitFor({state:'visible',timeout:20000});
  await unmatchedRadio.check();
  await addressStep.locator('button[name="confirm-addresses"]').click();
  await page.waitForLoadState('domcontentloaded');
  const noQuote = deliveryStep.locator('.grtransport-no-quote');
  await noQuote.waitFor({state:'visible',timeout:25000});
  assert.match(await noQuote.innerText(), /Greek public transport shipping is unavailable for this address/);
  assert.match(await noQuote.innerText(), /No priced transport route matches this destination/);
  assert.ok((await noQuote.getAttribute('class')).includes('alert-info'),
    'Unavailable explanation should share BOOTSTRAP alert presentation with pickup notices');
  const displayedBackground = await noQuote.evaluate(x=>getComputedStyle(x).backgroundColor);
  assert.ok(displayedBackground !== 'rgba(0, 0, 0, 0)' && displayedBackground !== 'rgb(255, 255, 255)',
    'Customer should see the no-quote message styled as an alert, not a bare paragraph');
  assert.equal(await deliveryStep.locator('.delivery-option__item').filter({
    hasText: 'Greek transport — station pickup',
  }).count(), 0, 'Carrier must disappear when quote becomes unavailable');
  console.log('LIVE UNMATCHED SHIPPING: 99999 Greek address shows visible alert with reason; other carriers remain; transport option absent; background=' + displayedBackground);

  // A fresh checkout load with the already-unmatched Greek address must
  // still show the explanation, not only in the immediate transition.
  await page.goto(fixture.checkout_url,{waitUntil:'domcontentloaded',timeout:45000});
  const reloadedAlert = page.locator('#checkout-delivery-step .grtransport-no-quote');
  await reloadedAlert.waitFor({state:'visible',timeout:25000});
  assert.match(await reloadedAlert.innerText(), /No priced transport route matches this destination/);
  console.log('LIVE UNMATCHED RELOAD: no-quote message persists in freshly rendered checkout');

  // #45: Real Hummingbird customer checkout on a postcode that matches NO
  // seeded prefix. Only a city-named route for stored "Νάξος" is available.
  // Customer address "Ναξος" must restore the carrier and show station text.
  await deliveryStep.locator('button.js-back[data-step="checkout-addresses-step"]').click();
  await addressStep.waitFor({state:'visible',timeout:20000});
  await addressStep.locator(
    'input[name="id_address_delivery"][value="' + fixture.city_only_address_id + '"]'
  ).check();
  await addressStep.locator('button[name="confirm-addresses"]').click();
  await page.waitForLoadState('domcontentloaded');
  const cityOnlyCarrier = deliveryStep.locator('.delivery-option__item').filter({
    hasText: 'Greek transport — station pickup',
  });
  await cityOnlyCarrier.waitFor({state:'visible',timeout:20000});
  await cityOnlyCarrier.locator('input[type="radio"]').check();
  await page.waitForTimeout(250);
  assert.ok(await cityOnlyCarrier.getByText('CI Naxos city station').isVisible(),
    'Unaccented Ναξος must select named city-only Νάξος route in customer checkout');
  assert.match(await cityOnlyCarrier.innerText(), /12[.,]50/,
    'Customer must see city-only EUR 12.50 quote');
  assert.equal(await deliveryStep.locator('.grtransport-no-quote:visible').count(), 0);
  console.log('LIVE GREEK CITY: stored Νάξος city-only route offered for customer Ναξος with 99997 postcode, EUR 12.50 visible');

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
