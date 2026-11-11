const assert=require('node:assert/strict');
const model=require('../grtransportshipping/views/js/map-spatial-model.js');
const sample={type:'MultiPolygon',coordinates:[
  [[[23.1,37.1],[23.2,37.1],[23.2,37.2],[23.1,37.1]],
   [[23.13,37.13],[23.14,37.13],[23.13,37.14],[23.13,37.13]]],
  [[[24.0,38.0],[24.1,38.0],[24.1,38.1],[24.0,38.0]]]
]};
const parsed=model.open(JSON.stringify({type:'Feature',properties:{name:'ignored'},geometry:sample}),'polygon');
assert.equal(parsed.type,'MultiPolygon');
assert.equal(model.assess(parsed,'polygon').ok,true);
assert.equal(model.polygons(parsed).length,2);
assert.equal(model.polygons(parsed)[0].length,2,'holes retained');
const moved=model.move(parsed,'polygon',0,1,0,{lat:-34.123456789,lng:-70.123456789});
assert.equal(moved.coordinates[0][1][0][0],-70.1234568);
assert.deepEqual(moved.coordinates[0][1][0],moved.coordinates[0][1].at(-1),'closed ring stays closed');
assert.deepEqual(parsed.coordinates,sample.coordinates,'source is not mutated');
const withVertex=model.insert(parsed,'polygon',0,0,1,{lat:37.15,lng:23.15});
assert.equal(withVertex.coordinates[0][0].length,5);
assert.equal(model.remove(withVertex,'polygon',0,0,2).coordinates[0][0].length,4);
assert.equal(model.remove(parsed,'polygon',0,0,0),null,'cannot leave incomplete ring');
const extra=model.appendRing(parsed,[[24.5,38.5],[24.6,38.5],[24.6,38.6]],true);
assert.equal(extra.type,'MultiPolygon');assert.equal(extra.coordinates.length,3);
const moreHole=model.appendRing(parsed,[[23.11,37.11],[23.12,37.11],[23.12,37.12]],false);
assert.equal(moreHole.coordinates[0].length,3);
const holeOnSecond=model.appendRing(parsed,[[24.02,38.02],[24.03,38.02],[24.03,38.03]],false,1);
assert.equal(holeOnSecond.coordinates[1].length,2);
assert.equal(holeOnSecond.coordinates[0].length,2);
assert.equal(model.deletePart(parsed,'polygon',0,1).coordinates[0].length,1);
assert.equal(model.deletePart({type:'Polygon',coordinates:[sample.coordinates[1][0]]},'polygon',0,0),null);
const route={type:'MultiLineString',coordinates:[[[24,38],[25,39]],[[25,39],[26,40]]]};
assert.equal(model.assess(route,'line').ok,true);
assert.equal(model.open(JSON.stringify(route),'line').type,'MultiLineString');
assert.equal(model.move(route,'line',1,0,0,{lat:-30,lng:-60}).coordinates[1][0][0],-60);
assert.equal(model.appendSegment(route,[{lat:39,lng:27},{lat:40,lng:28}]).coordinates.length,3);
assert.equal(model.remove(route,'line',0,0,0),null);
assert.equal(model.deletePart(route,'line',0,0).coordinates.length,1);
assert.equal(model.assess({type:'Polygon',coordinates:[[[181,0],[0,0],[0,1],[181,0]]]},'polygon').ok,false);
assert.equal(model.assess({type:'Polygon',coordinates:[[[0,0],[1,0],[1,1],[0,1]]]},'polygon').ok,false,'closure required');
assert.equal(model.assess({type:'LineString',coordinates:[[0,0]]},'line').ok,false);
assert.equal(model.assess({type:'MultiPolygon',coordinates:Array.from({length:13},()=>sample.coordinates[1])},'polygon').ok,false);
assert.equal(model.assess({type:'Polygon',coordinates:Array.from({length:21},()=>sample.coordinates[1][0])},'polygon').ok,false);
assert.equal(model.assess({type:'LineString',coordinates:[[0,0],[180,-90]]},'line').ok,true);
assert.equal(model.point({lat:-40.123456789,lng:-150.123456789}).lat,-40.1234568);
assert.equal(model.point({lat:'',lng:0}),null);
assert.equal(model.point({lat:91,lng:0}),null);
assert.equal(model.point({lat:0,lng:-180}).lng,-180);
const centre={lat:37.9,lng:23.7};
for(const radius of [0.1,1,20,100]){
  const handle=model.eastHandle(centre,radius);
  assert.ok(handle,'radius handle exists');
  assert.ok(Math.abs(model.distanceKm(centre,handle)-radius)<0.0001,'geodesic reach '+radius);
}
console.log('Stage-2 spatial model: polygon rings/islands/holes, route segments, bounds, radius handles PASS');
