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
      on(event, fn) { this.events[event] = fn; }, setLatLng(p) { this.point = p; },
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
const {calls, elements, panes} = fixture({
  stations: [station(1, '<first>', 37.9, 23.6), station(2, 'Second', 38.2, 23.9)],
  legs: [{id_leg: 9, from_station_id:1, to_station_id:null, mode:'other',
    area_lat:37.8, area_lng:23.5, area_radius_km:4, area_name:'Third area', price:5}],
});
const circles = calls.circles;
assert.equal(panes['grtrans-coverage-patches'].style.zIndex, '390',
  'Patches retain the #102 dedicated vector pane');
assert.equal(circles.length, 5, 'Direct area plus two separately rendered station ring pairs');
assert.equal(circles[0].options.fillOpacity, 0.24,
  'Dynamic service area is painted first, underneath the station islands');
assert.equal(circles[1].options.fillOpacity, 0.2, 'Unmerged station outer coverage stays translucent');
assert.equal(circles[2].options.fillOpacity, 0.1, 'Unmerged station inner coverage stays translucent');
assert.ok(circles.every(c => c.options.fill === true &&
  c.options.pane === 'grtrans-coverage-patches'),
  'Every direct-area and station circle uses the dedicated patch pane');
assert.equal(new Set([circles[0].options.fillColor, circles[1].options.fillColor,
  circles[3].options.fillColor]).size, 3, 'Independent zones retain distinct colours');
assert.equal(elements['grtrans-zone-legend'].children.length, 3);
assert.ok(elements['grtrans-zone-legend'].children.map(item =>
  item.children[1].textContent).includes('<first>'),
  'Merchant-supplied names use text, never HTML');
assert.equal(calls.markers.length, 2, 'Pin-based station selection remains available');
const single = fixture({stations:[{...station(1,'One small patch',38,24),outer_radius_km:1}]});
assert.equal(single.calls.fits.length, 1, 'One radius-aware patch is framed on load');
const fitted = single.calls.fits[0][0];
assert.equal(fitted.length, 2);
assert.ok(fitted[0][0] < 38 && fitted[1][0] > 38);
assert.ok(fitted[0][1] < 24 && fitted[1][1] > 24);
assert.ok(single.calls.fits[0][1].maxZoom >= 12);
const empty = fixture({});
assert.equal(empty.calls.fits.length, 0);
assert.equal(empty.calls.views[0][1], 6);
const directOnly = fixture({legs:[{id_leg:7,from_station_id:1,to_station_id:null,
  area_lat:37.9,area_lng:23.7,area_radius_km:2,area_name:'Area',price:2}]});
assert.equal(directOnly.calls.fits.length, 0, 'Orphan area has no invented coverage');
const withArea = fixture({stations:[station(1,'Hub',38,24)],legs:[{
  id_leg:7,from_station_id:1,to_station_id:null,area_lat:36,area_lng:25,
  area_radius_km:3,area_name:'Area',price:3
}]});
assert.ok(withArea.calls.fits[0][0].some(p => p[0] < 36),
  'Direct area radius contributes to map framing');
assert.equal(withArea.calls.polygons.length, 0,
  'Dynamic service is its own patch, not a sewn fabric band');
console.log('Admin coverage pane, area sea, islands, fitting and legend passed');

const connected = fixture({
  stations: [station(1, 'Athens', 38.0, 23.7),
    station(2, 'Piraeus', 38.05, 23.75),
    station(3, 'Unrelated', 39, 25)],
  legs: [{id_leg:1, from_station_id:1, to_station_id:2, mode:'bus',
    operator_label:'Athens-Piraeus bus', price:10, bidirectional:0}]
});
// Geometry evidence for correction #1: previously Athens/Piraeus drew
// FOUR separate overlapping circle paths (two rings each). Now it draws
// ONE union polygon path and ZERO internal circle paths/arcs.
assert.equal(connected.calls.polygons.length, 1, 'Two overlapping bus stations form ONE union path');
assert.equal(connected.calls.circles.length, 2,
  'Only the unrelated station keeps its two circles; zero Athens/Piraeus internal arcs');
const union = connected.calls.polygons[0];
assert.equal(union.options.pane, 'grtrans-coverage-patches');
assert.equal(union.options.fill, true);
assert.ok(union.points.length > 180, 'Union path samples continuous exposed circle arcs');
const kmDistance = (a,b) => Math.hypot((a[0]-b[0])*111.195,
  (a[1]-b[1])*111.195*Math.cos((a[0]+b[0])*Math.PI/360));
assert.ok(union.points.every(p => kmDistance(p, [38,23.7]) > 9.94 &&
  kmDistance(p, [38.05,23.75]) > 9.94),
  'Every union vertex is on an OUTER perimeter, not a buried internal circle arc');
assert.equal(connected.calls.lines.length, 2,
  'Only one short priced stitch and its invisible hover target remain');
