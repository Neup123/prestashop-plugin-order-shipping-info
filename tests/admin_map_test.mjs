import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../grtransportshipping/views/js/network-admin.js', import.meta.url), 'utf8');
const geometrySource = fs.readFileSync(new URL('../grtransportshipping/views/js/coverage-geometry.js', import.meta.url), 'utf8');
function fixture(data, options = {}) {
  const make = () => ({
    children: [], style: {}, attrs: {}, events: {}, disabled: true, hidden: true,
    addEventListener(type, callback) { this.events[type] = callback; },
    appendChild(child) { this.children.push(child); },
    setAttribute(name, value) { this.attrs[name] = value; },
    focus() {}, requestSubmit() { this.submitted = true; }
  });
  const ids = ['grtrans-network-map','grtrans-map-status','grtrans-zone-legend',
    'grtrans-map-link','grtrans-map-link-form','grtrans-link-cancel',
    'grtrans-link-from','grtrans-link-to','grtrans-link-type','grtrans-link-price','grtrans-link-override',
    'grtrans-network-field-lat','grtrans-network-field-lng',
    'grtrans-network-field-area_lat','grtrans-network-field-area_lng',
    'grtrans-map-preview','grtrans-map-preview-clear','grtrans-preview-status'];
  const elements = Object.fromEntries(ids.map(id => [id, make()]));
  elements['grtrans-network-map'].dataset = {config: JSON.stringify({
    stations: [], legs: [], transfers: [], cost: {}, leafletImagePath: '/leaflet/',
    ...data
  })};
  const calls = {circles: [], lines: [], polygons: [], markers: [], mapEvents: {}, fits: [], views: []};
  const layer = (type, entry) => {
    const value = {
      events: {}, addTo() { this.removed = false; return this; },
      bindTooltip(content) { this.tooltip = content; return this; },
      on(event, fn) { this.events[event] = fn; return this; }, setLatLng(p) { this.point = p; },
      getLatLng() { return this.point; }, remove() { this.removed = true; },
      setStyle(style) { this.style = {...style}; return this; }
    };
    entry.layer = value; calls[type].push(entry); return value;
  };
  const panes = {};
  const map = {
    createPane(name) { panes[name] = {style: {}}; return panes[name]; },
    setView(...args) { calls.views.push(args); return this; },
    fitBounds(...args) { calls.fits.push(args); }, on(type, fn) { calls.mapEvents[type] = fn; },
    removeLayer(item) { item.removed = true; }
  };
  class DefaultIcon { constructor(options = {}) { this.options = options; } }
  const L = {
    Icon: {Default: DefaultIcon}, DomEvent: {stopPropagation() {}},
    map() { return map; }, tileLayer() { return {addTo() {}}; },
    circle(point, options) { return layer('circles', {point, options}); },
    marker(point, options) { return layer('markers', {point, options}); },
    polyline(points, options) { return layer('lines', {points, options}); },
    polygon(points, options) { return layer('polygons', {points, options}); }
  };
  const runtimeWindow = {L};
  const runtime = {
    window: runtimeWindow, L, Map,
    fetch: options.fetch || (async () => ({ok:true,json:async () => ({
      code:'Ok',routes:[{geometry:{coordinates:[[23.7,38.0],[23.72,38.02],[23.75,38.05]]}}]
    })})), document: {
      getElementById(id) { return elements[id] || null; },
      createElement: make
    }
  };
  vm.runInNewContext(geometrySource, runtime);
  vm.runInNewContext(source, runtime);
  return {elements, calls, map, panes, geometry: runtimeWindow.GrtransCoverage};
}
const station = (id, name, lat, lng) => ({
  id_station: id, name, type:'bus', lat, lng,
  inner_radius_km: 2, outer_radius_km: 10, is_origin_hub: id === 1 ? 1 : 0
});
const kmDistance = (a, b) => Math.hypot((a[0] - b[0]) * 111.195,
  (a[1] - b[1]) * 111.195 * Math.cos((a[0] + b[0]) * Math.PI / 360));
