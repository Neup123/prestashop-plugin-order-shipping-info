import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../grtransportshipping/views/js/quote.js', import.meta.url), 'utf8');
function fixture(withLeaflet = true) {
  const elements = {};
  for (const id of ['map-option', 'map', 'map-panel', 'show-map', 'pin-lat', 'pin-lng', 'pin-submit']) {
    elements[`grtransport-${id}`] = {
      hidden: true, disabled: true, events: {},
      addEventListener(name, fn) { this.events[name] = fn; }
    };
  }
  elements['grtransport-map'].dataset = { config: JSON.stringify({
    stations: [
      { name: 'Origin <unsafe>', lat: 37.94, lng: 23.63 },
      { name: 'Destination', lat: 37.10, lng: 25.37 }
    ], zoomIn: 'Zoom in', zoomOut: 'Zoom out', marker: 'Destination pin'
  }) };
  const calls = { tiles: 0, markers: [], lines: [], events: {} };
  const map = {
    setView() { return this; },
    on(name, fn) { calls.events[name] = fn; },
    fitBounds(points) { calls.bounds = points; },
    invalidateSize() {}
  };
  const L = {
    map() { return map; },
    control: { zoom() { return { addTo() {} }; } },
    tileLayer() { calls.tiles++; return { addTo() {} }; },
    marker(point, options) {
      const marker = {
        point, options, events: {},
        addTo() { return this; },
        bindPopup(content) { this.popup = content; return this; },
        on(name, fn) { this.events[name] = fn; },
        setLatLng(point) { this.point = point; },
        getLatLng() { return this.point; }
      };
      calls.markers.push(marker);
      return marker;
    },
    polyline(points) { calls.lines.push(points); return { addTo() {} }; }
  };
  vm.runInNewContext(source, {
    window: withLeaflet ? { L } : {}, L,
    document: {
      getElementById(id) { return elements[id]; },
      createTextNode(text) { return { textContent: text }; },
      createElement() { return { href: '', textContent: '', outerHTML: '<a>Map attribution</a>' }; }
    }
  });
  return { elements, calls };
}
const absent = fixture(false);
assert.equal(absent.elements['grtransport-map-option'].hidden, true, 'Unavailable Leaflet leaves text form independent');
const { elements, calls } = fixture();
assert.equal(elements['grtransport-map-option'].hidden, false);
assert.equal(calls.tiles, 0, 'No OSM request before map opt-in');
elements['grtransport-show-map'].events.click();
assert.equal(calls.tiles, 1);
assert.equal(calls.lines.length, 1, 'Route connects both station coordinates');
assert.equal(calls.markers[0].popup.textContent, 'Origin <unsafe>', 'Merchant station names are text nodes');
calls.events.click({ latlng: { lat: 37.11, lng: 25.38 } });
assert.equal(elements['grtransport-pin-lat'].value, 37.11);
assert.equal(elements['grtransport-pin-lng'].value, 25.38);
assert.equal(elements['grtransport-pin-submit'].disabled, false);
const pin = calls.markers.at(-1);
assert.equal(pin.options.draggable, true);
pin.point = { lat: 37.12, lng: 25.39 };
pin.events.dragend();
assert.equal(elements['grtransport-pin-lat'].value, 37.12);
assert.equal(elements['grtransport-pin-lng'].value, 25.39);
elements['grtransport-show-map'].events.click();
assert.equal(calls.tiles, 1, 'Reopening does not recreate map');
console.log('Map opt-in, click/drag, route plotting and absent-Leaflet contracts passed');
