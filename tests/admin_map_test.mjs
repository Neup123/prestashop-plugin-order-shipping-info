import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../grtransportshipping/views/js/network-admin.js', import.meta.url), 'utf8');
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
  const calls = {circles: [], lines: [], markers: [], mapEvents: {}};
  const layer = (type, entry) => {
    const value = {
      events: {}, addTo() { return this; }, bindTooltip() { return this; },
      on(event, fn) { this.events[event] = fn; }, setLatLng(p) { this.point = p; },
      getLatLng() { return this.point; }, remove() { this.removed = true; }
    };
    entry.layer = value; calls[type].push(entry); return value;
  };
  const map = {
    setView() { return this; }, fitBounds() {}, on(type, fn) { calls.mapEvents[type] = fn; },
    removeLayer(item) { item.removed = true; }
  };
  class DefaultIcon { constructor(options = {}) { this.options = options; } }
  const L = {
    Icon: {Default: DefaultIcon}, DomEvent: {stopPropagation() {}},
    map() { return map; }, tileLayer() { return {addTo() {}}; },
    circle(point, options) { return layer('circles', {point, options}); },
    marker(point, options) { return layer('markers', {point, options}); },
    polyline(points, options) { return layer('lines', {points, options}); }
  };
  vm.runInNewContext(source, {
    window: {L}, L, Map,
    fetch: options.fetch || (async () => ({ok:true,json:async () => ({
      code:'Ok',routes:[{geometry:{coordinates:[[23.7,38.0],[23.72,38.02],[23.75,38.05]]}}]
    })})), document: {
      getElementById(id) { return elements[id] || null; },
      createElement: make
    }
  });
  return {elements, calls, map};
}
const station = (id, name, lat, lng) => ({
  id_station: id, name, type:'bus', lat, lng,
  inner_radius_km: 2, outer_radius_km: 10, is_origin_hub: id === 1 ? 1 : 0
});
const {calls, elements} = fixture({
  stations: [station(1, '<first>', 37.9, 23.6), station(2, 'Second', 38.2, 23.9)],
  legs: [{id_leg: 9, from_station_id:1, to_station_id:null, mode:'other',
    area_lat:37.8, area_lng:23.5, area_radius_km:4, area_name:'Third area', price:5}],
});
const circles = calls.circles;
assert.equal(circles.length, 5, 'Two inner/outer station pairs and direct-area patch');
assert.equal(circles[0].options.fillOpacity, 0.2, 'Outer radius remains see-through');
assert.equal(circles[1].options.fillOpacity, 0.1, 'Combined inner fill stays translucent');
const colours = [circles[0].options.fillColor, circles[2].options.fillColor, circles[4].options.fillColor];
assert.equal(new Set(colours).size, 3, 'All zone colours must be different');
assert.equal(elements['grtrans-zone-legend'].children.length, 3, 'Legend matches all zones');
const labels = elements['grtrans-zone-legend'].children.map(item => item.children[1].textContent);
assert.ok(labels.includes('<first>'), 'Merchant-supplied zone label is text, never HTML');
assert.equal(calls.markers.length, 2, 'Existing station selection remains available');
console.log('Admin coverage patches, distinct colours and zone legend passed');


const connected = fixture({
  stations: [station(1, 'A', 38.0, 23.7), station(2, 'B', 38.05, 23.75),
    station(3, 'Disconnected', 39, 25)],
  legs: [{id_leg:1, from_station_id:1, to_station_id:2, mode:'bus',
    operator_label:'Test route', price:10, bidirectional:0}],
  transfers: []
});
assert.ok(connected.calls.lines.length > 4, 'Connected zones receive a seam and perpendicular stitches');
for (const line of connected.calls.lines) {
  const [p, q] = line.points;
  const bothStations = (Number(p[0]) === 38 && Number(p[1]) === 23.7 &&
    Number(q[0]) === 38.05 && Number(q[1]) === 23.75);
  assert.equal(bothStations, false, 'No line can be a station-centre connector');
}
const disconnected = fixture({
  stations: [station(1, 'A', 38.0, 23.7), station(2, 'B', 38.05, 23.75)]
});
assert.equal(disconnected.calls.lines.length, 0, 'Unconnected zones show no seams');
const disjoint = fixture({
  stations: [station(1, 'A', 38.0, 23.7), station(2, 'B', 39, 25)],
  transfers: [{id_transfer:5, from_station_id:1,to_station_id:2,
    connection_type:'taxi',price:12}]
});
assert.ok(disjoint.calls.lines.length > 1, 'Disjoint connected rims get a schematic stitched gap');
assert.ok(disjoint.calls.lines[0].layer, 'Schematic connector has a Leaflet layer');
console.log('Admin patch seam and disconnected-zone rendering contracts passed');

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