const lengthKm = points => points.slice(1).reduce((total, p, i) =>
  total + kmDistance(p, points[i]), 0);
const polygonContains = (p, ring) => {
  let hit = false;
  for (let i=0,j=ring.length-1;i<ring.length;j=i++) {
    const a=ring[i],b=ring[j];
    if ((a[0]>p[0])!==(b[0]>p[0]) &&
      p[1]<(b[1]-a[1])*(p[0]-a[0])/(b[0]-a[0])+a[1]) hit=!hit;
  }
  return hit;
};
const polygon = JSON.stringify({type:'Polygon',coordinates:[[
  [23.6,37.9],[23.72,37.9],[23.73,38.0],[23.64,38.03],[23.6,37.9]
]]});
const isochrone = JSON.stringify({type:'Polygon',coordinates:[[
  [23.5,37.7],[23.6,37.72],[23.66,37.79],[23.55,37.85],[23.5,37.7]
]]});
const original = fixture({
  stations:[station(1,'First',37.9,23.6),station(2,'Second',38.2,23.9)],
  legs:[{id_leg:9,from_station_id:1,to_station_id:null,
    mode:'other',area_lat:37.8,area_lng:23.5,area_radius_km:4,
    area_name:'Third area',price:5}]
});
assert.equal(original.panes['grtrans-coverage-patches'].style.zIndex,'390');
assert.equal(original.calls.circles.length,0,'No Leaflet stamped circle paths');
assert.equal(original.calls.polygons.length,5,'Dynamic area first, two station inner+outer pairs');
assert.equal(original.calls.polygons[0].options.fillOpacity,.24);
assert.ok(original.calls.polygons.every(p=>p.options.pane==='grtrans-coverage-patches'));
assert.equal(original.calls.markers.length,2,'Station pins remain independently clickable');
assert.equal(original.elements['grtrans-zone-legend'].children.length,3);
const radiusRing=original.calls.polygons[1].points;
assert.ok(Math.max(...radiusRing.map(p=>kmDistance(p,[37.9,23.6]))) -
  Math.min(...radiusRing.map(p=>kmDistance(p,[37.9,23.6]))) < .06,
  'A walking-radius definition renders as an actual circle (panel A)');
const postalStation={...station(1,'Postal reach',38,23.65),
  coverage_kind:'postal_polygon',coverage_geojson:polygon};
const postal=fixture({stations:[postalStation]});
assert.equal(postal.calls.polygons.length,2,
  'Postal reach has one exact outer polygon plus its shape-clipped inner tier');
assert.equal(postal.calls.polygons[0].points.length,5,
  'Postal polygon geometry is used verbatim');
assert.equal(postal.calls.polygons[0].points[0][1],23.6);
const iso=fixture({stations:[{...station(1,'Walking routes',38,23.6),
  coverage_kind:'walking_isochrone',coverage_geojson:isochrone}]});
assert.equal(iso.calls.polygons.length,1,
  'A distant isochrone not intersecting the station inner reach has only its outer footprint');
assert.equal(iso.calls.polygons[0].points.length,5,'Isochrone polygon not a circle');
const empty=fixture({});
assert.equal(empty.calls.fits.length,0);
const orphan=fixture({legs:[{id_leg:4,from_station_id:9,to_station_id:null,
  area_lat:38,area_lng:23.6,area_radius_km:3,price:3}]});
assert.equal(orphan.calls.polygons.length,0,'Orphan direct-area service has no coverage');
assert.ok(postal.calls.fits.length===1 && postal.calls.fits[0][0].some(p=>p[1]===23.6),
  'Polygon extents, not arbitrary radius, determine map framing');
console.log('Panel A: exact postal polygon / walking radius / isochrone, pane and fit passed');

/* Panel I: tier definitions are geometry data and work for circles and
 * polygonal reach. The far spread stays LIGHTER than the normal inner fill. */
