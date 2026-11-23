import assert from 'node:assert/strict';
import {chromium} from 'playwright';

const adminUrl=process.env.ADMIN_URL;
const user=process.env.TEST_ADMIN_EMAIL;
const password=process.env.TEST_ADMIN_PASSWORD;
if(!adminUrl||!user||!password)throw new Error('Missing disposable admin-browser settings');

const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1600,height:1000},deviceScaleFactor:2});
let passed=0;
const confirm=(label)=>{console.log('MANUAL-ROUND '+label+' PASS');passed++;};
const visit=async(entity='stations')=>{
  const url=new URL(adminUrl);
  if(entity!=='stations')url.searchParams.set('network_entity',entity);
  await page.goto(url.toString(),{waitUntil:'domcontentloaded',timeout:45000});
  await page.locator('#grtrans-map-workspace').waitFor({timeout:20000});
};
const stationRow=name=>page.locator('#grtrans-network .grtrans-network-list[data-grtrans-entity="stations"] tbody tr')
  .filter({hasText:name});
const legRow=name=>page.locator('#grtrans-network .grtrans-network-list[data-grtrans-entity="legs"] tbody tr')
  .filter({hasText:name});
const visibleConnection=()=>page.locator('#grtrans-network-map svg path.grtrans-road-fallback, #grtrans-network-map svg path.grtrans-land-road, #grtrans-network-map svg path.grtrans-land-gradient');
const removeRow=async(row)=>{
  page.once('dialog',dialog=>dialog.accept());
  await row.locator('button[data-grtrans-row-action="delete"]').click();
  await page.getByText('Network record updated.',{exact:true}).first().waitFor({timeout:20000});
};
const submitCreatePRG=async(button,entity)=>{
  const [response]=await Promise.all([
    page.waitForResponse(result=>
      result.request().isNavigationRequest() &&
      result.request().method()==='POST' &&
      result.status()===303,{timeout:20000}),
    button.click(),
  ]);
  assert.equal(response.status(),303,'Successful create must return 303 See Other');
  assert.equal(new URL(page.url()).searchParams.get('network_entity'),entity,
    'Redirect must return to the edited network entity');
  assert.doesNotMatch(page.url(),/grtrans_network_action/,'Mutation must not remain in GET URL');
};
const assertReloadGET=async()=>{
  const response=await page.reload({waitUntil:'domcontentloaded'});
  assert.equal(response.request().method(),'GET','Reload after create must GET, never replay POST');
};
const addStation=async(name,lat,lng)=>{
  await visit();
  await page.getByRole('button',{name:'Place station',exact:true}).click();
  const form=page.locator('#grtrans-network-editor form');
  await form.locator('[name="name"]').fill(name);
  await form.locator('[name="address_text"]').fill('Disposable N7 regression station');
  await form.locator('[name="lat"]').fill(String(lat));
  await form.locator('[name="lng"]').fill(String(lng));
  await form.locator('[name="inner_radius_km"]').fill('2');
  await form.locator('[name="outer_radius_km"]').fill('5');
  await submitCreatePRG(form.getByRole('button',{name:'Create record'}),'stations');
  await page.getByText('Network record created.',{exact:true}).first().waitFor({timeout:20000});
  const row=stationRow(name);
  assert.equal(await row.count(),1,'Station created in disposable PrestaShop');
  await assertReloadGET();
  assert.equal(await row.count(),1,'Reload must not create a duplicate station');
  assert.equal(await page.getByText('Network record created.',{exact:true}).count(),0,
    'One-time success flash must disappear after refresh');
  return Number((await row.locator('td').first().innerText()).trim());
};

