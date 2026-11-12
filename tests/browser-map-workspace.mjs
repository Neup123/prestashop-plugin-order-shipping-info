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
  // A stage element can exist before restoreView and Leaflet size reconciliation.
  const waitForWorkspace=async(entity,view=null)=>{
    await page.waitForFunction(({entity,view})=>{
      const workspace=window.GrtransMapWorkspace;
      if(workspace?.stage?.dataset.grtransViewReady!=='true')return false;
      const list=workspace.stage.querySelector('section.grtrans-network-list:not([hidden])');
      if(list?.dataset.grtransEntity!==entity)return false;
      if(!view)return true;
      const map=window.GrtransMapBridge.map,centre=map.getCenter();
      return Math.abs(centre.lat-view.lat)<1e-5 && Math.abs(centre.lng-view.lng)<1e-5
        && map.getZoom()===view.zoom;
    },{entity,view},{timeout:20000});
  };
  await stage.waitFor({timeout:20000});
  await waitForWorkspace('stations');
  assert.equal(await stage.locator('.grtrans-map-sidebar').count(),1);
  assert.equal(await stage.locator('.grtrans-map-column').count(),1);
  const map=page.locator('#grtrans-network-map');
  const height=await map.evaluate(element=>element.getBoundingClientRect().height);
  assert.ok(height>=600,'Map must use a substantial viewport-relative height: '+height);
  assert.equal(await stage.locator('section.grtrans-network-list').count(),1,'Only the active server-rendered list exists');
  assert.equal(await stage.locator('section.grtrans-network-list:not([hidden])').count(),1);
  assert.match(await stage.locator('[data-grtrans-entity="stations"] thead').innerText(),/Can dispatch parcels/);
  assert.doesNotMatch(await stage.locator('[data-grtrans-entity="stations"] thead').innerText(),/is origin hub/);
  const before=await page.evaluate(()=>{
    const bridge=window.GrtransMapBridge;
    return {id:bridge.map._leaflet_id,lat:bridge.map.getCenter().lat,lng:bridge.map.getCenter().lng,zoom:bridge.map.getZoom()};
  });
  const fallbackContext=await browser.newContext({javaScriptEnabled:false});
  await fallbackContext.addCookies(await page.context().cookies());
  try {
    const fallback=await fallbackContext.newPage();
    await fallback.goto(adminUrl,{waitUntil:'domcontentloaded',timeout:45000});
    assert.equal(await fallback.locator('#grtrans-network section.grtrans-network-list table').count(),1);
    assert.ok(await fallback.locator('#grtrans-network .grtrans-network-actions .material-icons').count()>0);
    assert.ok(await fallback.locator('#grtrans-network .grtrans-network-actions input[type=checkbox]').count()>0);
    assert.equal(await fallback.locator('#grtrans-network-editor [data-grtrans-network-state]').count(),1);
  } finally {await fallbackContext.close();}
  await stage.locator('.grtrans-network-toolbar a').filter({hasText:'Transport connections'}).click();
  await page.waitForURL(/network_entity=legs/,{waitUntil:'domcontentloaded',timeout:20000});
  await waitForWorkspace('legs',before);
  const during=await page.evaluate(()=>{
    const bridge=window.GrtransMapBridge;
    return {id:bridge.map._leaflet_id,lat:bridge.map.getCenter().lat,lng:bridge.map.getCenter().lng,zoom:bridge.map.getZoom()};
  });
  assert.ok(Math.abs(during.lat-before.lat)<1e-5 && Math.abs(during.lng-before.lng)<1e-5);
  assert.equal(during.zoom,before.zoom,'Server navigation must restore the saved map view');
  assert.equal(await stage.locator('section.grtrans-network-list:not([hidden])').getAttribute('data-grtrans-entity'),'legs');
  await stage.locator('.grtrans-network-toolbar a').filter({hasText:'Stations'}).click();
  await page.waitForURL(/network_entity=stations/,{waitUntil:'domcontentloaded',timeout:20000});
  await waitForWorkspace('stations',before);
  const firstRow=stage.locator('[data-grtrans-entity="stations"] tr[data-grtrans-record-id]').first();
  const checkbox=firstRow.locator('input[type=checkbox][data-grtrans-select-record]');
  await checkbox.check();
  assert.equal(await checkbox.isChecked(),true);
  await firstRow.focus();await page.keyboard.press('Space');
  assert.equal(await checkbox.isChecked(),false,'Space toggles the focused row');
  await page.keyboard.press('Space');assert.equal(await checkbox.isChecked(),true);
  let deletePrompt='';
  page.once('dialog',async dialog=>{deletePrompt=dialog.message();await dialog.dismiss();});
  await firstRow.focus();await page.keyboard.press('Delete');
  assert.match(deletePrompt,/Delete/,'Delete key must use the existing confirmation');
  const secondRow=stage.locator('[data-grtrans-entity="stations"] tr[data-grtrans-record-id]').nth(1);
  if(await secondRow.count()) {
    await page.keyboard.press('ArrowDown');
    assert.equal(await secondRow.evaluate(row=>document.activeElement===row),true);
    const firstDetails=firstRow.locator('details.grtrans-record-details');
    const secondDetails=secondRow.locator('details.grtrans-record-details');
    await firstDetails.locator('summary').click();
    await page.waitForTimeout(100); // Let the native toggle event settle.
    await secondDetails.evaluate(panel=>{panel.open=true;});
    await page.waitForFunction(()=>document.querySelectorAll('#grtrans-network details.grtrans-record-details[open]').length===1);
    assert.equal(await firstDetails.getAttribute('open'),null,'Only one Details panel remains open');
    await page.keyboard.press('Escape');
    await page.waitForFunction(()=>document.querySelectorAll('#grtrans-network details.grtrans-record-details[open]').length===0);
    await firstDetails.locator('summary').click();
    await stage.locator('.grtrans-map-status').click();
    assert.equal(await firstDetails.getAttribute('open'),null,'Click outside closes Details');
  }
  assert.ok(await stage.locator('.grtrans-record-filters .material-icons').count()>0,'Sort icon visible');
  const networkStatus=stage.locator('[data-grtrans-network-state]');
  assert.ok(await networkStatus.isVisible());
  await stage.locator('#grtrans-network-editor input[name="name"]').fill('Not yet saved');
  assert.equal(await networkStatus.getAttribute('data-state'),'unsaved');
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
  await page.waitForURL(/network_entity=legs/,{waitUntil:'domcontentloaded',timeout:20000});
  await waitForWorkspace('legs');
  const pairForm=page.locator('#grtrans-network-editor form');
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
  // Subscribe before firing the marker: the old Move handler issued two
  // competing location.assign calls and aborted the first navigation.
  const movingNavigation=page.waitForURL(/network_entity=stations.*network_edit=/,
    {waitUntil:'domcontentloaded',timeout:20000});
  await page.evaluate(id=>{
    const m=window.GrtransMapBridge.marker(id);
    m.fire('click',{latlng:m.getLatLng()});
  },stations[0]);
  await movingNavigation;
  await waitForWorkspace('stations');
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
  console.log('LIVE MAP WORKSPACE: one active dataset, server fallback, checkbox/keyboard, exclusive popovers, draft and map actions');
} finally {await browser.close();}
