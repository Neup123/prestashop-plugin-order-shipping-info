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
  // Use the bundled module assets, not paths relative to the BO controller.
  L.Icon.Default.imagePath = data.leafletImagePath;
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
  // Fit actual patch extents, not just station centres: a 1 km circle at
  // country zoom is otherwise nearly invisible. Keep the Greece view for an
  // empty network (no positive-radius coverage).
  const coverageBounds = [];
  const includeCoverage = (centre, radiusKm) => {
    const lat = Number(centre[0]), lng = Number(centre[1]), radius = Number(radiusKm);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) ||
        !Number.isFinite(radius) || radius <= 0) return;
    const latitudeSpan = radius / 111.195;
    const longitudeSpan = radius / (111.195 * Math.max(0.01, Math.cos(lat * Math.PI / 180)));
    coverageBounds.push([Math.max(-90, lat - latitudeSpan), Math.max(-180, lng - longitudeSpan)]);
    coverageBounds.push([Math.min(90, lat + latitudeSpan), Math.min(180, lng + longitudeSpan)]);
  };
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
  // A palette with a different hue for every actual zone, including direct areas.
  // Stable IDs make the legend independent of painting order and price changes.
  const zones = data.stations.map(s => ({
    key: 'station:' + s.id_station, name: s.name
  })).concat(data.legs.filter(leg => !leg.to_station_id &&
    leg.area_lat !== null && leg.area_lng !== null).map(leg => ({
    key: 'area:' + leg.id_leg, name: leg.area_name || 'Direct area #' + leg.id_leg
  }))).sort((a, b) => a.key.localeCompare(b.key));
  const palette = zones.map((zone, index) =>
    'hsl(' + ((17 + 360 * index / zones.length) % 360).toFixed(5) + ' 67% 42%)');
  const zoneColors = new Map(zones.map((zone, index) => [zone.key, palette[index]]));
  const legend = document.getElementById('grtrans-zone-legend');
  if (legend) zones.forEach(zone => {
    const item = document.createElement('span');
    item.className = 'grtrans-zone-key';
    item.setAttribute('role', 'listitem');
    const swatch = document.createElement('span');
    swatch.className = 'grtrans-zone-swatch';
    swatch.style.backgroundColor = zoneColors.get(zone.key);
    swatch.setAttribute('aria-hidden', 'true');
    const name = document.createElement('span');
    name.textContent = zone.name;
    item.appendChild(swatch);
    item.appendChild(name);
    legend.appendChild(item);
  });
  const color = type => baseColor[type] || baseColor.other;
  // Keep seam layers separate from the route preview (#96), which can hide
  // schematic joins to expose actual travel paths without touching coverage.
  const seamLayers = [];
  const paintSeam = layer => {
    seamLayers.push(layer);
    return layer.addTo(map);
  };
  // A seam belongs to patch rims rather than station centres. Project only
  // locally in kilometres to find the overlap chord / closest outer edges.
  const drawSeam = (from, to, fromRadius, toRadius, label, stale, fromColor, toColor) => {
    const a = point(from), b = point(to);
    const latScale = 111.195;
    const lngScale = latScale * Math.max(0.01, Math.cos((a[0] + b[0]) / 2 * Math.PI / 180));
    const dx = (b[0] - a[0]) * latScale, dy = (b[1] - a[1]) * lngScale;
    const d = Math.hypot(dx, dy);
    if (d < 0.00001) return; // Coincident zone centres have no defined join.
    const ux = dx / d, uy = dy / d;
    const ra = Math.max(0, Number(fromRadius)), rb = Math.max(0, Number(toRadius));
    const at = (along, across) => [
      a[0] + (ux * along - uy * across) / latScale,
      a[1] + (uy * along + ux * across) / lngScale
    ];
    const options = {color: '#343e50', weight: 2.5,
      opacity: stale ? 0.32 : 0.9, interactive: false};
    let along, half, spanStart, spanEnd;
    if (d > ra + rb) {
      // Disjoint patches: stitch between their facing rims, *not* station pins.
      // This is a schematic link across a gap, never a coverage polygon.
      spanStart = ra; spanEnd = d - rb;
    } else if (d + Math.min(ra, rb) < Math.max(ra, rb)) {
      // One circle inside the other: choose an inner-circle rim.
      along = ra < rb ? -ra : d - rb;
      half = Math.max(0.2, Math.min(1.5, Math.min(ra, rb) * 0.4));
    } else {
      // Intersecting patches: sew along their common overlap chord.
      along = (ra * ra - rb * rb + d * d) / (2 * d);
      half = Math.sqrt(Math.max(0, ra * ra - along * along));
      half = Math.max(0.18, Math.min(half, 10));
    }
    if (spanStart !== undefined) {
      // Disjoint circles have no literal shared edge. Draw a broad, gently
      // curved two-colour fabric bridge between their FACING rims, not a road
      // or a station-to-station line. The hover label discloses the schematic
      // gap: this cloth-style join does not grant delivery coverage.
      const gap = spanEnd - spanStart;
      const bow = Math.min(4, gap * 0.14);
      const width = Math.max(0.35, Math.min(2.5, Math.min(ra, rb) * 0.55 + gap * 0.008));
      const sample = t => {
        const x = spanStart + gap * t;
        const y = bow * Math.sin(Math.PI * t);
        const tx = gap, ty = bow * Math.PI * Math.cos(Math.PI * t);
        const length = Math.hypot(tx, ty);
        return {x, y, nx: -ty / length, ny: tx / length};
      };
      const edge = (t, side) => {
        const p = sample(t);
        return at(p.x + p.nx * side * width, p.y + p.ny * side * width);
      };
      const samples = Array.from({length: 17}, (_, i) => i / 16);
      const middle = samples.map(t => edge(t, 0));
      const left = samples.map(t => edge(t, -1));
      const right = samples.map(t => edge(t, 1));
      const opacity = stale ? 0.22 : 0.62;
      // Two adjacent wide patch-coloured halves, not a single dashed line.
      const leftFabric = paintSeam(L.polygon([...left, ...middle.slice().reverse()], {
        color: fromColor, fillColor: fromColor, fillOpacity: opacity,
        opacity, weight: 2, interactive: false
      }));
      paintSeam(L.polygon([...middle, ...right.slice().reverse()], {
        color: toColor, fillColor: toColor, fillOpacity: opacity,
        opacity, weight: 2, interactive: false
      }));
      // Pixel-width edges remain clearly fabric-like even at national zoom.
      paintSeam(L.polyline(left, {color: fromColor, weight: 10,
        opacity: stale ? 0.25 : 0.75, interactive: false}));
      paintSeam(L.polyline(right, {color: toColor, weight: 10,
        opacity: stale ? 0.25 : 0.75, interactive: false}));
      const thread = paintSeam(L.polyline(middle, {
        color: '#303744', weight: 3, opacity: stale ? 0.3 : 0.92,
        dashArray: '3 6', interactive: true
      })).bindTooltip(safeTooltip(label +
        ' · schematic sewn gap between patches; NOT coverage or a routed path'));
      const stitches = Math.max(8, Math.min(28, Math.ceil(gap / 4)));
      for (let i = 1; i < stitches; i++) {
        const t = i / stitches;
        paintSeam(L.polyline([edge(Math.max(0, t - 0.014), -1),
          edge(Math.min(1, t + 0.014), 1)], {
          color: '#303744', weight: 2.5,
          opacity: stale ? 0.28 : 0.94, interactive: false
        }));
      }
      return leftFabric;
    }
    const seam = paintSeam(L.polyline([at(along, -half), at(along, half)], {
      ...options, dashArray: '4 4', interactive: true
    })).bindTooltip(safeTooltip(label));
    // Short perpendicular stitches cross the seam, as on sewn fabric.
    const ticks = 8;
    for (let i = 0; i <= ticks; i++) {
      const cross = -half + 2 * half * i / ticks;
      paintSeam(L.polyline([at(along - 0.16, cross), at(along + 0.16, cross)], options));
    }
    return seam;
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
    includeCoverage(pos, s.outer_radius_km);
    const c = zoneColors.get('station:' + s.id_station);
    const title = s.name + (s.is_origin_hub == 1 ? ' · Origin hub' : '')
      + (Object.prototype.hasOwnProperty.call(costs, s.id_station)
        ? ' · cheapest ' + fmt(costs[s.id_station]) + ' EUR' : ' · no priced path');
    [
      { radius: Number(s.outer_radius_km) * 1000, opacity: 0.20 },
      { radius: Number(s.inner_radius_km) * 1000, opacity: 0.10 }
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
        fillColor: zoneColors.get('area:' + leg.id_leg), color: zoneColors.get('area:' + leg.id_leg), fillOpacity: leg._stale ? 0.1 : 0.24,
        opacity: leg._stale ? 0.2 : 0.5, weight: 1
      }).addTo(map).bindTooltip(safeTooltip((leg.area_name || 'Direct area') + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg)));
      includeCoverage(point(to), leg.area_radius_km);
    }
    if (!to) return;
    const title = (leg.operator_label || leg.mode) + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg);
    drawSeam(from, to, from.outer_radius_km, isArea ? leg.area_radius_km : to.outer_radius_km,
      title, leg._stale, zoneColors.get('station:' + from.id_station),
      zoneColors.get(isArea ? 'area:' + leg.id_leg : 'station:' + to.id_station));
  });
  data.transfers.forEach(transfer => {
    const from = stations.get(Number(transfer.from_station_id));
    const to = stations.get(Number(transfer.to_station_id));
    if (!from || !to) return;
    drawSeam(from, to, from.outer_radius_km, to.outer_radius_km,
      transfer.connection_type + ' transfer · ' + fmt(transfer.price) + ' EUR' + staleLabel(transfer),
      transfer._stale, zoneColors.get('station:' + from.id_station),
      zoneColors.get('station:' + to.id_station));
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
  if (coverageBounds.length) map.fitBounds(coverageBounds, { padding: [25, 25], maxZoom: 12 });
}());
