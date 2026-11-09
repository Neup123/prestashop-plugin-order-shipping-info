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
    focus() {}, requestSubmit() {}
  });
  const ids = ['grtrans-network-map','grtrans-map-status','grtrans-zone-legend',
    'grtrans-map-link','grtrans-map-link-form','grtrans-link-cancel',
    'grtrans-link-from','grtrans-link-to','grtrans-link-type','grtrans-link-price',
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
const lengthKm = vertices => vertices.slice(1).reduce((total, p, i) =>
  total + kmDistance(p, vertices[i]), 0);
const withinPolygon = (p, poly) => {
  let found = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
    const a = poly[i], b = poly[j];
    if ((a[0] > p[0]) !== (b[0] > p[0]) &&
        p[1] < (b[1] - a[1]) * (p[0] - a[0]) / (b[0] - a[0]) + a[1]) found = !found;
  }
  return found;
};
const {calls, elements, panes, geometry} = fixture({
  stations: [station(1, '<first>', 37.9, 23.6), station(2, 'Second', 38.2, 23.9)],
  legs: [{id_leg:9, from_station_id:1, to_station_id:null, mode:'other',
    area_lat:37.8, area_lng:23.5, area_radius_km:4, area_name:'Third area', price:5}]
});
assert.equal(panes['grtrans-coverage-patches'].style.zIndex, '390',
  'The existing #102 dedicated vector pane is retained');
assert.equal(calls.circles.length, 0, 'No stamped Leaflet circles remain');
assert.equal(calls.polygons.length, 5,
  'One organic direct-area sea plus two independent outer/inner station blobs');
assert.equal(calls.polygons[0].options.fillOpacity, 0.24,
  'Full dynamic coverage is painted first, beneath its islands');
assert.equal(calls.polygons[1].options.fillOpacity, 0.20);
assert.equal(calls.polygons[2].options.fillOpacity, 0.10);
assert.equal(calls.polygons[2].options.stroke, false,
  'Inner soft wash has no extra stamped-circle outline');
assert.ok(calls.polygons.every(p => p.options.fill &&
  p.options.pane === 'grtrans-coverage-patches' && p.options.className === 'grtrans-organic-patch'));
assert.equal(new Set([calls.polygons[0].options.fillColor,
  calls.polygons[1].options.fillColor, calls.polygons[3].options.fillColor]).size, 3);
const outerContour = calls.polygons[1].points;
const outerRadii = outerContour.map(p => kmDistance(p, [37.9, 23.6]));
assert.ok(Math.max(...outerRadii) - Math.min(...outerRadii) > 0.3,
  'Organic patch is visibly non-circular rather than a stamped disc');
assert.ok(Math.max(...outerRadii) <= 10.03,
  'The organic visual never grows beyond the stored coverage radius');
assert.ok(outerContour.length >= 120, 'Organic edge is smoothly sampled');
assert.equal(elements['grtrans-zone-legend'].children.length, 3);
assert.ok(elements['grtrans-zone-legend'].children.map(item =>
  item.children[1].textContent).includes('<first>'),
  'Merchant-supplied labels remain safe text');
assert.equal(calls.markers.length, 2, 'Individual station pins remain clickable');
const single = fixture({stations:[{...station(1,'Small patch',38,24),outer_radius_km:1}]});
assert.equal(single.calls.fits.length, 1);
const bounds = single.calls.fits[0][0];
assert.ok(bounds[0][0] < 38 && bounds[1][0] > 38);
assert.ok(bounds[0][1] < 24 && bounds[1][1] > 24);
assert.ok(single.calls.fits[0][1].maxZoom >= 12);
const empty = fixture({});
assert.equal(empty.calls.fits.length, 0);
assert.equal(empty.calls.views[0][1], 6);
const orphan = fixture({legs:[{id_leg:4,from_station_id:5,to_station_id:null,
  area_lat:37.9,area_lng:23.7,area_radius_km:2,price:3}]});
assert.equal(orphan.calls.polygons.length, 0);
assert.equal(orphan.calls.fits.length, 0);
const withArea = fixture({stations:[station(1,'Hub',38,24)],legs:[{
  id_leg:7,from_station_id:1,to_station_id:null,area_lat:36,area_lng:25,
  area_radius_km:3,area_name:'Area',price:3
}]});
assert.ok(withArea.calls.fits[0][0].some(p => p[0] < 36),
  'Dynamic coverage radius participates in map fit');
