import assert from 'node:assert/strict';
import { chromium } from 'playwright';

// Real PrestaShop 9.2 browser acceptance for #86, #87 and #88.
// Runs on the same disposable Docker shop as tests/browser-admin.mjs.
const adminUrl = process.env.ADMIN_URL;
const user = process.env.TEST_ADMIN_EMAIL;
const password = process.env.TEST_ADMIN_PASSWORD;
if (!adminUrl || !user || !password) throw new Error('Missing browser test configuration');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });

async function checkMarkerAssets(mapSelector, label) {
  const icons = page.locator(mapSelector + ' img.leaflet-marker-icon');
  const shadows = page.locator(mapSelector + ' img.leaflet-marker-shadow');
  await icons.first().waitFor({ timeout: 20000 });
  await shadows.first().waitFor({ timeout: 20000 });
  await page.waitForFunction(selector => {
    const images = document.querySelectorAll(
      selector + ' img.leaflet-marker-icon, ' + selector + ' img.leaflet-marker-shadow'
    );
    return images.length >= 2 && [...images].every(image => image.complete && image.naturalWidth > 0);
  }, mapSelector, { timeout: 20000 });
  const urls = await page.locator(
    mapSelector + ' img.leaflet-marker-icon, ' + mapSelector + ' img.leaflet-marker-shadow'
  ).evaluateAll(images => [...new Set(images.map(image => image.src))]);
  assert.ok(urls.some(url => /\/marker-icon(?:-2x)?\.png$/.test(url)), label + ': missing icon');
  assert.ok(urls.some(url => /\/marker-shadow\.png$/.test(url)), label + ': missing shadow');
  for (const url of urls) {
    assert.match(new URL(url).pathname,
      /\/modules\/grtransportshipping\/views\/vendor\/leaflet\/images\/marker-(?:icon(?:-2x)?|shadow)\.png$/,
      label + ': icon path must not be resolved against an admin/front controller');
    const response = await page.request.get(url);
    assert.equal(response.status(), 200, label + ': ' + url + ' must return HTTP 200');
  }
}

async function addStation(name, lat, lng, hub) {
  await page.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
  const form = page.locator('#grtrans-network-editor form');
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="address_text"]').fill('Ephemeral CI station');
  await form.locator('input[name="lat"]').fill(String(lat));
  await form.locator('input[name="lng"]').fill(String(lng));
  await form.locator('input[name="inner_radius_km"]').fill('2');
  await form.locator('input[name="outer_radius_km"]').fill('6');
  await form.locator('select[name="is_origin_hub"]').selectOption(hub ? '1' : '0');
  await form.getByRole('button', { name: 'Create record' }).click();
  await page.getByText('Network record created.', { exact: true }).first().waitFor({ timeout: 20000 });
  const row = page.locator('#grtrans-network table').first().locator('tbody tr').filter({ hasText: name });
  assert.equal(await row.count(), 1, 'Station should be persisted after create');
  return (await row.locator('td').first().innerText()).trim();
}

