import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../grtransportshipping/views/js/quote.js', import.meta.url), 'utf8');
function fixture(withLeaflet = true) {
  const elements = {};
  for (const id of ['map-option', 'map', 'map-panel', 'show-map', 'destination',
    'is-pin', 'pin-lat', 'pin-lng', 'pin-submit', 'pin-status']) {
    elements[`grtransport-${id}`] = {
      hidden: true, disabled: true, events: {}, value: '',
      addEventListener(name, fn) { this.events[name] = fn; }
    };
  }
  elements['grtransport-map'].dataset = {config: JSON.stringify({
    // Deliberate legacy data: even if supplied, customer JS must not draw it.
    stations: [{name:'Private origin',lat:37.94,lng:23.63}],
    zoomIn:'Zoom in',zoomOut:'Zoom out',marker:'Destination pin',
    leafletImagePath:'/modules/grtransportshipping/views/vendor/leaflet/images/'
  })};
  const calls = {tiles:0,markers:[],lines:[],circles:[],events:{},lookups:[]};
  let nextTimer = 0;
  const scheduled = new Map();
  const map = {
    setView() { return this; }, invalidateSize() {},
    removeLayer(layer) { layer.removed = true; },
    on(name, fn) { calls.events[name] = fn; }
  };
  const L = {
    Icon:{Default:{}},
    map() { return map; },
    control:{zoom() {return {addTo(){}};}},
    tileLayer() { calls.tiles++; return {addTo(){}}; },
    marker(point, options) {
      const marker = {
        point,options,events:{},
        addTo() {return this;},
        on(type, fn) {this.events[type] = fn;},
        setLatLng(next) {this.point = next;},
        getLatLng() {return this.point;}
      };
      calls.markers.push(marker);
      return marker;
    },
    polyline(points) {calls.lines.push(points);return {addTo(){}};},
    circle() {calls.circles.push(true);return {addTo(){}};}
  };
  vm.runInNewContext(source, {
    window: withLeaflet ? {L} : {}, L, Map,
    setTimeout(fn) {const id = ++nextTimer; scheduled.set(id, fn); return id;},
    clearTimeout(id) {scheduled.delete(id);},
    fetch: async url => {
      calls.lookups.push(url);
      return {ok:true,json:async () => ({display_name:'Example Street, Greece'})};
    },
    document: {
      getElementById(id) {return elements[id];},
      createElement() {return {href:'',textContent:'',outerHTML:'<a>Attribution</a>'};}
    }
  });
  async function flushLookup() {
    const jobs = [...scheduled.values()];scheduled.clear();
    for (const fn of jobs) await fn();
  }
  return {elements,calls,iconPath:L.Icon.Default.imagePath,flushLookup};
}
const absent = fixture(false);
assert.equal(absent.elements['grtransport-map-option'].hidden,true,
  'No Leaflet means ordinary text form remains available');
const {elements,calls,iconPath,flushLookup} = fixture();
assert.equal(iconPath,'/modules/grtransportshipping/views/vendor/leaflet/images/');
assert.equal(elements['grtransport-map-option'].hidden,false);
assert.equal(calls.tiles,0,'No tile request before customer opt-in');
elements['grtransport-show-map'].events.click();
assert.equal(calls.tiles,1);
assert.equal(calls.markers.length,0,'No stations or origin markers on customer map');
assert.equal(calls.lines.length,0,'No route polyline');
assert.equal(calls.circles.length,0,'No coverage patches');
calls.events.click({latlng:{lat:37.11,lng:25.38}});
assert.equal(calls.markers.length,1,'Only the customer pin renders');
assert.equal(calls.markers[0].options.draggable,true);
assert.equal(elements['grtransport-is-pin'].value,'1');
assert.equal(elements['grtransport-pin-lat'].value,37.11);
assert.equal(elements['grtransport-pin-lng'].value,25.38);
assert.match(elements['grtransport-destination'].value,/Coordinates: 37.11000, 25.38000/);
assert.equal(elements['grtransport-pin-submit'].disabled,false);
await flushLookup();
assert.equal(elements['grtransport-destination'].value,'Example Street, Greece');
assert.match(calls.lookups[0],/nominatim.openstreetmap.org\/reverse/);
const pin = calls.markers[0];
pin.point = {lat:37.12,lng:25.39};
pin.events.dragend();
assert.equal(elements['grtransport-pin-lat'].value,37.12);
assert.match(elements['grtransport-destination'].value,/37.12000/,'Drag refreshes address field');
await flushLookup();
assert.equal(elements['grtransport-destination'].value,'Example Street, Greece');
elements['grtransport-show-map'].events.click();
assert.equal(calls.tiles,1,'Reopening map does not re-create tiles');
elements['grtransport-destination'].value='Νάξος';
elements['grtransport-destination'].events.input();
assert.equal(elements['grtransport-is-pin'].value,'0',
  'Manual input must reset pin pricing mode');
assert.equal(elements['grtransport-pin-submit'].disabled,true);
assert.equal(pin.removed,true);
console.log('Customer-only pin, reverse address, drag and text/pin form synchronization passed');