assert.equal(original.calls.polygons[1].options.className.includes('tier-far'),true);
assert.equal(original.calls.polygons[2].options.className.includes('tier-normal'),true);
assert.ok(original.calls.polygons[1].options.fillOpacity <
  original.calls.polygons[2].options.fillOpacity,
  'Far zone visibly lighter than normal reach (panel I)');
assert.equal(original.calls.polygons[2].options.stroke,false,
  'Normal wash has no spurious interior circle outline');
assert.equal(postal.calls.polygons[0].points.length,5,
  'Recorded postal polygon remains the true outer geometry');
assert.ok(postal.calls.polygons[1].points.length >= 3,
  'Inner postal tier is clipped to actual polygon reach, not an invented full disc');
assert.equal(iso.calls.polygons[0].options.className.includes('tier-far'),true,
  'An isochrone outside normal walking radius remains only a far-zone polygon');
console.log('Panel I: normal strong inner coverage, lighter far outer spread, extensible tier model passed');


const a=station(1,'Athens',38,23.7);
const b=station(2,'Piraeus',38.05,23.75);
const busLeg={id_leg:1,from_station_id:1,to_station_id:2,mode:'bus',
  operator_label:'Athens-Piraeus bus',price:5};
const noLink=fixture({stations:[a,b]});
assert.equal(noLink.calls.polygons.length,4);
assert.equal(noLink.calls.lines.length,0,
  'Panel B: overlapping but unlinked patches blend with NO seam or line');
const withPrices=fixture({
  stations:[a,b],
  cost:{1:5,2:9},
  legs:[busLeg]
});
assert.equal(withPrices.calls.polygons.length,4,
  'Panel G: DIFFERENT station prices keep the two patch identities (no union)');
const firstBlob=withPrices.geometry.patchContour({
  lat:a.lat,lng:a.lng,radiusKm:10,key:'station:1'});
const secondBlob=withPrices.geometry.patchContour({
  lat:b.lat,lng:b.lng,radiusKm:10,key:'station:2'});
const stitch=withPrices.calls.lines.find(l=>l.options.className==='grtrans-zigzag-stitch');
assert.ok(stitch && stitch.points.length===9,'Panel C: priced overlap gets nine-point zigzag');
assert.ok(stitch.points.every(p=>polygonContains(p,firstBlob)&&polygonContains(p,secondBlob)),
  'The transfer seam stays INSIDE the overlap lens');
assert.ok(lengthKm(stitch.points)<=1.8,'#102 length limit applies to WHOLE zigzag');
assert.ok(stitch.points.every(p=>Math.abs(kmDistance(p,[38,23.7])-10)<0.3),
  'Seam is on the €5 patch OUTER cheap-reach boundary, not centred in overlap');
assert.equal(stitch.options.weight,3,
  'Panel C: short winning-boundary stitch must be thick enough for z11–z12');
assert.equal(stitch.options.opacity,.9);
assert.equal(stitch.options.dashArray,'3 2',
  'Panel C: a dense dash shows multiple marks even on a short stitch');
assert.ok(!withPrices.calls.polygons.some(p=>p.options.fillOpacity>0.6));
await new Promise(resolve=>setImmediate(resolve));
const gradient=withPrices.calls.lines.filter(l=>l.options.className==='grtrans-land-gradient');
assert.ok(gradient.length>=20,'Panel G: a continuous multi-segment price gradient follows road geometry');
assert.ok(new Set(gradient.map(l=>l.options.color)).size>12,
  'The gradient changes lightness along the connection');
assert.ok(gradient.every(l=>l.options.opacity>=.9));
assert.ok(gradient.some(l=>l.points.length===2));
const samePrice=fixture({stations:[a,b],cost:{1:5,2:5},legs:[{
  ...busLeg,price:0,
  route_geojson:JSON.stringify({type:'LineString',coordinates:[
    [23.7,38],[23.72,38.02],[23.75,38.05]]})
}]});
assert.equal(samePrice.calls.polygons.length,2,
  'Panel F/I: four legacy paths become ONE outer union and ONE inner-tier layer');
