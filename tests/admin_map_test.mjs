import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../grtransportshipping/views/js/network-admin.js', import.meta.url), 'utf8');
function fixture(data) {
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
    'grtrans-network-field-area_lat','grtrans-network-field-area_lng'];
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
  const L = {
    Icon: {Default: {}}, DomEvent: {stopPropagation() {}},
    map() { return map; }, tileLayer() { return {addTo() {}}; },
    circle(point, options) { return layer('circles', {point, options}); },
    marker(point, options) { return layer('markers', {point, options}); },
    polyline(points, options) { return layer('lines', {points, options}); }
  };
  vm.runInNewContext(source, {
    window: {L}, L, Map, document: {
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