console.log('Organic radius-safe patches, separate islands, coverage pane and legend passed');

const a = station(1, 'Athens', 38, 23.7);
const b = station(2, 'Piraeus', 38.05, 23.75);
const unrelated = station(3, 'Unrelated', 39, 25);
const unlinked = fixture({stations:[a,b]});
assert.equal(unlinked.calls.polygons.length, 4,
  'Two touching services without a priced link retain four translucent patch washes');
assert.equal(unlinked.calls.lines.length, 0,
  'Overlapping with NO priced link has no seam, marker or line');

const linked = fixture({
  stations:[a,b,unrelated],
  legs:[{id_leg:1,from_station_id:1,to_station_id:2,mode:'bus',
    operator_label:'Athens-Piraeus',price:10}]
});
assert.equal(linked.calls.polygons.length, 3,
  'Correction #1+#3: Athens/Piraeus 4 ring paths BEFORE -> 1 union path AFTER, plus 2 unrelated washes');
assert.equal(linked.calls.circles.length, 0,
  'Union has ZERO buried internal arcs/circle paths');
const union = linked.calls.polygons[0];
assert.equal(union.options.pane,'grtrans-coverage-patches');
assert.ok(union.points.length >= 150, 'Union is a complete exposed organic perimeter');
const firstBlob = geometry.patchContour({
  lat:a.lat,lng:a.lng,radiusKm:a.outer_radius_km,key:'station:1'
});
const secondBlob = geometry.patchContour({
  lat:b.lat,lng:b.lng,radiusKm:b.outer_radius_km,key:'station:2'
});
const midpointSegments = union.points.map((p,i) => {
  const q = union.points[(i+1)%union.points.length];
  return [(p[0]+q[0])/2, (p[1]+q[1])/2];
});
assert.equal(midpointSegments.filter(p => withinPolygon(p,firstBlob) &&
  withinPolygon(p,secondBlob)).length,0,
  'Union draws ZERO boundary segments buried inside BOTH linked patches');
assert.equal(linked.calls.lines.length,2,
  'One local zigzag seam and one transparent hover target, no ribbon');
const seam = linked.calls.lines[0];
assert.equal(seam.options.weight,1.25);
assert.equal(seam.options.opacity,0.55);
assert.equal(seam.options.dashArray,'2 5');
assert.equal(seam.points.length,9,
  'Overlap seam is a zigzag, not a straight chord or cross-ticks');
assert.ok(lengthKm(seam.points) <= 1.8,
  'Zigzag total length, not just its endpoint span, stays under 1.8 km');
assert.ok(seam.points.every(p => withinPolygon(p,firstBlob) &&
  withinPolygon(p,secondBlob)),
  'ALL zigzag vertices lie inside the two-patch overlap lens');
const stitchCurvatures = seam.points.slice(1,-1).map((p,i) => {
  const prev = seam.points[i], next = seam.points[i+2];
  return Math.abs((p[0]-prev[0])*(next[1]-p[1])-
    (p[1]-prev[1])*(next[0]-p[0]));
});
assert.ok(stitchCurvatures.some(value => value > 1e-9),
  'Zigzag alternates direction along the shared interior boundary');
assert.equal(linked.calls.lines[1].options.opacity,0.001);
assert.equal(linked.calls.lines[1].layer.tooltip.textContent,
  'Athens-Piraeus · 10.00 EUR',
  'Hover target retains exact priced route tooltip');
linked.elements['grtrans-map-preview'].events.click();
assert.ok(linked.calls.lines.every(line => line.layer.removed),
  'Route preview hides seam marks');
assert.ok(linked.calls.polygons.every(p => !p.layer.removed),
  'Route preview never removes the organic patches or union');
linked.elements['grtrans-map-preview-clear'].events.click();
assert.ok(linked.calls.lines.every(line => !line.layer.removed),
  'Clearing preview restores the zigzag seam');