assert.equal(samePrice.calls.circles.length,0,
  'Panel F: zero internal arcs from former overlapping stamped circles');
const union=samePrice.calls.polygons[0];
assert.equal(union.options.pane,'grtrans-coverage-patches');
assert.ok(union.points.length>150);
assert.ok(union.points.every((p,i)=>{
  const q=union.points[(i+1)%union.points.length];
  const centre=[(p[0]+q[0])/2,(p[1]+q[1])/2];
  return !(polygonContains(centre,firstBlob)&&polygonContains(centre,secondBlob));
}), 'Union has zero boundary segments buried inside both original reaches');
await new Promise(resolve=>setImmediate(resolve));
const solid=samePrice.calls.lines.filter(l=>l.options.className==='grtrans-land-road');
assert.equal(solid.length,1,'Panel F: same-price land is one solid road line');
assert.equal(solid[0].points.length,3,'Persisted actual road shape overrides OSRM');
assert.equal(solid[0].options.weight,3.5);
assert.ok(solid[0].options.opacity>.9,'Land line is more opaque than its patches');
samePrice.elements['grtrans-map-preview'].events.click();
assert.ok(!union.layer.removed,'Route preview leaves union patch visible');
samePrice.elements['grtrans-map-preview-clear'].events.click();
assert.ok(solid.every(l=>!l.layer.removed));

/* Panel F regression: a ferry-type port can be a stop on a BUS leg.
 * Link transport kind and equal route cost, not station type, own the union. */
const mixedStops = [a, {...b,type:'ferry'}];
const mixedNoLink = fixture({stations:mixedStops,cost:{1:0,2:0}});
assert.equal(mixedNoLink.calls.polygons.length,4,
  'A bus hub and ferry port do not merge without a priced fixed-route link');
const mixedBus=fixture({stations:mixedStops,cost:{1:0,2:0},legs:[{
  ...busLeg,price:5,route_geojson:JSON.stringify({type:'LineString',coordinates:[
    [23.7,38],[23.72,38.02],[23.75,38.05]]})
}]});
assert.equal(mixedBus.geometry.stationGroups(mixedStops,[{...busLeg,price:5}],{1:0,2:0}).length,1,
  'Bus leg combines mixed station labels at equal route cost (0 = 0)');
assert.equal(mixedBus.calls.polygons.length,2,
  'Panel F: bus hub ↔ ferry port makes ONE outer union and ONE normal tier');
assert.ok(mixedBus.calls.polygons[0].points.every((p,i,ring=mixedBus.calls.polygons[0].points)=>{
  const q=ring[(i+1)%ring.length];
  const midpoint=[(p[0]+q[0])/2,(p[1]+q[1])/2];
  return !(polygonContains(midpoint,firstBlob)&&polygonContains(midpoint,secondBlob));
}), 'Mixed-label station union has NO buried boundary arcs');
await new Promise(resolve=>setImmediate(resolve));
const mixedRoad=mixedBus.calls.lines.filter(l=>l.options.className==='grtrans-land-road');
assert.equal(mixedRoad.length,1,'Mixed-label linked stations keep ONE continuous road');
assert.equal(mixedRoad[0].points.length,3,'Recorded road geometry remains authoritative');
assert.equal(mixedRoad[0].options.weight,3.5);
assert.ok(mixedRoad[0].options.opacity>.9,'Road stays stronger than area fill');
const zeroPrice=fixture({stations:[a,b],cost:{1:0,2:0},legs:[{
  ...busLeg,price:0}]});
assert.ok(!zeroPrice.calls.lines.some(l=>l.options.className==='grtrans-zigzag-stitch'),
  'Equal-cost linked land stations merge without a false cheap-to-expensive transfer seam');

/* #103/F regression: equal-price station-bound union must preserve the
 * real Polygon/MultiPolygon footprint, including an unfilled postal hole.
 * The original radius-only CI fixture missed this source-geometry case. */
