/* The GET text form works without Leaflet, tiles, reverse geocoding or JavaScript. */
(function () {
  'use strict';
  const option = document.getElementById('grtransport-map-option');
  const element = document.getElementById('grtransport-map');
  if (!option || !element || !window.L) return;
  const config = JSON.parse(element.dataset.config);
  L.Icon.Default.imagePath = config.leafletImagePath;
  const panel = document.getElementById('grtransport-map-panel');
  const destination = document.getElementById('grtransport-destination');
  const pinMode = document.getElementById('grtransport-is-pin');
  const latField = document.getElementById('grtransport-pin-lat');
  const lngField = document.getElementById('grtransport-pin-lng');
  const pinSubmit = document.getElementById('grtransport-pin-submit');
  const status = document.getElementById('grtransport-pin-status');
  let map;
  let pin;
  let reverseToken = 0;
  let lookupTimer = null;
  let pendingRequest = null;
  const cancelLookup = () => {
    reverseToken++;
    if (lookupTimer !== null) clearTimeout(lookupTimer);
    lookupTimer = null;
    if (pendingRequest) pendingRequest.abort();
    pendingRequest = null;
  };
  const coordinatesLabel = latlng =>
    'Coordinates: ' + Number(latlng.lat).toFixed(5) + ', ' + Number(latlng.lng).toFixed(5);
  const updateAddress = value => {
    // A human-readable reverse address is informative only. Coordinates,
    // never this text, are sent to the existing pin-pricing adapter.
    destination.value = String(value).slice(0, 128);
  };
  const reverseAddress = latlng => {
    cancelLookup();
    const token = reverseToken;
    updateAddress(coordinatesLabel(latlng));
    status.textContent = 'Looking up the pinned address; pricing uses the pin coordinates.';
    // Debounce clicks/drags to avoid repeated reverse-geocoder requests.
    lookupTimer = setTimeout(async () => {
      lookupTimer = null;
      try {
        if (typeof fetch !== 'function') throw new Error('Reverse geocoder unavailable');
        pendingRequest = typeof AbortController === 'function' ? new AbortController() : null;
        const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18'
          + '&lat=' + encodeURIComponent(latlng.lat) + '&lon=' + encodeURIComponent(latlng.lng);
        const response = await fetch(url, pendingRequest ? {signal:pendingRequest.signal} : {});
        if (!response.ok) throw new Error('Reverse address lookup failed');
        const result = await response.json();
        if (!result || typeof result.display_name !== 'string' || !result.display_name.trim()) {
          throw new Error('No address was returned for this location');
        }
        if (token !== reverseToken) return;
        updateAddress(result.display_name);
        status.textContent = 'Address found. Check price to quote the pinned coordinates.';
      } catch (error) {
        if (token === reverseToken) {
          status.textContent = 'Address lookup unavailable. Coordinates will still be used for pricing.';
        }
      } finally {
        if (token === reverseToken) pendingRequest = null;
      }
    }, 1100);
  };
  function select(latlng) {
    if (!pin) {
      pin = L.marker(latlng, {
        draggable: true, alt: config.marker, title: config.marker
      }).addTo(map);
      pin.on('dragend', () => select(pin.getLatLng()));
    } else pin.setLatLng(latlng);
    pinMode.value = '1';
    latField.value = latlng.lat;
    lngField.value = latlng.lng;
    pinSubmit.disabled = false;
    reverseAddress(latlng);
  }
  // Manual postcode/city edits switch back to normal text pricing instead of
  // silently sending a previously selected pin with a different field value.
  destination.addEventListener('input', () => {
    cancelLookup();
    pinMode.value = '0';
    latField.value = '';
    lngField.value = '';
    pinSubmit.disabled = true;
    if (pin && map) { map.removeLayer(pin); pin = null; }
    status.textContent = 'Manual address entered. Select a new pin to quote coordinates.';
  });
  function show() {
    panel.hidden = false;
    if (map) { map.invalidateSize(); return; }
    map = L.map(element, {zoomControl:false}).setView([38.5,24],6);
    L.control.zoom({zoomInTitle:config.zoomIn,zoomOutTitle:config.zoomOut}).addTo(map);
    const attribution = document.createElement('a');
    attribution.href = 'https://www.openstreetmap.org/copyright';
    attribution.textContent = config.attribution;
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom:19, attribution: attribution.outerHTML
    }).addTo(map);
    map.on('click', event => select(event.latlng));
    // No network stations, origins, coverage patches or routes on this map.
    if (Array.isArray(config.pin) && config.pin.length === 2) {
      const location = {lat:Number(config.pin[0]),lng:Number(config.pin[1])};
      if (Number.isFinite(location.lat) && Number.isFinite(location.lng)) {
        select(location);
        map.setView([location.lat,location.lng],10);
      }
    }
  }
  option.hidden = false;
  document.getElementById('grtransport-show-map').addEventListener('click',show);
}());