const unpriced = fixture({stations:[a,b],legs:[{
  id_leg:3,from_station_id:1,to_station_id:2,mode:'bus',price:-2
}]});
assert.equal(unpriced.calls.lines.length,0,'Invalid/negative prices produce no stitch');
const free = fixture({stations:[a,b],legs:[{
  id_leg:3,from_station_id:1,to_station_id:2,mode:'bus',price:0
}]});
assert.equal(free.calls.lines.length,2,'Zero-priced valid links still have a seam');
const disjointLand = fixture({
  stations:[a,station(2,'Distant',40,25)],
  legs:[{id_leg:4,from_station_id:1,to_station_id:2,mode:'bus',price:5}]
});
assert.equal(disjointLand.calls.polygons.length,4,
  'Distant station blobs remain separate');
assert.equal(disjointLand.calls.lines.length,4,
  'Distant land has only two local anchor marks and hit targets, no route line');
assert.ok(disjointLand.calls.lines.every(line => lengthKm(line.points) < 1.8));
assert.ok(disjointLand.calls.lines.every(line => line.points.length === 2));
assert.ok(disjointLand.calls.lines.every(line => line.options.weight === 1.25 ||
  line.options.opacity === 0.001), 'No #99 broad fabric, parallel rims or cross-ticks');

const dynamicIsland = fixture({
  stations:[a,station(2,'Island',38.04,23.76)],
  legs:[{id_leg:12,from_station_id:1,to_station_id:null,mode:'other',price:8,
    area_lat:38.02,area_lng:23.73,area_radius_km:25,area_name:'Taxi coverage sea'}]
});
assert.equal(dynamicIsland.calls.polygons.length,5);
assert.equal(dynamicIsland.calls.polygons[0].options.fillOpacity,0.24,
  'Dynamic area sea is one organic patch underneath separate station islands');
assert.ok(dynamicIsland.calls.polygons[0].points.length >= 120);
assert.equal(dynamicIsland.calls.circles.length,0);
assert.ok(dynamicIsland.calls.polygons.every(p => p.points.length > 100),
  'No cutouts, corridors, fabric strips, or hard disc shapes for island model');

const air = fixture({
  stations:[{...a,type:'airport'}, {...station(2,'Island airport',37,25),type:'airport'}],
  legs:[{id_leg:20,from_station_id:1,to_station_id:2,mode:'flight',
    operator_label:'Flight A',price:45}]
});
assert.equal(air.calls.lines.length,2,
  'Distant AIR EXCEPTION is a dashed half-circle arc plus destination arrowhead');
const arc = air.calls.lines[0], arrow = air.calls.lines[1];
assert.equal(arc.points.length,41);
assert.equal(arc.options.dashArray,'7 7');
assert.equal(arc.options.className,'grtrans-air-arc');
assert.equal(arrow.options.className,'grtrans-air-arrow');
assert.equal(arrow.points.length,3);
assert.ok(kmDistance(arrow.points[1],[37,25]) < .001,
  'Arrowhead points at the actual destination');
const midpoint = [(38+37)/2,(23.7+25)/2];
assert.ok(kmDistance(arc.points[20],midpoint) > 50,
  'Air connection is a high, symbolic curved arc, never straight across the route');
const fixedKm = p => Math.hypot((p[0] - midpoint[0]) * 111.195,
  (p[1] - midpoint[1]) * 111.195 * Math.cos(midpoint[0] * Math.PI / 180));
const arcRadii = arc.points.map(fixedKm);
assert.ok(Math.max(...arcRadii) - Math.min(...arcRadii) < 0.05,
  'Every air-arc sample lies on one true radius: a geometric half circle');
assert.match(arc.layer.tooltip.textContent,/symbolic flight arc; not area coverage/);
air.calls.polygons[0].layer.events.mouseover();
assert.ok(air.calls.polygons[0].layer.style.fillOpacity > .2);
assert.ok(air.calls.polygons[2].layer.style.fillOpacity > .2,
  'Air endpoint hover highlights both plane-islands as one mental connection');
air.calls.polygons[0].layer.events.mouseout();
assert.equal(air.calls.polygons[2].layer.style.fillOpacity,.2);
arc.layer.events.mouseover();
assert.ok(air.calls.polygons[0].layer.style.fillOpacity > .2);
assert.ok(air.calls.polygons[2].layer.style.fillOpacity > .2,
  'Hovering the arc also highlights both plane-islands');