const multipart=JSON.stringify({type:'MultiPolygon',coordinates:[
  [
    [[23.60,37.90],[23.80,37.90],[23.80,38.10],[23.60,38.10],[23.60,37.90]],
    [[23.68,37.98],[23.72,37.98],[23.72,38.02],[23.68,38.02],[23.68,37.98]]
  ],
  [[[24.00,38.00],[24.08,38.00],[24.08,38.08],[24.00,38.08],[24.00,38.00]]]
]});
const storedMulti={...station(1,'Postal islands',38,23.62),
  coverage_kind:'postal_polygon',coverage_geojson:multipart};
const adjoining={...station(2,'Bus boundary',38,23.79),
  outer_radius_km:3,inner_radius_km:1};
const multipartMerged=fixture({stations:[storedMulti,adjoining],cost:{1:0,2:0},
  legs:[{...busLeg,price:4}]});
assert.equal(multipartMerged.calls.polygons.length,2,
  'Equal-price linked polygon and circle have ONE far and ONE normal layer');
const mergedPaths=multipartMerged.calls.polygons[0].points;
assert.equal(mergedPaths.length,2,
  'Panel F retains the second supplied MultiPolygon island after union');
assert.ok(mergedPaths.some(poly=>polygonContains([38.04,24.04],poly[0])),
  'Distant postal polygon island is not silently discarded');
const polygonWithHole=mergedPaths.find(poly=>poly.length>1);
assert.ok(polygonWithHole&&polygonContains([38,23.70],polygonWithHole[1]),
  'Uncovered postal hole remains a hole when adjacent station coverage merges');
const postalRecord={lat:38,lng:23.62,radiusKm:10,
  coverage_geojson:multipart,key:'station:1'};
assert.equal(multipartMerged.geometry.overlaps(postalRecord,
  {lat:38,lng:23.70,radiusKm:0.2,key:'isolated-in-hole'}),false,
  'A station entirely inside a saved polygon hole cannot falsely merge');
console.log('Panel F: polygon/multipolygon union retains all sourced islands and holes');

/* Resolution is a data contract, not hard-coded price interpretation inside
 * the seam painter. An override-aware resolver can replace this map in #107. */
const explicitBoundary = fixture({
  stations:[a,b], cost:{1:5,2:9},
  resolutionByLink:{'leg:1':{boundaryOwner:'station:2',rule:'external-resolution'}},
  legs:[busLeg]
});
const resolvedSeam=explicitBoundary.calls.lines.find(l =>
  l.options.className==='grtrans-zigzag-stitch');
assert.ok(resolvedSeam,'An external resolution result still produces a linked seam');
assert.ok(resolvedSeam.points.every(p=>Math.abs(kmDistance(p,[38.05,23.75])-10)<0.3),
  'Injected winner-boundary result overrides cheapest-cost default WITHOUT geometry changes');
const explicitUnresolved=fixture({
  stations:[a,b],cost:{1:5,2:9},
  resolutionByLink:{'leg:1':null},legs:[busLeg]
});
assert.ok(!explicitUnresolved.calls.lines.some(l=>
  l.options.className==='grtrans-zigzag-stitch'),
  'An unresolved winner produces no fabricated handover seam');

/* #107 consumes #106's zigzag-stitch contract without redrawing seam geometry. */
const overrideBoundary = fixture({
  stations:[a,b],cost:{1:5,2:9},
  transfers:[{id_transfer:11,from_station_id:1,to_station_id:2,
    connection_type:'taxi',price:14,override_winner:'to'}],
  resolution:{links:{'transfer:11':{
    winner_key:'station:2',reach_boundary_km:10,reason:'link_override'
  }},paint_order:['station:1','station:2']}
});
const overrideSeam = overrideBoundary.calls.lines.find(l =>
  l.options.className === 'grtrans-zigzag-stitch');
assert.ok(overrideSeam,'Override-aware result retains the #106 zigzag stitch');
assert.ok(overrideSeam.points.every(p => Math.abs(kmDistance(p,[38.05,23.75])-10)<0.3),
  'Override seam follows resolved taxi reach boundary');