assert.equal(connected.calls.lines[0].options.weight, 1.25);
assert.equal(connected.calls.lines[0].options.opacity, 0.55);
assert.equal(connected.calls.lines[0].options.dashArray, '2 5');
assert.equal(connected.calls.lines[1].options.opacity, 0.001);
assert.equal(connected.calls.lines[0].layer.tooltip, undefined);
assert.equal(connected.calls.lines[1].layer.tooltip.textContent,
  'Athens-Piraeus bus · 10.00 EUR');
const [p,q] = connected.calls.lines[0].points;
assert.ok(kmDistance(p,q) <= 1.801, 'Stitch length never exceeds 1.8 km');
assert.notEqual(p[0], 38, 'A stitch is not a station-centre-to-centre line');
connected.elements['grtrans-map-preview'].events.click();
assert.ok(connected.calls.lines.every(line => line.layer.removed),
  'Preview hides schematic priced stitches');
assert.ok(!union.layer.removed, 'Preview leaves merged coverage fully visible');
connected.elements['grtrans-map-preview-clear'].events.click();
assert.ok(connected.calls.lines.every(line => !line.layer.removed),
  'Clear preview restores local stitches');
assert.ok(!union.layer.removed);

const disconnected = fixture({stations: [
  station(1, 'A', 38.0, 23.7), station(2, 'B', 38.05, 23.75)
]});
assert.equal(disconnected.calls.lines.length, 0, 'No priced link means NO stitch');
assert.equal(disconnected.calls.polygons.length, 0,
  'Coincident station types without a shared priced service are not inferred as one service');

const disjoint = fixture({
  stations: [station(1, 'A', 38.0, 23.7), station(2, 'B', 39, 25)],
  transfers: [{id_transfer:5, from_station_id:1,to_station_id:2,
    connection_type:'taxi',price:12}]
});
assert.equal(disjoint.calls.polygons.length, 0,
  'Disjoint priced patches contain NO broad fabric polygons');
assert.equal(disjoint.calls.lines.length, 4,
  'Two separate rim-local stitches with two invisible targets, nothing spanning gap');
assert.ok(disjoint.calls.lines.every(line => kmDistance(
  line.points[0], line.points[1]) <= 1.801));
assert.ok(disjoint.calls.lines.every(line => line.options.weight === 1.25 ||
  line.options.opacity === 0.001), 'No #99 rim bands, rails, ticks or ribbons');
assert.ok(disjoint.calls.lines.filter(line => line.options.weight === 1.25)
  .every(line => line.options.dashArray === '2 5' && line.options.opacity === 0.55));

const disjointBus = fixture({
  stations: [station(1,'A',38,23.7),station(2,'B',40,25)],
  legs: [{id_leg:6,from_station_id:1,to_station_id:2,mode:'bus',price:5}]
});
assert.equal(disjointBus.calls.polygons.length, 0,
  'A long land journey never manufactures false coverage between its patches');
assert.equal(disjointBus.calls.circles.length, 4,
  'Far-apart stations remain separate patches');

const dynamicIsland = fixture({
  stations: [station(1,'Hub',38,23.7),station(2,'Island',38.04,23.76)],
  legs: [{id_leg:12,from_station_id:1,to_station_id:null,mode:'other',
    price:8,area_lat:38.02,area_lng:23.73,area_radius_km:25,
    area_name:'Taxi coverage sea'}]
});
assert.equal(dynamicIsland.calls.circles.length, 5);
assert.equal(dynamicIsland.calls.circles[0].options.radius, 25000,
  'Dynamic transport fills one whole area patch underneath two station islands');
assert.equal(dynamicIsland.calls.polygons.length, 0,
  'Islands do not punch holes in the area sea or get fabric connectors');

const air = fixture({
  stations:[station(1,'Airport A',38,23.7),station(2,'Airport B',37,25)],
  legs:[{id_leg:20,from_station_id:1,to_station_id:2,mode:'flight',price:45}]
});
assert.equal(air.calls.lines.length, 4,
  'Air map currently has local endpoint stitches only, pending route-arc decision');
assert.ok(air.calls.lines.filter(l => l.options.weight === 1.25)
  .every(l => kmDistance(l.points[0], l.points[1]) < 1.81));
air.calls.circles[0].layer.events.mouseover();
assert.ok(air.calls.circles[0].layer.style.fillOpacity > 0.2,
  'Hovering one air endpoint darkens its own patch');
assert.ok(air.calls.circles[2].layer.style.fillOpacity > 0.2,
  'Hovering one air endpoint also darkens the other endpoint patch');
air.calls.circles[0].layer.events.mouseout();
assert.equal(air.calls.circles[2].layer.style.fillOpacity, 0.2,
  'Hover leave restores both patches');

const sea = fixture({
  stations:[station(1,'Piraeus',38,23.7),
    station(2,'Naxos',37.1,25.4), station(3,'Syros',37.4,24.9)],
  legs:[
    {id_leg:30,from_station_id:1,to_station_id:2,mode:'ferry',
      operator_label:'Vessel A',price:29},
    {id_leg:31,from_station_id:1,to_station_id:3,mode:'ferry',
      operator_label:'Vessel B',price:24}
  ]
});
assert.equal(sea.calls.lines.length, 8,
  'Two differently priced sea journeys stay distinct: two local stitches per pair');
