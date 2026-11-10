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
  await simulator.getByRole('button', { name: 'Calculate test price' }).click();
  const outcome = page.locator('#grtrans-simulator [data-grtrans-result], #grtrans-simulator .alert').first();
  await outcome.waitFor({ timeout: 20000 });
  assert.doesNotMatch(page.url(), /\/security\/compromised/, 'Simulator must not enter the token warning page');
  assert.match(await outcome.innerText(),
    /Quoted price|No price available|The test could not be completed/,
    'The normal simulator result must render on first submit');
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


  // #103 Panel H: create a direct-area leg through the REAL admin form and
  // verify that its DB-backed map snapshot has SQL NULL (not numeric zero).
  // A DB seed would bypass the Db::insert/update nullable writer bug.
  await page.goto(legsUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 45000 });
  form = page.locator('#grtrans-network-editor form');
  await form.locator('select[name="from_station_id"]').selectOption(source);
  await form.locator('select[name="to_station_id"]').selectOption('');
  await form.locator('select[name="mode"]').selectOption('other');
  await form.locator('input[name="operator_label"]').fill('CI dynamic sea via admin');
  await form.locator('input[name="price"]').fill('8.00');
  await form.locator('input[name="area_name"]').fill('CI area sea');
  await form.locator('input[name="area_lat"]').fill('38.0');
  await form.locator('input[name="area_lng"]').fill('23.7');
  await form.locator('input[name="area_radius_km"]').fill('25');
  await form.getByRole('button', { name: 'Create record' }).click();
  await page.getByText('Network record created.', { exact: true }).first().waitFor({ timeout: 20000 });
  const storedArea = () => page.locator('#grtrans-network-map').evaluate(root => {
    const legs = JSON.parse(root.dataset.config).legs;
    return legs.find(leg => leg.operator_label === 'CI dynamic sea via admin') || null;
  });
  let savedArea = await storedArea();
  assert.ok(savedArea, 'Created area leg must appear in the DB-backed admin map config');
  assert.equal(savedArea.to_station_id, null,
    'Direct-area to_station_id must be SQL NULL after INSERT, not 0');
  assert.equal(Number(savedArea.area_radius_km), 25);
  const seamRing = page.locator('#grtrans-network-map path.grtrans-linked-island-perimeter');
  await seamRing.first().waitFor({ timeout: 20000 });
  assert.ok(await seamRing.count() >= 1, 'Panel H: rendered area has a linked island stitch');
  const farSea = page.locator('#grtrans-network-map path.grtrans-organic-patch.grtrans-tier-far[stroke="none"][fill-opacity="0.16"]');
  const normalSea = page.locator('#grtrans-network-map path.grtrans-organic-patch.grtrans-tier-normal[stroke="none"][fill-opacity="0.36"]');
  await farSea.first().waitFor({ timeout: 20000 });
  await normalSea.first().waitFor({ timeout: 20000 });
  assert.ok(await farSea.count() >= 1 && await normalSea.count() >= 1,
    'Panels H/I: unframed FAR area and stronger normal-area tier are rendered');

  // Editing the SAME area leg must preserve NULL in Db::update as well.
  const areaRow = page.locator('#grtrans-network table').nth(1)
    .locator('tbody tr').filter({ hasText: 'CI dynamic sea via admin' });
  assert.equal(await areaRow.count(), 1);
  await areaRow.getByRole('link', { name: 'Edit' }).click();
  form = page.locator('#grtrans-network-editor form');
  await form.locator('input[name="area_radius_km"]').fill('26');
  await form.getByRole('button', { name: 'Save changes' }).click();
  await page.getByText('Network record updated.', { exact: true }).first().waitFor({ timeout: 20000 });
  savedArea = await storedArea();
  assert.equal(savedArea.to_station_id, null,
    'Direct-area to_station_id must remain SQL NULL after UPDATE');
  assert.equal(Number(savedArea.area_radius_km), 26,
    'The updated area geometry must be persisted and served to the map');
  assert.equal(savedArea.area_outer_radius_km, null,
    'An area starts inherited and remains NULL when the normal radius changes');
  await areaRow.getByRole('link', { name: 'Edit' }).click();
  form = page.locator('#grtrans-network-editor form');
  await form.locator('input[name="area_outer_radius_km"]').fill('30');
  await form.getByRole('button', { name: 'Save changes' }).click();
  await page.getByText('Network record updated.', { exact: true }).first().waitFor({ timeout: 20000 });
  savedArea = await storedArea();
  assert.equal(Number(savedArea.area_outer_radius_km), 30,
    'Admin persists an explicit area FAR override in km');
  await areaRow.getByRole('link', { name: 'Edit' }).click();
  form = page.locator('#grtrans-network-editor form');
  await form.getByRole('button', { name: 'Clear override · inherit global' }).last().click();
  assert.equal(await form.locator('input[name="area_outer_radius_km"]').inputValue(), '',
    'Clear control empties the per-area FAR override');
  await form.getByRole('button', { name: 'Save changes' }).click();
  await page.getByText('Network record updated.', { exact: true }).first().waitFor({ timeout: 20000 });
  savedArea = await storedArea();
  assert.equal(savedArea.area_outer_radius_km, null,
    'Clearing the area override saves a real SQL NULL for global inheritance');
  await seamRing.first().waitFor({ timeout: 20000 });
  console.log('LIVE PANEL H: admin area INSERT/UPDATE preserved SQL NULL, sea fill + island stitch rendered');

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