const advancedEditor=fixture({stations:[station(1,'From',38,23.7),
  station(2,'To',38.005,23.702)]});
advancedEditor.calls.markers[0].layer.events.click({});
advancedEditor.elements['grtrans-map-link'].events.click();
advancedEditor.calls.markers[1].layer.events.click({});
assert.equal(advancedEditor.elements['grtrans-map-link-form'].hidden,false,
  'Nearby links open the Advanced editor');
assert.notEqual(advancedEditor.elements['grtrans-map-link-form'].submitted,true,
  'Nearby links cannot auto-submit before choosing override');
assert.equal(advancedEditor.elements['grtrans-link-override'].value,'none',
  'New link resets to neutral precedence');
console.log('Panels B/C/F/G: no-link blend, cheaper boundary, same-price union and gradient passed');

const air=fixture({stations:[{...a,type:'airport'},
  {...station(2,'Island airport',37,25),type:'airport'}],
  legs:[{id_leg:20,from_station_id:1,to_station_id:2,mode:'flight',price:45}]});
const arc=air.calls.lines.find(l=>l.options.className==='grtrans-air-arc');
const arrow=air.calls.lines.find(l=>l.options.className==='grtrans-air-arrow');
assert.ok(arc && arrow,'Panel D: flight renders a directed dashed arc');
assert.equal(arc.points.length,41);
assert.equal(arc.options.dashArray,'7 7');
assert.ok(kmDistance(arrow.points[1],[37,25])<.001);
const airMiddle=[37.5,24.35];
const airRadii=arc.points.map(p=>Math.hypot((p[0]-airMiddle[0])*111.195,
  (p[1]-airMiddle[1])*111.195*Math.cos(airMiddle[0]*Math.PI/180)));
assert.ok(Math.max(...airRadii)-Math.min(...airRadii)<.05,
  'Distant flight is a true symbolic half-circle, not an arbitrary bend');
air.calls.polygons[0].layer.events.mouseover();
assert.ok(air.calls.polygons[2].layer.style.fillOpacity>.16,
  'Mental hover highlights both distant plane-island reaches');
air.calls.polygons[0].layer.events.mouseout();
assert.equal(air.calls.polygons[2].layer.style.fillOpacity,.16);
arc.layer.events.mouseover();
assert.ok(air.calls.polygons[0].layer.style.fillOpacity>.16);
assert.ok(air.calls.polygons[2].layer.style.fillOpacity>.16);
arc.layer.events.mouseout();
const sea=fixture({stations:[
  {...a,type:'ferry'},
  {...station(2,'Naxos',37.1,25.4),type:'ferry'},
  {...station(3,'Syros',37.4,24.9),type:'ferry'}],
  legs:[{id_leg:30,from_station_id:1,to_station_id:2,
      mode:'ferry',operator_label:'Vessel A',price:29},
    {id_leg:31,from_station_id:1,to_station_id:3,
      mode:'ferry',operator_label:'Vessel B',price:24}]
});
const vessels=sea.calls.lines.filter(l=>l.options.className==='grtrans-sea-line');
assert.equal(vessels.length,2,
  'Panel E: each priced sea origin-destination vessel gets a separate straight dashed line');
assert.ok(vessels.every(l=>l.points.length===2&&l.options.dashArray==='8 7'));
assert.equal(sea.calls.lines.filter(l=>l.options.className==='grtrans-sea-arrow').length,2);
vessels[0].layer.events.mouseover();
assert.ok(sea.calls.polygons[0].layer.style.fillOpacity>.16);
assert.ok(sea.calls.polygons[2].layer.style.fillOpacity>.16);
assert.equal(sea.calls.polygons[4].layer.style.fillOpacity,.16,
  'Sea mental link is priced-pair-scoped, NOT one fan-out');
vessels[0].layer.events.mouseout();
const dynamic=fixture({stations:[a,station(2,'Island',38.04,23.76)],
  legs:[{id_leg:12,from_station_id:1,to_station_id:null,mode:'other',price:8,
    area_lat:38.02,area_lng:23.73,area_radius_km:25,area_name:'Courier sea'}]});