try {
  await page.goto('http://localhost:8080/admin-dev/',{waitUntil:'domcontentloaded',timeout:45000});
  await page.locator('input[name="email"],input[type="email"]').first().fill(user);
  await page.locator('input[name="passwd"],input[type="password"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  await visit();

  // N1: supported 17px Material Details icon on both tables.
  let glyph=page.locator('#grtrans-network .grtrans-record-details summary .material-icons').first();
  assert.equal(await glyph.textContent(),'info');
  assert.ok((await glyph.boundingBox()).width<35,'Station Details glyph must fit 30px cell');
  await page.goto(adminUrl,{waitUntil:'domcontentloaded'});
  glyph=page.locator('#grtrans-routes .grtrans-record-details summary .material-icons').first();
  assert.equal(await glyph.textContent(),'info');
  assert.ok((await glyph.boundingBox()).width<35,'Route Details glyph must fit 30px cell');
  confirm('N1 icon');

  // N2: the chooser must be outside the map/BO stacking context, clickable,
  // and completely to the right of the 208px back-office navigation.
  const trigger=page.locator('#grtrans-routes .grtrans-columns-chooser > summary').first();
  await trigger.click();
  const chooser=page.locator('body > .grtrans-columns-panel');
  await chooser.waitFor({state:'visible'});
  const bounds=await chooser.boundingBox();
  assert.ok(bounds && bounds.x>=208 && bounds.x+bounds.width<=1600,
    'Column chooser overlaps BO navigation or clips viewport: '+JSON.stringify(bounds));
  const choice=chooser.locator('input[type="checkbox"]:not(:disabled)').first();
  const original=await choice.isChecked();
  await choice.click();
  assert.notEqual(await choice.isChecked(),original,'Chooser checkbox must be mouse-clickable');
  await choice.click();assert.equal(await choice.isChecked(),original);
  await trigger.click();
  confirm('N2 chooser');

  // N4: real seed terminal names survive unchanged save; price edit persists.
  const route1=page.locator('#grtrans-routes tbody tr[data-grtrans-record-id="1"]');
  await route1.getByRole('link',{name:/Edit/}).click();
  let form=page.locator('#grtrans-route-editor form');
  assert.equal(await form.locator('select[name="origin_name"]').inputValue(),'Athens Kifisos KTEL');
  assert.equal(await form.locator('select[name="station_name"]').inputValue(),'Patras KTEL station');
  const initial=Number(await form.locator('[name="price"]').inputValue());
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated',{exact:false}).first().waitFor({timeout:20000});
  assert.ok((await page.locator('#grtrans-routes tbody tr[data-grtrans-record-id="1"]').innerText()).includes(initial.toFixed(2)));
  await page.locator('#grtrans-routes tbody tr[data-grtrans-record-id="1"]').getByRole('link',{name:/Edit/}).click();
  form=page.locator('#grtrans-route-editor form');
  assert.equal(await form.locator('[name="city"]').inputValue(),'Patras','Derivable seed city backfilled');
  const changed=(initial+0.75).toFixed(2);
  await form.locator('[name="price"]').fill(changed);
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated',{exact:false}).first().waitFor({timeout:20000});
  await page.goto(adminUrl,{waitUntil:'domcontentloaded'});
  await page.locator('#grtrans-routes tbody tr[data-grtrans-record-id="1"]').getByRole('link',{name:/Edit/}).click();
  form=page.locator('#grtrans-route-editor form');
  assert.equal(Number(await form.locator('[name="price"]').inputValue()),Number(changed));
  await form.locator('[name="price"]').fill(initial.toFixed(2));
  await form.locator('button[name="grtrans_save"]').click();
  await page.getByText('Route dataset updated',{exact:false}).first().waitFor({timeout:20000});
  confirm('N4 seeded edit and reload');

  await visit();
  // N3: base tiles must request an unkeyed OpenStreetMap endpoint, with
  // coverage markers and legend still instantiated.
  const tile=page.locator('#grtrans-network-map img.leaflet-tile').first();
  await tile.waitFor({timeout:20000});
  assert.match(await tile.getAttribute('src'),/tile\.openstreetmap\.org/);
  assert.ok(await page.locator('#grtrans-network-map .leaflet-marker-icon').count()>0,'Station markers persist');
  assert.ok(await page.locator('#grtrans-zone-legend .grtrans-zone-key').count()>0,'Coverage legend persists');
  confirm('N3 default map source and overlays');

  // N5: five station controls show full value within the 440px dock.
  await page.getByRole('button',{name:'Place station',exact:true}).click();
  const dimensions=await page.evaluate(()=>{
    const panel=document.querySelector('.grtrans-map-sidebar').getBoundingClientRect();
    const fields=['name','type','address_text','is_origin_hub','active'];
    return fields.map(field=>{
      const box=document.querySelector('#grtrans-network-editor [name="'+field+'"]').getBoundingClientRect();
      return {field,width:box.width,left:box.left,right:box.right,panelRight:panel.right};
    });
  });
  for(const field of dimensions)assert.ok(field.width>=150 && field.right<=field.panelRight+2,
    'Station form field is clipped: '+JSON.stringify(field));
  await visit();
  const tableScroll=page.locator('#grtrans-network .grtrans-network-list[data-grtrans-entity="stations"] .table-responsive');
  const horizontal=await tableScroll.evaluate(el=>({
    view:el.clientWidth,total:el.scrollWidth,overflow:getComputedStyle(el).overflowX
  }));
  assert.ok(horizontal.total>horizontal.view && ['auto','scroll'].includes(horizontal.overflow),
    'Station action columns must remain accessible by horizontal scrolling: '+JSON.stringify(horizontal));
  confirm('N5 station form and table');

  // N6/N7: a newly saved leg paints a line before/without OSRM, survives
  // reload, and leaves no phantom station dependency after it is deleted.
  const source=await addStation('CI-N7-source',38.08,23.6);
  const target=await addStation('CI-N7-target',38.12,23.65);
  await visit('legs');
  await page.getByRole('button',{name:'Add legs',exact:true}).click();
  form=page.locator('#grtrans-network-editor form');
  await form.locator('[name="from_station_id"]').selectOption(String(source));
  await form.locator('[name="to_station_id"]').selectOption(String(target));
  await form.locator('[name="operator_label"]').fill('CI-N6N7-connection');
  await form.locator('[name="price"]').fill('3.33');
  await submitCreatePRG(form.getByRole('button',{name:'Create record'}),'legs');
  await page.getByText('Network record created.',{exact:true}).first().waitFor({timeout:20000});
  assert.equal(await legRow('CI-N6N7-connection').count(),1);
  await page.waitForFunction(()=>[...document.querySelectorAll('#grtrans-network-map path')]
    .some(p=>p.classList.contains('grtrans-land-road')||p.classList.contains('grtrans-land-gradient')));
  // The defect in #163 was exactly page.reload() after a successful POST.
  // A real 303 now means refresh and browser Back cannot duplicate the leg.
  await assertReloadGET();
  assert.equal(await legRow('CI-N6N7-connection').count(),1,
    'Reload after create must preserve one connection, not add another');
  await page.goBack({waitUntil:'domcontentloaded'});
  await visit('legs');
  assert.equal(await legRow('CI-N6N7-connection').count(),1,
    'Back and return must not duplicate the connection');
  await page.waitForFunction(()=>[...document.querySelectorAll('#grtrans-network-map path')]
    .some(p=>(p.classList.contains('grtrans-land-road')||p.classList.contains('grtrans-land-gradient'))
      && getComputedStyle(p).stroke!=='none'));
  assert.ok(await visibleConnection().count()>0,'Visible leg line must persist after reload');
  confirm('N6 saved line and reload');


  // #163: the transfer editor also creates records and must use PRG.
  await visit('transfers');
  const transferRows=page.locator('#grtrans-network .grtrans-network-list[data-grtrans-entity="transfers"] tbody tr');
  const transfersBefore=await transferRows.count();
  await page.getByRole('button',{name:'Add transfer',exact:true}).click();
  form=page.locator('#grtrans-network-editor form');
  await form.locator('[name="from_station_id"]').selectOption(String(source));
  await form.locator('[name="to_station_id"]').selectOption(String(target));
  await form.locator('[name="connection_type"]').selectOption('walk');
  await form.locator('[name="price"]').fill('1.00');
  await submitCreatePRG(form.getByRole('button',{name:'Create record'}),'transfers');
  await page.getByText('Network record created.',{exact:true}).first().waitFor({timeout:20000});
  assert.equal(await transferRows.count(),transfersBefore+1,'One transfer created');
  const transferId=await transferRows.evaluateAll(rows=>
    Math.max(...rows.map(row=>Number(row.dataset.grtransRecordId))));
  assert.ok(transferId>0,'New transfer has persisted ID');
  await assertReloadGET();
  assert.equal(await transferRows.count(),transfersBefore+1,'Reload must not duplicate transfer');
  await removeRow(page.locator('#grtrans-network .grtrans-network-list[data-grtrans-entity="transfers"] tbody tr[data-grtrans-record-id="'+transferId+'"]'));
  await visit('transfers');
  assert.equal(await transferRows.count(),transfersBefore,'Transfer removed without stale dependency');
  confirm('PRG stations, legs, transfers and Back/Reload');

  await removeRow(legRow('CI-N6N7-connection'));
  await visit('stations');
  await removeRow(stationRow('CI-N7-target'));
  await visit();
  assert.equal(await stationRow('CI-N7-target').count(),0,'N7 station must be absent after deletion/reload');
  confirm('N7 fresh dependency after leg deletion');

  console.log('MANUAL-ROUND total: '+passed+' findings passed, 0 failed (disposable PrestaShop 9.2.1 / 1600x1000 / DSF 2)');
} catch(error) {
  console.error('MANUAL-ROUND failed after '+passed+' passes:',error);
  await page.screenshot({path:'/tmp/prestashop-n1-n7-ci-failure.png',fullPage:true}).catch(()=>{});
  throw error;
} finally {
  await browser.close();
}