assert.equal(new Set(sea.calls.lines.filter(line => line.options.opacity === 0.001)
  .map(line => line.layer.tooltip.textContent)).size, 2,
  'Each priced sea vessel keeps its independent pair-specific label and hover target');
sea.calls.lines[1].layer.events.mouseover();
assert.ok(sea.calls.circles[0].layer.style.fillOpacity > 0.2,
  'Hovering sea vessel A stitch darkens its boarding anchor patch');
assert.ok(sea.calls.circles[2].layer.style.fillOpacity > 0.2,
  'Hovering sea vessel A stitch darkens its destination patch');
assert.equal(sea.calls.circles[4].layer.style.fillOpacity, 0.2,
  'A second differently priced sea vessel is NOT one fan-out hover unit');
sea.calls.lines[1].layer.events.mouseout();
assert.equal(sea.calls.circles[0].layer.style.fillOpacity, 0.2);
assert.equal(sea.calls.circles[2].layer.style.fillOpacity, 0.2);
console.log('True union (4 circles -> 1 path), stitches, distance, hover and vessels passed');

const routeA = station(1, 'Road start', 38.0, 23.7);
const routeB = station(2, 'Ferry port', 38.05, 23.75);
const routeC = station(3, 'Island port', 37.1, 25.37);
let roadRequests = 0;
const routing = fixture({
  stations: [routeA, routeB, routeC],
  legs: [
    {id_leg:1, from_station_id:1,to_station_id:2, mode:'bus',
      operator_label:'Road bus',price:3,bidirectional:0},
    {id_leg:2, from_station_id:2,to_station_id:3, mode:'ferry',
      operator_label:'Ferry',price:12,bidirectional:0}
  ]
}, {fetch:async url => {
  roadRequests++;
  assert.match(url, /router.project-osrm.org\/route\/v1\/driving/);
  return {ok:true, json:async () => ({
    code:'Ok', routes:[{geometry:{coordinates:[[23.7,38],[23.73,38.03],[23.75,38.05]]}}]
  })};
}});
assert.equal(routing.calls.markers[0].options.icon.options.className, 'grtrans-origin-marker',
  'Shipment origin shows a distinct admin-only pin style');
routing.elements['grtrans-map-preview'].events.click();
assert.equal(routing.calls.polygons.length, 1,
  'Nearby connected bus stations form one union path; ferry gap adds no polygon');
assert.ok(routing.calls.polygons.every(p => !p.layer.removed),
  'Preview never removes or hides coverage unions');
routing.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
routing.calls.mapEvents.click({latlng:{lat:37.1,lng:25.37}});
await new Promise(resolve => setImmediate(resolve));
assert.equal(roadRequests,1,'Road segment uses OSRM, ferry does not');
assert.match(routing.elements['grtrans-preview-status'].textContent,/Route preview ready/);
assert.ok(routing.calls.lines.some(line => line.points.length === 3 &&
  line.options.weight === 5),'Rendered road geometry contains OSRM bend');
assert.ok(routing.calls.lines.some(line => line.options.weight === 5 &&
  line.options.dashArray === '10 5'),'Ferry is a separate port-to-port segment');
routing.elements['grtrans-map-preview-clear'].events.click();
assert.ok(routing.calls.polygons.every(p => !p.layer.removed),
  'Clearing preview keeps the same union coverage path visible');
assert.ok(routing.calls.lines.some(l => l.options.weight === 1.25 && !l.layer.removed),
  'Clearing preview restores priced local connection stitches');
assert.match(routing.elements['grtrans-preview-status'].textContent,/cleared/);
const noRoute = fixture({stations:[routeA,routeC]});
const previousSeams = noRoute.calls.lines.length;
noRoute.elements['grtrans-map-preview'].events.click();
noRoute.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
noRoute.calls.mapEvents.click({latlng:{lat:37.1,lng:25.37}});
await new Promise(resolve => setImmediate(resolve));
assert.match(noRoute.elements['grtrans-preview-status'].textContent,/No directed network connection/);
assert.equal(noRoute.calls.lines.length,previousSeams,'No straight-line fallback on missing route');
const osrmDown = fixture({
  stations:[routeA,routeB],
  legs:[{id_leg:8,from_station_id:1,to_station_id:2,mode:'bus',
    operator_label:'Broken road',price:1,bidirectional:0}]
}, {fetch: async () => {throw Error('OSRM offline');}});
osrmDown.elements['grtrans-map-preview'].events.click();
osrmDown.calls.mapEvents.click({latlng:{lat:38,lng:23.7}});
osrmDown.calls.mapEvents.click({latlng:{lat:38.05,lng:23.75}});
await new Promise(resolve => setImmediate(resolve));
assert.match(osrmDown.elements['grtrans-preview-status'].textContent,/OSRM offline.*No straight-line fallback/);
assert.equal(osrmDown.calls.lines.filter(line => line.options.weight === 5).length,0);
console.log('Admin routed preview, ferry geometry, origin pins and failure contracts passed');
