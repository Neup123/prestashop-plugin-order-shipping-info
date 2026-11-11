import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const adminUrl=process.env.ADMIN_URL;
const user=process.env.TEST_ADMIN_EMAIL;
const password=process.env.TEST_ADMIN_PASSWORD;
if(!adminUrl||!user||!password)throw new Error('Missing ephemeral browser test configuration');
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1440,height:1000}});
try {
  await page.goto('http://localhost:8080/admin-dev/',{waitUntil:'domcontentloaded',timeout:45000});
  await page.locator('input[name="email"],input[type="email"]').first().fill(user);
  await page.locator('input[name="passwd"],input[type="password"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto(adminUrl,{waitUntil:'domcontentloaded',timeout:45000});
  const stage=page.locator('#grtrans-map-workspace');
  await stage.waitFor({timeout:20000});
  assert.equal(await stage.locator('.grtrans-map-sidebar').count(),1);
  assert.equal(await stage.locator('.grtrans-map-column').count(),1);
  const map=page.locator('#grtrans-network-map');
  const height=await map.evaluate(element=>element.getBoundingClientRect().height);
  assert.ok(height>=600,'Map must use a substantial viewport-relative height: '+height);
  assert.equal(await stage.locator('section.grtrans-network-list').count(),5);
  assert.equal(await stage.locator('section.grtrans-network-list:not([hidden])').count(),1);
  assert.match(await stage.locator('[data-grtrans-entity="stations"] thead').innerText(),/Can dispatch parcels/);
  assert.doesNotMatch(await stage.locator('[data-grtrans-entity="stations"] thead').innerText(),/is origin hub/);
  const before=await page.evaluate(()=>{
    const bridge=window.GrtransMapBridge;
    return {id:bridge.map._leaflet_id,lat:bridge.map.getCenter().lat,lng:bridge.map.getCenter().lng,zoom:bridge.map.getZoom()};
  });
  await stage.locator('.grtrans-network-toolbar a').filter({hasText:'Transport connections'}).click();
  const during=await page.evaluate(()=>{
    const bridge=window.GrtransMapBridge;
    return {id:bridge.map._leaflet_id,lat:bridge.map.getCenter().lat,lng:bridge.map.getCenter().lng,zoom:bridge.map.getZoom()};
  });
  assert.deepEqual(during,before,'Switching Manage category must not recreate or recenter the map');
  assert.equal(await stage.locator('section.grtrans-network-list:not([hidden])').getAttribute('data-grtrans-entity'),'legs');
  await stage.locator('.grtrans-network-toolbar a').filter({hasText:'Stations'}).click();
  const firstRow=stage.locator('[data-grtrans-entity="stations"] tr[data-grtrans-record-id]').first();
  await firstRow.locator('button[data-grtrans-select-record]').click();
  assert.equal(await firstRow.locator('button[data-grtrans-select-record]').getAttribute('aria-pressed'),'true');
  assert.equal(await page.evaluate(()=>window.GrtransMapWorkspace.stage.id),'grtrans-map-workspace');
  await stage.getByRole('button',{name:'Place station'}).click();
  await stage.getByRole('button',{name:'Use map centre'}).click();
  const form=stage.locator('#grtrans-network-editor form');
  assert.notEqual(await form.locator('input[name="lat"]').inputValue(),'');
  assert.notEqual(await form.locator('input[name="lng"]').inputValue(),'');
  assert.ok(await stage.locator('.grtrans-map-unsaved').isVisible());
  await page.keyboard.press('Escape');
  assert.equal(await form.locator('input[name="lat"]').inputValue(),'');
  assert.equal(await form.locator('input[name="lng"]').inputValue(),'');
  assert.equal(await stage.locator('[data-grtrans-mode="select"]').getAttribute('aria-pressed'),'true');
  await stage.getByRole('button',{name:'Connect stations'}).click();
  await page.waitForURL(/network_entity=legs/,{timeout:20000});
  const pairForm=page.locator('#grtrans-network-editor form');
  await page.locator('#grtrans-map-workspace').waitFor();
  const stations=await page.evaluate(()=>window.GrtransMapBridge.data.stations.map(s=>Number(s.id_station)));
  assert.ok(stations.length>=2,'At least two seeded stations required for pairwise editor');
  // Leaflet's exact click handlers are exercised without starting a POST.
  await page.evaluate(ids=>{
    const bridge=window.GrtransMapBridge;
    bridge.marker(ids[0]).fire('click',{latlng:bridge.marker(ids[0]).getLatLng()});
    bridge.marker(ids[1]).fire('click',{latlng:bridge.marker(ids[1]).getLatLng()});
  },stations.slice(0,2));
  assert.equal(await pairForm.locator('select[name="from_station_id"]').inputValue(),String(stations[0]));
  assert.equal(await pairForm.locator('select[name="to_station_id"]').inputValue(),String(stations[1]));
  assert.ok(await page.locator('.grtrans-map-unsaved').isVisible());
  await page.keyboard.press('Escape');
  await page.locator('#grtrans-map-workspace').getByRole('button',{name:'Move station'}).click();
  await page.evaluate(id=>{
    const m=window.GrtransMapBridge.marker(id);
    m.fire('click',{latlng:m.getLatLng()});
  },stations[0]);
  await page.waitForURL(/network_entity=stations.*network_edit=/,{timeout:20000});
  const moving=page.locator('#grtrans-network-editor form');
  const oldLat=await moving.locator('input[name="lat"]').inputValue();
  await page.locator('#grtrans-map-workspace').getByRole('button',{name:'Move tentative point north'}).click();
  assert.notEqual(await moving.locator('input[name="lat"]').inputValue(),oldLat,'Keyboard move must edit a draft position');
  await page.keyboard.press('Escape');
  assert.equal(await moving.locator('input[name="lat"]').inputValue(),oldLat,'Escape must cancel tentative move');
  await page.locator('#grtrans-map-workspace').getByRole('button',{name:'Expand or exit fullscreen map'}).click();
  await page.waitForFunction(()=>{
    const stage=document.querySelector('#grtrans-map-workspace');
    return document.fullscreenElement===stage||stage.classList.contains('grtrans-map-maximized');
  });
  const expanded=await page.evaluate(()=>{
    const stage=document.querySelector('#grtrans-map-workspace');
    return document.fullscreenElement===stage||stage.classList.contains('grtrans-map-maximized');
  });
  assert.equal(expanded,true,'Expanded map must use fullscreen or its fallback');
  await page.setViewportSize({width:700,height:900});
  assert.equal(await page.locator('#grtrans-map-workspace .grtrans-map-sidebar').isVisible(),true);
  console.log('LIVE MAP WORKSPACE: tall map, five lists, header fix, selection, Place draft and cancel, pairwise Connect and expand');
} finally {await browser.close();}
