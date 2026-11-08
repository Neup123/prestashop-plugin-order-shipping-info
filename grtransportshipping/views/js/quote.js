/* The GET text form is independent of Leaflet, tiles and JavaScript. */
(function () {
  'use strict';
  const option = document.getElementById('grtransport-map-option');
  const element = document.getElementById('grtransport-map');
  if (!option || !element || !window.L) return;
  const config = JSON.parse(element.dataset.config);
  const panel = document.getElementById('grtransport-map-panel');
  let map;
  let pin;
  function select(latlng) {
    if (!pin) {
      pin = L.marker(latlng, { draggable: true, alt: config.marker, title: config.marker }).addTo(map);
      pin.on('dragend', () => select(pin.getLatLng()));
    } else pin.setLatLng(latlng);
    document.getElementById('grtransport-pin-lat').value = latlng.lat;
    document.getElementById('grtransport-pin-lng').value = latlng.lng;
    document.getElementById('grtransport-pin-submit').disabled = false;
  }
  function show() {
    panel.hidden = false;
    if (map) { map.invalidateSize(); return; }
    map = L.map(element, { zoomControl: false }).setView([38.5, 24], 6);
    // Only external request: public OpenStreetMap tiles, after customer opt-in.
    L.control.zoom({ zoomInTitle: config.zoomIn, zoomOutTitle: config.zoomOut }).addTo(map);
    const attribution = document.createElement('a');
    attribution.href = 'https://www.openstreetmap.org/copyright';
    attribution.textContent = config.attribution;
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: attribution.outerHTML
    }).addTo(map);
    map.on('click', event => select(event.latlng));
    const points = config.stations.map(station => {
      const point = [station.lat, station.lng];
      L.marker(point, { alt: station.name, title: station.name }).addTo(map).bindPopup(document.createTextNode(station.name));
      return point;
    });
    if (points.length === 2) {
      L.polyline(points).addTo(map);
      map.fitBounds(points, { padding: [30, 30] });
    }
    if (config.pin) select(L.latLng(config.pin));
  }
  option.hidden = false;
  document.getElementById('grtransport-show-map').addEventListener('click', show);
}());