assert.equal(dynamic.calls.polygons.length,5,
  'Panel H: one continuous dynamic sea, then two island outer/inner washes');
const seaPatch=dynamic.calls.polygons[0];
assert.equal(seaPatch.options.fillOpacity,.24);
assert.equal(seaPatch.options.fill,true);
assert.equal(seaPatch.options.pane,'grtrans-coverage-patches');
assert.equal(seaPatch.options.stroke,false,
  'Panel H: dynamic sea must have NO general outline/frame');
assert.equal(seaPatch.options.weight,0);
assert.equal(seaPatch.options.opacity,0);
assert.ok(seaPatch.points.length>100 && !Array.isArray(seaPatch.points[0][0]),
  'Sea is ONE uncut outer polygon, not a punched-out ring or polygon with holes');
assert.ok(dynamic.calls.polygons.slice(1).every(p=>p.options.fillOpacity<1),
  'Island fills are translucent, so the still-present sea colour shows within them');
assert.ok(polygonContains([38,23.7],seaPatch.points) &&
  polygonContains([38.04,23.76],seaPatch.points),
  'Sea covers BOTH the linked and unlinked islands — no subtractive clipping');
const fullRing=dynamic.calls.lines.filter(l=>
  l.options.className==='grtrans-linked-island-perimeter');
const ringTarget=dynamic.calls.lines.filter(l=>
  l.options.className==='grtrans-linked-island-perimeter-hover');
assert.equal(fullRing.length,1,
  'Exactly ONE linked station island gets a full-circumference stitch');
assert.equal(ringTarget.length,1,'Ring retains one separate hover/tooltip target');
assert.ok(fullRing[0].points.length>200,
  'Linked stitch traces the WHOLE island perimeter, not one small lens seam');
assert.deepEqual(Array.from(fullRing[0].points[0]),
  Array.from(fullRing[0].points.at(-1)),
  'Zigzag seam is a closed full-circumference ring');
assert.ok(fullRing[0].points.slice(0,-1).every(p=>
  Math.abs(kmDistance(p,[38,23.7])-10)<.19),
  'Entire ring hugs the linked island boundary');
assert.ok(fullRing[0].points.some(p=>p[0]<37.92) &&
  fullRing[0].points.some(p=>p[0]>38.08),
  'The ring wraps north and south of the island, not one touch point');
assert.equal(fullRing[0].options.weight,1.25);
assert.equal(fullRing[0].options.opacity,.55);
assert.equal(fullRing[0].options.dashArray,'2 5');
assert.ok(!dynamic.calls.lines.some(l=>l.options.className==='grtrans-zigzag-stitch'),
  'Contained linked island is NOT also marked by the old short point stitch');
assert.ok(!dynamic.calls.lines.some(l=>l.options.className==='grtrans-land-road' ||
  l.options.className==='grtrans-land-gradient'),
  'No road/ribbon crosses over the dynamic sea');
assert.ok(ringTarget[0].layer.tooltip.textContent.includes('entire linked island boundary'));
ringTarget[0].layer.events.mouseover();
assert.ok(dynamic.calls.polygons[0].layer.style.fillOpacity>.24 &&
  dynamic.calls.polygons[1].layer.style.fillOpacity>.16,
  'Ring hover highlights the dynamic sea and linked island');
assert.equal(dynamic.calls.polygons[3].layer.style.fillOpacity,.16,
  'Unlinked island is not accidentally linked or highlighted');
ringTarget[0].layer.events.mouseout();
assert.equal(dynamic.calls.polygons[3].layer.style.fillOpacity,.16);
dynamic.elements['grtrans-map-preview'].events.click();
assert.ok(dynamic.calls.lines.every(l=>l.layer.removed),
  'Explicit route preview hides full-perimeter stitch without hiding the sea');
assert.ok(dynamic.calls.polygons.every(p=>!p.layer.removed),
  'Sea and both islands remain filled while the preview operates');