arc.layer.events.mouseout();
assert.equal(air.calls.polygons[2].layer.style.fillOpacity,.2);
air.elements['grtrans-map-preview'].events.click();
assert.ok(air.calls.lines.every(line => line.layer.removed),
  'Explicit route preview hides symbolic arc and arrow');
air.elements['grtrans-map-preview-clear'].events.click();
assert.ok(air.calls.lines.every(line => !line.layer.removed),
  'Clearing preview restores air arc and arrow');
const bidirectionalAir = fixture({
  stations:[{...a,type:'airport'}, {...station(2,'B',37,25),type:'airport'}],
  legs:[{id_leg:22,from_station_id:1,to_station_id:2,mode:'flight',
    price:48,bidirectional:1}]
});
assert.equal(bidirectionalAir.calls.lines.length,3,
  'A bidirectional priced flight shows arrowheads at BOTH ends');
const touchingAir = fixture({
  stations:[{...a,type:'airport'}, {...b,type:'airport'}],
  legs:[{id_leg:21,from_station_id:1,to_station_id:2,mode:'flight',price:5}]
});
assert.equal(touchingAir.calls.lines.length,2,
  'Near/touching AIR uses a zigzag seam, not a flight arc');
assert.equal(touchingAir.calls.lines[0].points.length,9);
assert.equal(touchingAir.calls.lines[0].options.weight,1.25);

const sea = fixture({
  stations:[{...a,type:'ferry'}, {...station(2,'Naxos',37.1,25.4),type:'ferry'},
    {...station(3,'Syros',37.4,24.9),type:'ferry'}],
  legs:[{id_leg:30,from_station_id:1,to_station_id:2,mode:'ferry',
      operator_label:'Vessel A',price:29},
    {id_leg:31,from_station_id:1,to_station_id:3,mode:'ferry',
      operator_label:'Vessel B',price:24}]
});
assert.equal(sea.calls.lines.length,8,
  'Two differently priced sea legs remain two separate local anchor pairs');
assert.equal(new Set(sea.calls.lines.filter(line => line.options.opacity === 0.001)
  .map(line => line.layer.tooltip.textContent)).size,2,
  'Every individual priced sea pair has its own tooltip and hover target');
assert.ok(sea.calls.lines.every(line => line.points.length === 2),
  'No full-route SEA line until second owner drawing');
sea.calls.lines[1].layer.events.mouseover();
assert.ok(sea.calls.polygons[0].layer.style.fillOpacity > .2);
assert.ok(sea.calls.polygons[2].layer.style.fillOpacity > .2);
assert.equal(sea.calls.polygons[4].layer.style.fillOpacity,.2,
  'Hovering vessel A does not treat differently priced vessel B as the same fan-out');
sea.calls.lines[1].layer.events.mouseout();
assert.equal(sea.calls.polygons[0].layer.style.fillOpacity,.2);
assert.equal(sea.calls.polygons[2].layer.style.fillOpacity,.2);
console.log('Union: 4 old circles -> 1 organic path, 0 internal arcs; zigzag, air and vessels passed');

const routeA = station(1,'Road start',38,23.7);
const routeB = station(2,'Ferry port',38.05,23.75);
const routeC = station(3,'Island port',37.1,25.37);
let roadRequests = 0;
const routing = fixture({
  stations:[routeA,routeB,routeC],
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
assert.equal(routing.calls.polygons.length,3,
  'Overlapping bus union plus the far ferry station outer/inner organic washes');
assert.ok(routing.calls.polygons.every(p => !p.layer.removed));
routing.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
routing.calls.mapEvents.click({latlng:{lat:37.1,lng:25.37}});
await new Promise(resolve => setImmediate(resolve));
assert.equal(roadRequests,1,'Road preview uses OSRM; ferry preview does not');
assert.match(routing.elements['grtrans-preview-status'].textContent,/Route preview ready/);
assert.ok(routing.calls.lines.some(line => line.points.length===3 &&
  line.options.weight===5),'Road preview contains OSRM bend');
assert.ok(routing.calls.lines.some(line => line.options.weight===5 &&
  line.options.dashArray==='10 5'),'Preview ferry uses direct port-to-port line');
routing.elements['grtrans-map-preview-clear'].events.click();
assert.ok(routing.calls.polygons.every(p => !p.layer.removed));
assert.ok(routing.calls.lines.some(l => l.options.weight===1.25 && !l.layer.removed));
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