try {
  await page.goto('http://localhost:8080/admin-dev/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('input[name="email"], input[type="email"]').first().fill(user);
  await page.locator('input[name="passwd"], input[type="password"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');

  // #86: Submit the actual back-office form. It must POST its valid token and
  // render the result without the Symfony /security/compromised interstitial.
  await page.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
  const simulator = page.locator('#grtrans-simulator form');
  assert.equal((await simulator.getAttribute('method')).toLowerCase(), 'post');
  assert.ok((await simulator.locator('input[name="token"]').inputValue()).length > 8);
  await simulator.locator('input[name="postcode"]').fill('26221');
  await simulator.getByRole('button', { name: 'Simulate quote' }).click();
  await page.locator('#grtrans-simulator .alert').first().waitFor({ timeout: 20000 });
  assert.doesNotMatch(page.url(), /\/security\/compromised/, 'Simulator must not enter the token warning page');
  assert.match(await page.locator('#grtrans-simulator .alert').first().innerText(),
    /Quoted EUR|No quote:/, 'The normal simulator result must render on first submit');
  console.log('LIVE SIMULATOR: tokenized POST rendered result without interstitial');

  // #88: Creating stations/legs confirms creation; editing a leg confirms update.
  const source = await addStation('CI map source', 38.0, 23.7, true);
  await checkMarkerAssets('#grtrans-network-map', 'admin station map');
  const stationRow = page.locator('#grtrans-network table').first().locator('tbody tr')
    .filter({ hasText: 'CI map source' });
  await stationRow.getByRole('link', { name: 'Edit' }).click();
  let form = page.locator('#grtrans-network-editor form');
  await form.locator('input[name="address_text"]').fill('CI edited station');
  await form.getByRole('button', { name: 'Save changes' }).click();
  await page.getByText('Network record updated.', { exact: true }).first().waitFor({ timeout: 20000 });
  const target = await addStation('CI map target', 38.05, 23.75, false);
  const legsUrl = new URL(adminUrl);
  legsUrl.searchParams.set('network_entity', 'legs');
  await page.goto(legsUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 45000 });
  form = page.locator('#grtrans-network-editor form');
  await form.locator('select[name="from_station_id"]').selectOption(source);
  await form.locator('select[name="to_station_id"]').selectOption(target);
  await form.locator('input[name="operator_label"]').fill('CI leg');
  await form.locator('input[name="price"]').fill('12.50');
  await form.getByRole('button', { name: 'Create record' }).click();
  await page.getByText('Network record created.', { exact: true }).first().waitFor({ timeout: 20000 });
  const legRow = page.locator('#grtrans-network table').nth(1).locator('tbody tr').filter({ hasText: 'CI leg' });
  assert.equal(await legRow.count(), 1);
  console.log('LIVE NETWORK FEEDBACK: stations and leg created, station edited with correct notices');

  // #87: Customer public map and BO map both load actual bundled PNGs over HTTP.
  await page.goto('http://localhost:8080/index.php?fc=module&module=grtransportshipping&controller=quote',
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('#grtransport-show-map').click();
  await page.locator('#grtransport-map').click({ position: { x: 120, y: 120 } });
  await checkMarkerAssets('#grtransport-map', 'customer destination map');
  console.log('LIVE LEAFLET ASSETS: customer and admin markers/shadows load with HTTP 200');

  // #90: Customer quote result has one Route line, not repeated station rows.
  // Exercise the real Smarty-rendered result, preserving pickup instructions.
  await page.goto(
    'http://localhost:8080/index.php?fc=module&module=grtransportshipping&controller=quote&destination=84300',
    { waitUntil: 'domcontentloaded', timeout: 45000 }
  );
  const result = page.locator('#grtransport-quote-result');
  await result.locator('dl dt').first().waitFor({ timeout: 20000 });
  const labels = (await result.locator('dl dt').allInnerTexts()).map(text => text.trim());
  assert.deepEqual(labels, ['Route', 'Carrier', 'Transport price including eligible handling'],
    'Customer details must omit duplicate Origin station / Destination station rows');
  const values = (await result.locator('dl dd').allInnerTexts()).map(text => text.trim());
  assert.equal(values.length, 3, 'Route, carrier, and price each have one value');
  assert.equal(values[0], 'Port of Piraeus → Naxos Port', 'Route still identifies both terminals');
  assert.ok(values[1].length > 0, 'Carrier still appears');
  assert.match(values[2], /(?:€\s*\d+[.,]\d{2}|\d+[.,]\d{2}\s*(?:EUR|€))/, 'Transport price still appears');
  const pickup = result.locator('.grtransport-pickup');
  assert.ok(await pickup.isVisible(), 'Customer pickup notice stays visible');
  assert.match(await pickup.innerText(), /STATION PICKUP ONLY/, 'Pickup warning remains');
  assert.match(await pickup.innerText(), /Collection point:\s*Naxos Port/,
    'Collection point is a necessary customer pickup instruction');
  console.log('LIVE CUSTOMER QUOTE: Route, carrier, price and collection notice; no duplicate station rows');
} catch (error) {
  console.error('Network fix browser acceptance failed:', error);
  console.error('Current URL:', page.url());
  console.error('Page text:', (await page.locator('body').innerText().catch(() => '')).slice(0, 2500));
  throw error;
} finally {
  await browser.close();
}
