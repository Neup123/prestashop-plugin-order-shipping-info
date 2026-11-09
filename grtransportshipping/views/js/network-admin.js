/* The coverage map is a view of the persisted API/repository network, not a second dataset. */
(function () {
  'use strict';
  const root = document.getElementById('grtrans-network-map');
  if (!root) return;
  const status = document.getElementById('grtrans-map-status');
  if (!window.L) {
    status.textContent = 'The local Leaflet map library is unavailable. Network tables and editor remain usable.';
    return;
  }
  const data = JSON.parse(root.dataset.config);
  const stations = new Map(data.stations.map(s => [Number(s.id_station), s]));
  const baseColor = { bus: '#2473b6', ferry: '#168ca2', airport: '#845bb7',
                      flight: '#845bb7', other: '#33875a' };
  const map = L.map(root).setView([38.5, 24], 6);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);
  const selectedButton = document.getElementById('grtrans-map-link');
  const linkForm = document.getElementById('grtrans-map-link-form');
  const linkCancel = document.getElementById('grtrans-link-cancel');
  let chosen = null;
  let linking = false;
  const bounds = [];
  const fmt = n => Number(n).toFixed(2);
  // Render merchant names as text, not HTML inside Leaflet tooltips.
  const safeTooltip = title => {
    const node = document.createElement('span');
    node.textContent = String(title);
    return node;
  };
  const staleLabel = record => record._stale ? ' · STALE (last verified ' + (record.verified_at || 'unknown') + ')' : '';
  const point = s => [Number(s.lat), Number(s.lng)];
  const costs = data.cost || {};
  const color = type => baseColor[type] || baseColor.other;
  const colorForLine = (mode, operator) => {
    // Same transport mode, distinguish operators visually without losing family.
    let hash = 0;
    for (const letter of String(operator)) hash = ((hash << 5) - hash + letter.charCodeAt(0)) | 0;
    const base = color(mode);
    const delta = (Math.abs(hash) % 30) - 15;
    const parts = [1, 3, 5].map(index => parseInt(base.slice(index, index + 2), 16));
    return '#' + parts.map(x => Math.max(0, Math.min(255, x + delta)).toString(16).padStart(2, '0')).join('');
  };
  const select = station => {
    if (linking && chosen && Number(chosen.id_station) !== Number(station.id_station)) {
      document.getElementById('grtrans-link-from').value = chosen.id_station;
      document.getElementById('grtrans-link-to').value = station.id_station;
      // A configurable extra gap extends the default overlapping-inner-ring rule.
      const km = distance(point(chosen), point(station));
      const nearby = km <= Number(chosen.inner_radius_km) + Number(station.inner_radius_km)
        + Number(data.nearbyGapKm || 0);
      document.getElementById('grtrans-link-type').value = nearby ? 'walk' : 'taxi';
      document.getElementById('grtrans-link-price').value = '0';
      linking = false;
      if (nearby) {
        status.textContent = 'Creating free walk transfer between overlapping inner rings.';
        linkForm.requestSubmit();
      } else {
        linkForm.hidden = false;
        status.textContent = 'Stations are far apart. Define how the parcel transfers and its cost.';
        document.getElementById('grtrans-link-type').focus();
      }
      return;
    }
    chosen = station;
    linking = false;
    selectedButton.disabled = false;
    status.textContent = 'Selected ' + station.name + '. Choose Link to select a second station.';
  };
  const distance = (a, b) => {
    const r = Math.PI / 180;
    const dLat = (b[0] - a[0]) * r, dLng = (b[1] - a[1]) * r;
    const x = Math.sin(dLat / 2) ** 2
      + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dLng / 2) ** 2;
    return 6371.0088 * 2 * Math.asin(Math.sqrt(Math.min(1, x)));
  };
  selectedButton.addEventListener('click', () => {
    if (!chosen) return;
    linking = true;
    status.textContent = 'Now click the second station coverage ring.';
  });
  linkCancel.addEventListener('click', () => {
    linkForm.hidden = true;
    linking = false;
    status.textContent = 'Transfer creation cancelled.';
  });
  // Draw costly reachable coverage first, cheapest on top. Unknown paths do not
  // misrepresent coverage as a sellable quote.
  const painted = data.stations.slice().sort((a, b) => {
    const aCost = Object.prototype.hasOwnProperty.call(costs, a.id_station) ? Number(costs[a.id_station]) : Infinity;
    const bCost = Object.prototype.hasOwnProperty.call(costs, b.id_station) ? Number(costs[b.id_station]) : Infinity;
    return bCost - aCost;
  });
  painted.forEach(s => {
    const pos = point(s);
    bounds.push(pos);
    const c = color(s.type);
    const title = s.name + (s.is_origin_hub == 1 ? ' · Origin hub' : '')
      + (Object.prototype.hasOwnProperty.call(costs, s.id_station)
        ? ' · cheapest ' + fmt(costs[s.id_station]) + ' EUR' : ' · no priced path');
    [
      { radius: Number(s.outer_radius_km) * 1000, opacity: 0.33 },
      { radius: Number(s.inner_radius_km) * 1000, opacity: 0.67 }
    ].forEach(ring => L.circle(pos, {
      radius: ring.radius, fillColor: c, color: c, fillOpacity: ring.opacity,
      opacity: 0.7, weight: 1
    }).addTo(map).bindTooltip(safeTooltip(title)).on('click', event => {
      L.DomEvent.stopPropagation(event);
      select(s);
    }));
    L.marker(pos, { title: s.name, alt: s.name })
      .addTo(map).bindTooltip(safeTooltip(title)).on('click', event => {
        L.DomEvent.stopPropagation(event);
        select(s);
      });
  });
  data.legs.forEach(leg => {
    const from = stations.get(Number(leg.from_station_id));
    if (!from) return;
    let to = stations.get(Number(leg.to_station_id));
    const isArea = !to && leg.area_lat !== null && leg.area_lng !== null;
    if (isArea) {
      to = { lat: leg.area_lat, lng: leg.area_lng };
      L.circle(point(to), {
        radius: Number(leg.area_radius_km) * 1000,
        fillColor: color(leg.mode), color: color(leg.mode), fillOpacity: leg._stale ? 0.1 : 0.35,
        opacity: leg._stale ? 0.2 : 0.5, weight: 1
      }).addTo(map).bindTooltip(safeTooltip((leg.area_name || 'Direct area') + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg)));
      bounds.push(point(to));
    }
    if (!to) return;
    const title = (leg.operator_label || leg.mode) + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg);
    L.polyline([point(from), point(to)], {
      color: colorForLine(leg.mode, leg.operator_label), weight: 4,
      opacity: leg._stale ? 0.22 : 0.85,
      dashArray: leg.bidirectional == 1 ? null : '9 5'
    }).addTo(map).bindTooltip(safeTooltip(title));
  });
  data.transfers.forEach(transfer => {
    const from = stations.get(Number(transfer.from_station_id));
    const to = stations.get(Number(transfer.to_station_id));
    if (!from || !to) return;
    L.polyline([point(from), point(to)], {
      color: '#4a4a4a', weight: 3, opacity: transfer._stale ? 0.22 : 0.9, dashArray: '3 8'
    }).addTo(map).bindTooltip(safeTooltip(transfer.connection_type + ' transfer · ' + fmt(transfer.price) + ' EUR' + staleLabel(transfer)));
  });
  map.on('click', event => {
    const lat = document.getElementById('grtrans-network-field-lat');
    const lng = document.getElementById('grtrans-network-field-lng');
    if (lat && lng) {
      lat.value = event.latlng.lat.toFixed(7);
      lng.value = event.latlng.lng.toFixed(7);
      status.textContent = 'Station coordinates selected on the map.';
    }
    const areaLat = document.getElementById('grtrans-network-field-area_lat');
    const areaLng = document.getElementById('grtrans-network-field-area_lng');
    if (areaLat && areaLng) {
      areaLat.value = event.latlng.lat.toFixed(7);
      areaLng.value = event.latlng.lng.toFixed(7);
      status.textContent = 'Direct-area centre selected on the map.';
    }
  });
  if (bounds.length) map.fitBounds(bounds, { padding: [25, 25], maxZoom: 10 });
}());