dynamic.elements['grtrans-map-preview-clear'].events.click();
assert.ok(dynamic.calls.lines.every(l=>!l.layer.removed),
  'Clearing preview restores the complete zigzag ring');
console.log('Panels D/E/H: air, distinct ferry vessels, full linked-island seam and uncut sea passed');

const routeA = station(1,'Road start',38,23.7);
const routeB = station(2,'Ferry port',38.05,23.75);
const routeC = station(3,'Island port',37.1,25.37);
let roadRequests = 0;
const routing = fixture({
  stations:[routeA,routeB,routeC],
  cost:{1:3,2:3,3:12},
  legs:[
    {id_leg:1,from_station_id:1,to_station_id:2,mode:'bus',
      operator_label:'Road bus',price:3,bidirectional:0},
    {id_leg:2,from_station_id:2,to_station_id:3,mode:'ferry',
      operator_label:'Ferry',price:12,bidirectional:0}
  ]
}, {fetch:async url => {
  roadRequests++;
  assert.match(url,/router.project-osrm.org\/route\/v1\/driving/);
  return {ok:true,json:async () => ({
    code:'Ok',routes:[{geometry:{coordinates:[[23.7,38],[23.73,38.03],[23.75,38.05]]}}]
  })};
}});
assert.equal(routing.calls.markers[0].options.icon.options.className,'grtrans-origin-marker');
routing.elements['grtrans-map-preview'].events.click();
assert.equal(routing.calls.polygons.length,4,
  'One outer union and one inner tier plus the ferry station outer and inner tier');
assert.ok(routing.calls.polygons.every(p => !p.layer.removed));
routing.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
routing.calls.mapEvents.click({latlng:{lat:37.1,lng:25.37}});
await new Promise(resolve => setImmediate(resolve));
assert.equal(roadRequests,2,
  'One OSRM request for base road line and one for explicit preview; no ferry routing');
assert.match(routing.elements['grtrans-preview-status'].textContent,/Route preview ready/);
assert.ok(routing.calls.lines.some(line => line.points.length===3 &&
  line.options.weight===5),'Road preview contains OSRM bend');
assert.ok(routing.calls.lines.some(line => line.options.weight===5 &&
  line.options.dashArray==='10 5'),'Preview ferry uses direct port-to-port line');
routing.elements['grtrans-map-preview-clear'].events.click();
assert.ok(routing.calls.polygons.every(p => !p.layer.removed));
assert.ok(routing.calls.lines.some(l => l.options.className==='grtrans-sea-line' && !l.layer.removed),
  'Clearing preview restores the actual ferry transport line');
assert.match(routing.elements['grtrans-preview-status'].textContent,/cleared/);
const noRoute = fixture({stations:[routeA,routeC]});
const previous = noRoute.calls.lines.length;
noRoute.elements['grtrans-map-preview'].events.click();
noRoute.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
noRoute.calls.mapEvents.click({latlng:{lat:37.1,lng:25.37}});
await new Promise(resolve => setImmediate(resolve));
assert.match(noRoute.elements['grtrans-preview-status'].textContent,/No directed network connection/);
assert.equal(noRoute.calls.lines.length,previous,'No straight fallback for missing path');
const osrmDown = fixture({
  stations:[routeA,routeB],
  legs:[{id_leg:8,from_station_id:1,to_station_id:2,mode:'bus',
    operator_label:'Broken road',price:1,bidirectional:0}]
}, {fetch:async () => {throw Error('OSRM offline');}});
osrmDown.elements['grtrans-map-preview'].events.click();
osrmDown.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
osrmDown.calls.mapEvents.click({latlng:{lat:38.05,lng:23.75}});
await new Promise(resolve => setImmediate(resolve));
assert.match(osrmDown.elements['grtrans-preview-status'].textContent,
  /OSRM offline.*No straight-line fallback/);
assert.equal(osrmDown.calls.lines.filter(line => line.options.weight===5).length,0);
console.log('Admin routed-preview geometry and failures passed');
