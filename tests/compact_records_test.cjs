const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {filterRecords,sortRecords,normalize,KEEP,FIELDS,GROUPS,SIZE} =
  require('../grtransportshipping/views/js/compact-records.js');
const rows=[
  {id:1,label:'Αθήνα',search:'Athens ferry 26221',mode:'ferry',availability:'Available',verification:'Unverified',price:12},
  {id:2,label:'Patras',search:'Patras bus 00011',mode:'bus',availability:'Draft',verification:'Verified',price:5},
  {id:3,label:'Thessaloniki',search:'Thessaloniki ferry 52001',mode:'ferry',availability:'Suspended',verification:'Verified',price:20},
];
assert.deepEqual(filterRecords(rows,'ferry').map(r=>r.id),[1,3]);
assert.deepEqual(filterRecords(rows,'', {mode:'bus'}).map(r=>r.id),[2]);
assert.deepEqual(filterRecords(rows,'', {mode:'ferry',availability:'Suspended'}).map(r=>r.id),[3]);
assert.deepEqual(filterRecords(rows,'', {verification:'Verified'}).map(r=>r.id),[2,3]);
assert.deepEqual(filterRecords(rows,'no such route'),[]);
assert.equal(normalize('ÁTHENS'),normalize('athens'));
assert.deepEqual(sortRecords(rows,'price').map(r=>r.id),[2,1,3]);
assert.deepEqual(sortRecords(rows,'recent').map(r=>r.id),[3,2,1]);
assert.deepEqual(sortRecords(rows,'original').map(r=>r.id),[1,2,3]);
assert.deepEqual(rows.map(r=>r.id),[1,2,3],'Sorting must not mutate the server order');
assert.deepEqual(KEEP.legs,[1,2,3,4,5,6,28,31],'Map stop order must not change the default visible leg fields');
assert.ok(GROUPS.legs[0][1].includes('stop_order'),'Stop order is an actual network field');
assert.deepEqual(KEEP.routes,[1,2,3,4,5,7,9,10,12]);
assert.ok(KEEP.stations.includes(15) && KEEP.stations.includes(1));
assert.equal(FIELDS.active,'Enabled');
assert.ok(GROUPS.stations.some(([,members])=>members.includes('active')));
assert.ok(KEEP.stations.length<14 && KEEP.transfers.length<8);
assert.equal(FIELDS.area_lat,'Area latitude');
assert.equal(SIZE.area_lat,'s');
assert.ok(GROUPS.branch_stock.some(([name,fields])=>fields.includes('id_product_attribute')));
assert.deepEqual(GROUPS.legs.map(([title])=>title),['Connection','Price','Timing','Direct service area','Advanced']);
const source=readFileSync(require.resolve('../grtransportshipping/views/js/compact-records.js'),'utf8');
const styles=readFileSync(require.resolve('../grtransportshipping/views/css/compact-records.css'),'utf8');
const workspace=readFileSync(require.resolve('../grtransportshipping/views/js/map-workspace.js'),'utf8');
const crm=readFileSync(require.resolve('../grtransportshipping/views/js/crm-records.js'),'utf8');
assert.match(source,/checkbox\.type='checkbox'/,'row selection must be a checkbox');
assert.doesNotMatch(source,/setAttribute\('aria-pressed',String\(active\)\)/);
for(const key of ['ArrowDown','ArrowUp','Enter','Delete','Spacebar'])
  assert.ok(source.includes(key),'keyboard action missing: '+key);
assert.match(source,/addEventListener\('toggle',event=>/);
assert.match(source,/addEventListener\('pointerdown',event=>/);
assert.match(source,/addEventListener\('keydown',event=>/);
assert.match(source,/grtrans-network-list\[data-grtrans-entity\]/);
assert.match(source,/\.grtrans-editor-layout\[data-grtrans-server-groups\]/);
assert.doesNotMatch(source,/el\('fieldset','grtrans-editor-group'\)/);
assert.match(source,/\[data-grtrans-product-picker\]/);
assert.match(source,/filter_alt/);assert.match(source,/material-icons','sort'/);
assert.match(styles,/width:max-content;min-width:0!important/);
assert.doesNotMatch(styles,/min-width:min\(24rem,90vw\)/);
assert.match(workspace,/if \(button && !button\.checked\) button\.click\(\)/);
assert.match(workspace,/grtrans-map-pending-add/,'Explicit Add intent survives entity navigation');
assert.match(workspace,/add\.addEventListener\('click',\(\)=>editNetwork\(activeEntity,0,'select'\)\)/);
assert.match(crm,/grtrans-columns:/);
assert.match(crm,/localStorage\.setItem/);
assert.match(crm,/grtrans-columns-chooser/);
assert.match(crm,/grtrans-inline-state/);
assert.match(crm,/grtrans-toggle-active/);
assert.match(crm,/grtrans:crm-saved/);
assert.match(crm,/grtrans_edit/);
assert.match(crm,/grtrans_edit_proof/);
assert.match(crm,/grtrans_network_action/);
assert.doesNotMatch(workspace,/function stockQuantityEditor/);
assert.match(workspace,/GrtransCRM\?\.openEditor\('branch_stock'/);
console.log('Compact record filtering, ordering, terminology and view models passed');
