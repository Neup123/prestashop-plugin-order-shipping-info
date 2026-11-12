const assert=require('node:assert/strict');
const path=require('node:path');
const model=require(path.join(__dirname,'../grtransportshipping/views/js/map-workspace-model.js'));
assert.deepEqual(model.MODES,['select','place','move','connect-leg','connect-transfer','preview','draw','edit-coverage',
  'place-area','radius','edit-route']);
assert.ok(model.MODES.every(mode=>model.modesEnabled(mode)),'Stage-2 spatial modes are available');
assert.equal(model.needsDraft('move'),true);
assert.equal(model.needsDraft('draw'),true);
assert.equal(model.needsDraft('radius'),true);
assert.equal(model.needsDraft('preview'),false);
assert.equal(model.validPoint({lat:-33.9,lng:-70.67}),true);
assert.equal(model.validPoint({lat:90.0001,lng:0}),false);
assert.equal(model.validPoint({lat:0,lng:-180}),true);
assert.equal(model.validPoint({lat:0,lng:Infinity}),false);
assert.equal(model.validPoint({lat:'',lng:''}),false,'Empty form values are not the equator');
assert.equal(model.validPoint({lat:null,lng:0}),false);
assert.deepEqual(model.normalizedPoint({lat:'-33.123456789',lng:'-70.123456789'}),{lat:-33.1234568,lng:-70.1234568});
assert.deepEqual(model.nudge({lat:-33,lng:-70},'ArrowUp'),{lat:-32.9995,lng:-70});
assert.deepEqual(model.nudge({lat:-33,lng:-70},'ArrowLeft',true),{lat:-33,lng:-70.00005});
assert.equal(model.nudge({lat:90,lng:0},'ArrowUp'),null,'Boundary rejects invalid coordinate');
assert.equal(model.nudge({lat:0,lng:0},'X'),null);
assert.deepEqual(model.pair('12',13),{from_station_id:12,to_station_id:13});
assert.equal(model.pair(12,12),null,'Self links are not a completed UI draft');
assert.equal(model.pair(0,1),null,'An unset reference cannot look selected');
const data={stations:[{id_station:1},{id_station:2}],
  legs:[{id_leg:31,from_station_id:1,to_station_id:2},{id_leg:32,from_station_id:2,to_station_id:null}],
  transfers:[{id_transfer:41,from_station_id:2,to_station_id:1}],
  branches:[{id_branch:7,origin_station_id:2},{id_branch:8,origin_station_id:1}],
  branch_stock:[{id_branch_stock:61,id_branch:7,quantity:4},{id_branch_stock:62,id_branch:8,quantity:2}]};
assert.equal(model.originFor(data,'branches',7),2);
assert.equal(model.originFor(data,'branch_stock',61),2);
assert.equal(model.originFor(data,'legs',31),1);
assert.equal(model.originFor(data,'transfers',41),2);
assert.deepEqual(model.recordsAt(data,'branches',2).map(x=>x.id_branch),[7]);
assert.deepEqual(model.recordsAt(data,'branch_stock',2).map(x=>x.id_branch_stock),[61]);
assert.deepEqual(model.recordsAt(data,'legs',2).map(x=>x.id_leg),[31,32]);
assert.equal(model.recordId('branch_stock',data.branch_stock[0]),61);
assert.equal(model.originFor(data,'branch_stock',999),0);
const chain={legs:[
  {id_leg:51,mode:'bus',operator_label:'Line 45',from_station_id:1,to_station_id:2,price:10,active:1},
  {id_leg:52,mode:'bus',operator_label:'Line 45',from_station_id:2,to_station_id:3,price:20,active:1},
  {id_leg:53,mode:'ferry',operator_label:'Line 45',from_station_id:3,to_station_id:4,price:40,active:1},
  {id_leg:54,mode:'bus',operator_label:'Unrelated',from_station_id:3,to_station_id:5,price:10,active:1},
]};
const line=model.lineFor(chain,52);
assert.deepEqual(line.stops,[1,2,3]);
assert.equal(line.reorderable,true);
assert.deepEqual(model.reorderLine(line,[1,3,2]).map(leg=>
  [leg.id_leg,leg.from_station_id,leg.to_station_id,leg.stop_order]),
  [[51,1,3,1],[52,3,2,2]]);
assert.equal(model.reorderLine(line,[1,2,2]),null);
assert.equal(model.stationPriceShade(line).get(2),1/3);
assert.equal(model.stationPriceShade(line).get(3),1);
assert.equal(model.lineFor(chain,53).legs.length,1);
assert.equal(model.lineFor({legs:[...chain.legs,
  {id_leg:55,mode:'bus',operator_label:'Line 45',from_station_id:2,to_station_id:4,price:15}]},51).reorderable,false);
console.log('Stage-1 map workspace modes, signed positions, pairs and contextual selection passed');
