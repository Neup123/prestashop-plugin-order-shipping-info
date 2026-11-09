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
  const geometry = window.GrtransCoverage;
  if (!geometry) {
    status.textContent = 'The bundled coverage geometry library is unavailable. Network tables remain usable.';
    return;
  }
  // Use the bundled module assets, not paths relative to the BO controller.
  L.Icon.Default.imagePath = data.leafletImagePath;
  const stations = new Map(data.stations.map(s => [Number(s.id_station), s]));
  const baseColor = { bus: '#2473b6', ferry: '#168ca2', airport: '#845bb7',
                      flight: '#845bb7', other: '#33875a' };
  const map = L.map(root).setView([38.5, 24], 6);
  // Paint coverage in a dedicated vector pane. An intersecting seam must never
  // replace a patch or share its SVG paint stack; keep both above map tiles
  // and below connections and the existing routed-preview overlay.
  const coveragePane = map.createPane('grtrans-coverage-patches');
  coveragePane.style.zIndex = '390';
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
  const includeShape = paths => {
    const visit = value => {
      if (!Array.isArray(value) || !value.length) return;
      if (typeof value[0] === 'number' && typeof value[1] === 'number') {
        coverageBounds.push([value[0], value[1]]);
      } else value.forEach(visit);
    };
    visit(paths);
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
  })).concat(data.legs.filter(leg => leg.to_station_id == null &&
    leg.area_lat != null && leg.area_lng != null &&
    (Number(leg.area_radius_km) > 0 || !!leg.area_coverage_geojson) &&
    stations.has(Number(leg.from_station_id))).map(leg => ({
    key: 'area:' + leg.id_leg, name: leg.area_name || 'Direct area #' + leg.id_leg
  }))).sort((a, b) => a.key.localeCompare(b.key));
  const palette = zones.map((zone, index) =>
    'hsl(' + ((17 + 360 * index / zones.length) % 360).toFixed(5) + ' 67% 42%)');
  const zoneColors = new Map(zones.map((zone, index) => [zone.key, palette[index]]));
  // Only a priced, station-bound leg between overlapping compatible stations
  // establishes shared service. Identical station-type labels alone do not.
  const stationGroups = geometry.stationGroups(data.stations, data.legs, costs);
  const groupsByStation = new Map();
  stationGroups.forEach(group => {
    const sharedColor = zoneColors.get('station:' + group[0].id_station);
    group.forEach(station => {
      groupsByStation.set(Number(station.id_station), group);
      if (group.length > 1) zoneColors.set('station:' + station.id_station, sharedColor);
    });
  });
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
  let previewActive = false;
  const paintSeam = layer => {
    seamLayers.push(layer);
    if (!previewActive) layer.addTo(map);
    return layer;
  };
  // Local organic patch geometry is the base map. Distant air links are the
  // ONE owner-confirmed symbolic exception; distant land/sea routes stay blank.
  const patchStyles = new Map();
  const mentalLinks = new Map();
  const linkGroup = (a, b) => {
    for (const [first, second] of [[a, b], [b, a]]) {
      if (!mentalLinks.has(first)) mentalLinks.set(first, new Set());
      mentalLinks.get(first).add(second);
    }
  };
  const restoreFocus = () => {
    patchStyles.forEach((entry, layer) => layer.setStyle(entry.style));
  };
  const focusExactly = owners => {
    const selected = new Set(owners);
    patchStyles.forEach((entry, layer) => {
      const highlighted = entry.owners.some(owner => selected.has(owner));
      layer.setStyle({...entry.style, fillOpacity: highlighted
        ? Math.min(0.54, entry.style.fillOpacity + 0.22) : entry.style.fillOpacity});
    });
  };
  const focusOwners = owners => {
    const selected = new Set(owners);
    owners.forEach(owner => (mentalLinks.get(owner) || []).forEach(other => selected.add(other)));
    focusExactly([...selected]);
  };
  const registerPatch = (owners, layer, style) => {
    patchStyles.set(layer, {owners, style});
    layer.on('mouseover', () => focusOwners(owners));
    layer.on('mouseout', restoreFocus);
    return layer;
  };
  const stationPatch = station => ({
    lat: Number(station.lat), lng: Number(station.lng),
    radiusKm: Number(station.outer_radius_km), key: 'station:' + station.id_station,
    coverage_kind: station.coverage_kind, coverage_geojson: station.coverage_geojson
  });
  const areaPatch = (leg) => ({
    lat: Number(leg.area_lat), lng: Number(leg.area_lng),
    radiusKm: Number(leg.area_radius_km), key: 'area:' + leg.id_leg,
    area_coverage_kind: leg.area_coverage_kind,
    area_coverage_geojson: leg.area_coverage_geojson
  });
  const pairLanes = new Map();
  const drawStitch = (from, to, label, stale, fromOwner, toOwner, lane) => {
    const ca = costs[fromOwner.replace('station:', '')];
    const cb = costs[toOwner.replace('station:', '')];
    const priceOrder = Number.isFinite(Number(ca)) && Number.isFinite(Number(cb)) &&
      ca !== undefined && cb !== undefined ? [Number(ca), Number(cb)] : null;
    const pieces = geometry.stitches(from, to, lane, priceOrder);
    for (const endpoints of pieces) {
      paintSeam(L.polyline(endpoints, {
        color: '#637384', weight: 1.25,
        opacity: stale ? 0.22 : 0.55, dashArray: '2 5', interactive: false,
        className: 'grtrans-zigzag-stitch'
      }));
      const target = paintSeam(L.polyline(endpoints, {
        color: '#637384', weight: 10, opacity: 0.001, interactive: true
      })).bindTooltip(safeTooltip(label));
      // An individual hover target selects only this priced pair. Hovering a
      // multi-vessel origin patch shows its separate, legitimately linked ends.
      target.on('mouseover', () => focusExactly([fromOwner, toOwner]));
      target.on('mouseout', restoreFocus);
    }
  };
  // Panel H/v7: a priced direct-area leg explicitly joins its boarding
  // station island to the sea. If that island is fully contained, stitch its
  // WHOLE outer perimeter; do not invent a seam around an unrelated island.
  // Unlike the short lens stitch, this full perimeter can exceed 1.8 km.
  const drawIslandPerimeter = (sea, island, label, stale, owners) => {
    if (!geometry.containsPatch(sea, island)) return false;
    const rings = geometry.islandStitchRings(island);
    if (!rings.length) return false;
    rings.forEach(ring => {
      paintSeam(L.polyline(ring, {
        color: '#ad5165', weight: 1.25, opacity: stale ? 0.22 : 0.55,
        dashArray: '2 5', interactive: false,
        className: 'grtrans-linked-island-perimeter'
      }));
      paintSeam(L.polyline(ring, {
        color: '#ad5165', weight: 10, opacity: 0.001, interactive: true,
        className: 'grtrans-linked-island-perimeter-hover'
      })).bindTooltip(safeTooltip(label + ' · entire linked island boundary'))
        .on('mouseover', () => focusExactly(owners))
        .on('mouseout', restoreFocus);
    });
    return true;
  };
  const drawAirArc = (from, to, label, stale, fromOwner, toOwner, bidirectional) => {
    const arc = geometry.airArc([from.lat, from.lng], [to.lat, to.lng], bidirectional);
    if (!arc) return;
    const paintArrow = coords => paintSeam(L.polyline(coords, {
      color: '#7453a7', weight: 1.85, opacity: stale ? 0.37 : 0.85,
      interactive: false, className: 'grtrans-air-arrow'
    }));
    paintSeam(L.polyline(arc.points, {
      color: '#7453a7', weight: 1.8, opacity: stale ? 0.35 : 0.78,
      dashArray: '7 7', interactive: true, className: 'grtrans-air-arc'
    })).bindTooltip(safeTooltip(label + ' · symbolic flight arc; not area coverage'))
      .on('mouseover', () => focusExactly([fromOwner, toOwner]))
      .on('mouseout', restoreFocus);
    paintArrow(arc.arrow);
    if (arc.reverseArrow) paintArrow(arc.reverseArrow);
  };
  // A sea vessel is an individual priced pair, not a fan-out unit.
  const drawSeaLink = (from, to, label, stale, fromOwner, toOwner) => {
    const sea=geometry.seaLine(from,to);
    if (!sea) return;
    const shade = '#168ca2';
    paintSeam(L.polyline(sea.points, {
      color: shade, dashArray: '8 7', opacity: stale ? 0.32 : 0.8,
      weight: 2.2, interactive: true, className: 'grtrans-sea-line'
    })).bindTooltip(safeTooltip(label + ' · vessel connection; NOT maritime coverage'))
      .on('mouseover', () => focusExactly([fromOwner, toOwner]))
      .on('mouseout', restoreFocus);
    paintSeam(L.polyline(sea.arrow, {
      color: shade, opacity: stale ? 0.4 : 0.9,
      weight: 1.7, interactive: false, className: 'grtrans-sea-arrow'
    }));
  };
  const interpolatePath = (points, slices = 20) => {
    const lengths=points.slice(1).map((p,i)=>distance(p,points[i]));
    const total=lengths.reduce((a,b)=>a+b,0);
    if (!total) return [points[0],points[points.length-1]];
    const pointAt = fraction => {
      let remaining = fraction * total;
      for(let i=0;i<lengths.length;i++) {
        if (remaining <= lengths[i] || i===lengths.length-1) {
          const t=lengths[i] ? Math.max(0,Math.min(1,remaining/lengths[i])) : 0;
          return [points[i][0]+t*(points[i+1][0]-points[i][0]),
            points[i][1]+t*(points[i+1][1]-points[i][1])];
        }
        remaining-=lengths[i];
      }
      return points[points.length-1];
    };
    return Array.from({length:slices+1},(_,i)=>pointAt(i/slices));
  };
  const routeColor = (base, t) => {
    // Keep the same service hue; use progressively darker lightness at the
    // higher-priced station. Path color is MORE opaque than the patch fill.
    const parts=/hsl\(([\d.]+)\s+(\d+)%\s+(\d+)%\)/.exec(base);
    const h=parts ? Number(parts[1]) : 210;
    return 'hsl(' + h + ' 67% ' + (54 - 27 * t).toFixed(1) + '%)';
  };
  const drawLandPath = (path, priceFrom, priceTo, from, to, stale) => {
    const segments = Array.isArray(path[0]?.[0]) ? path : [path];
    const a=zoneColors.get('station:'+from.id_station);
    const cheaper=Number(priceFrom)<Number(priceTo);
    const different=Number.isFinite(priceFrom)&&Number.isFinite(priceTo)&&Math.abs(priceFrom-priceTo)>.005;
    const startingFromCheap=!different || cheaper;
    const base=zoneColors.get('station:'+(startingFromCheap?from.id_station:to.id_station));
    for(const segment of segments) {
      if (!Array.isArray(segment) || segment.length<2) continue;
      if(!different) {
        paintSeam(L.polyline(segment,{color:a,weight:3.5,opacity:stale?.42:.92,
          interactive:false,className:'grtrans-land-road'}));
        continue;
      }
      const points=interpolatePath(segment,24);
      for(let k=0;k<points.length-1;k++){
        const t=(k+.5)/(points.length-1);
        const priceProgress=startingFromCheap ? t : 1-t;
        paintSeam(L.polyline([points[k],points[k+1]],{
          color:routeColor(base,priceProgress),weight:4.2,
          opacity:stale?.4:.94,interactive:false,
          className:'grtrans-land-gradient'
        }));
      }
    }
  };
  const roadCache = new Map();
  const drawLandLink = (fromStation,toStation,leg,label,stale) => {
    const stored=geometry.routePaths(leg.route_geojson);
    const ca=Object.hasOwn(costs,fromStation.id_station)?Number(costs[fromStation.id_station]):NaN;
    const cb=Object.hasOwn(costs,toStation.id_station)?Number(costs[toStation.id_station]):NaN;
    const from={lat:Number(fromStation.lat),lng:Number(fromStation.lng)};
    const to={lat:Number(toStation.lat),lng:Number(toStation.lng)};
    const key=[from.lat,from.lng,to.lat,to.lng].join(':');
    const points=stored.length?Promise.resolve(stored):
      (roadCache.get(key) || (()=>{
        const task=roadGeometry(from,to).then(pts=>[pts]).catch(()=>[[point(from),point(to)]]);
        roadCache.set(key,task);return task;
      })());
    points.then(paths => drawLandPath(paths,ca,cb,fromStation,toStation,stale));
  };
  const renderConnection = (mode, from, to, label, stale, fromOwner, toOwner, bidirectional, leg = null) => {
    const ends = [fromOwner, toOwner].sort();
    const key = ends.join('=>');
    const lane = pairLanes.get(key) || 0;
    pairLanes.set(key, lane + 1);
    // All kind-specific lines are TRANSPORT ONLY, never coverage polygons.
    // Touching priced patches additionally get the cheap-boundary transfer
    // stitch, regardless of kind.
    if (mode === 'air' && !geometry.overlaps(from, to)) {
      drawAirArc(from, to, label, stale, fromOwner, toOwner, bidirectional);
    } else if (mode === 'sea' && !geometry.overlaps(from, to)) {
      drawSeaLink(from, to, label, stale, fromOwner, toOwner);
    } else if (mode !== 'station-bound' || !leg) {
      drawStitch(from, to, label, stale, fromOwner, toOwner, lane);
    }
    if (mode === 'station-bound' && leg) {
      if (geometry.overlaps(from,to)) drawStitch(from,to,label,stale,fromOwner,toOwner,lane);
      drawLandLink(leg.fromStation,leg.toStation,leg,label,stale);
    }
  };
  const previewButton = document.getElementById('grtrans-map-preview');
  const clearButton = document.getElementById('grtrans-map-preview-clear');
  const previewStatus = document.getElementById('grtrans-preview-status');
  let previewSelecting = false;
  let previewGeneration = 0;
  let previewPoints = [];
  let previewMarkers = [];
  let previewLayers = [];
  const previewMessage = message => { if (previewStatus) previewStatus.textContent = message; };
  const clearPreview = () => {
    previewGeneration++;
    previewMarkers.forEach(layer => map.removeLayer(layer));
    previewLayers.forEach(layer => map.removeLayer(layer));
    previewMarkers = [];
    previewLayers = [];
    previewPoints = [];
    previewSelecting = false;
    previewActive = false;
    // Restore transport lines and local seams after the preview is cleared.
    seamLayers.forEach(layer => layer.addTo(map));
  };
  const select = station => {
    if (previewSelecting) {
      addPreviewPin({lat: Number(station.lat), lng: Number(station.lng)});
      return;
    }
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
  // Preview is read-only. It uses the persisted directed leg/transfer graph
  // without changing prices, quote eligibility, forms or stored network data.
  const nearestCoveredStation = p => {
    let best = null, bestKm = Infinity;
    for (const station of data.stations) {
      const km = distance([p.lat, p.lng], point(station));
      if (km <= Number(station.outer_radius_km) && km < bestKm) {
        best = station; bestKm = km;
      }
    }
    return best;
  };
  const previewPath = (start, finish) => {
    const adjacency = new Map();
    const add = edge => {
      if (!adjacency.has(edge.from)) adjacency.set(edge.from, []);
      adjacency.get(edge.from).push(edge);
    };
    for (const leg of data.legs) {
      const from = Number(leg.from_station_id), to = Number(leg.to_station_id);
      if (!stations.has(from) || !stations.has(to) || Number(leg.price) < 0) continue;
      add({from, to, mode: leg.mode, price: Number(leg.price)});
      if (Number(leg.bidirectional) === 1) {
        add({from: to, to: from, mode: leg.mode, price: Number(leg.price)});
      }
    }
    for (const transfer of data.transfers) {
      const from = Number(transfer.from_station_id), to = Number(transfer.to_station_id);
      if (!stations.has(from) || !stations.has(to) || Number(transfer.price) < 0) continue;
      add({from, to, mode: transfer.connection_type, price: Number(transfer.price)});
    }
    const cost = new Map([[Number(start.id_station), 0]]);
    const parent = new Map();
    const pending = new Set(stations.keys());
    while (pending.size) {
      let current = null, best = Infinity;
      for (const id of pending) {
        if ((cost.get(id) ?? Infinity) < best) {
          current = id; best = cost.get(id);
        }
      }
      if (current === null || current === Number(finish.id_station)) break;
      pending.delete(current);
      for (const edge of adjacency.get(current) || []) {
        const candidate = best + edge.price;
        if (pending.has(edge.to) && candidate + 0.000001 < (cost.get(edge.to) ?? Infinity)) {
          cost.set(edge.to, candidate); parent.set(edge.to, edge);
        }
      }
    }
    const steps = [];
    let cursor = Number(finish.id_station);
    while (cursor !== Number(start.id_station)) {
      const edge = parent.get(cursor);
      if (!edge) throw new Error('No directed network connection between the selected coverage zones.');
      steps.unshift(edge);
      cursor = edge.from;
      if (steps.length > stations.size) throw new Error('Invalid network route cycle.');
    }
    return steps;
  };
  const roadGeometry = async (a, b) => {
    const coords = p => Number(p.lng).toFixed(7) + ',' + Number(p.lat).toFixed(7);
    // The OSRM demo service is used only after an explicit admin preview.
    const url = 'https://router.project-osrm.org/route/v1/driving/'
      + coords(a) + ';' + coords(b) + '?overview=full&geometries=geojson';
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), 12000) : null;
    try {
      const response = await fetch(url, controller ? {signal: controller.signal} : {});
      if (!response.ok) throw new Error('OSRM returned HTTP ' + response.status);
      const payload = await response.json();
      const coordinates = payload.routes && payload.routes[0] && payload.routes[0].geometry
        && payload.routes[0].geometry.coordinates;
      if (payload.code !== 'Ok' || !Array.isArray(coordinates) || coordinates.length < 2) {
        throw new Error('OSRM has no road route for this segment');
      }
      return coordinates.map(p => [p[1], p[0]]);
    } finally {
      if (timer !== null) clearTimeout(timer);
    }
  };
  const previewGeometry = (a, b, mode) => {
    if (mode === 'ferry') return Promise.resolve([ [a.lat, a.lng], [b.lat, b.lng] ]);
    if (mode === 'flight') return Promise.reject(new Error(
      'Flight path cannot be previewed: no air-route geometry is stored.'));
    // Driving geometry follows roads; no stored bus schedules, pedestrian paths
    // or stop-by-stop itineraries exist in the network model.
    return roadGeometry(a, b);
  };
  async function renderPreview(generation) {
    const [a, b] = previewPoints;
    const from = nearestCoveredStation(a), to = nearestCoveredStation(b);
    if (!from || !to) throw new Error(
      'A pin is outside every station coverage radius. No network route can be previewed.');
    const path = previewPath(from, to);
    const pieces = [];
    const append = async (p, q, mode) => {
      if (distance([p.lat,p.lng],[q.lat,q.lng]) < 0.005) return;
      const points = await previewGeometry(p, q, mode);
      pieces.push({points, mode});
    };
    const asPoint = station => ({lat:Number(station.lat), lng:Number(station.lng)});
    if (Number(from.id_station) === Number(to.id_station)) {
      await append(a, b, 'bus');
    } else {
      await append(a, asPoint(from), 'bus');
      for (const edge of path) {
        await append(asPoint(stations.get(edge.from)), asPoint(stations.get(edge.to)), edge.mode);
      }
      await append(asPoint(to), b, 'bus');
    }
    if (generation !== previewGeneration) return; // Ignore an obsolete preview response.
    for (const piece of pieces) {
      const ferry = piece.mode === 'ferry';
      const layer = L.polyline(piece.points, {
        color: ferry ? '#138799' : '#2259b5',
        weight: 5, opacity: 0.95, dashArray: ferry ? '10 5' : null
      }).addTo(map);
      previewLayers.push(layer);
    }
    if (pieces.length) map.fitBounds(pieces.flatMap(piece => piece.points), {
      padding: [25,25], maxZoom: 12
    });
    previewMessage('Route preview ready: ' + path.length + ' stored network legs. '
      + 'Roads: OSRM driving geometry; ferries: direct port-to-port segments. '
      + 'This is not a timetable or pedestrian itinerary.');
  }
  function addPreviewPin(latlng) {
    if (!previewSelecting || previewPoints.length >= 2) return;
    previewPoints.push({lat:Number(latlng.lat),lng:Number(latlng.lng)});
    const marker = L.marker(latlng, {
      title: 'Preview point ' + previewPoints.length,
      alt: 'Preview point ' + previewPoints.length,
      icon: new L.Icon.Default({className:'grtrans-preview-pin'})
    }).addTo(map);
    previewMarkers.push(marker);
    if (previewPoints.length === 1) {
      previewMessage('Starting pin set. Choose the destination point or station.');
      return;
    }
    previewSelecting = false;
    const generation = ++previewGeneration;
    previewMessage('Computing routed preview…');
    renderPreview(generation).catch(error => {
      if (generation === previewGeneration) {
        previewMessage('Route preview unavailable: ' + error.message + ' No straight-line fallback.');
      }
    });
  }
  if (previewButton && clearButton) {
    previewButton.addEventListener('click', () => {
      clearPreview();
      // Hide symbolic arcs and local stitches during a deliberately requested
      // route preview; coverage polygons remain visible throughout.
      seamLayers.forEach(layer => map.removeLayer(layer));
      previewActive = true;
      previewSelecting = true;
      previewMessage('Choose a starting point on the map or click a station pin.');
    });
    clearButton.addEventListener('click', () => {
      clearPreview();
      previewMessage('Preview cleared. Coverage patches remain visible.');
    });
  }
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
  // The large dynamic service-area patches are the background 'sea'.
  // Draw stations AFTER them as opaque-edged translucent islands; no holes.
  data.legs.forEach(leg => {
    const from = stations.get(Number(leg.from_station_id));
    if (!from || leg.to_station_id != null || leg.area_lat == null ||
        leg.area_lng == null || (!(Number(leg.area_radius_km) > 0) &&
        !leg.area_coverage_geojson)) return;
    const area = {lat: leg.area_lat, lng: leg.area_lng};
    const c = zoneColors.get('area:' + leg.id_leg);
    const style = {
      pane: 'grtrans-coverage-patches', fill: true, className: 'grtrans-organic-patch',
      fillColor: c, color: c, fillOpacity: leg._stale ? 0.1 : 0.24,
      opacity: leg._stale ? 0.2 : 0.5, weight: 1
    };
    const owner = 'area:' + leg.id_leg;
    const layer = L.polygon(geometry.leafletPaths(geometry.patchPaths(areaPatch(leg))),
      style).addTo(map).bindTooltip(
      safeTooltip((leg.area_name || 'Direct area') + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg)));
    registerPatch([owner], layer, style);
    if (leg.area_coverage_geojson && leg.area_coverage_kind !== 'radius') {
      includeShape(geometry.patchPaths(areaPatch(leg)));
    } else includeCoverage(point(area), leg.area_radius_km);
  });

  // Draw expensive reachable patches first and cheapest ones on top.
  const painted = data.stations.slice().sort((a, b) => {
    const aCost = Object.prototype.hasOwnProperty.call(costs, a.id_station) ? Number(costs[a.id_station]) : Infinity;
    const bCost = Object.prototype.hasOwnProperty.call(costs, b.id_station) ? Number(costs[b.id_station]) : Infinity;
    return bCost - aCost;
  });
  painted.forEach(s => {
    if (s.coverage_geojson && s.coverage_kind !== 'radius') {
      includeShape(geometry.patchPaths(stationPatch(s)));
    } else includeCoverage(point(s), s.outer_radius_km);
  });
  const paintedGroups = new Set();
  painted.forEach(s => {
    const group = groupsByStation.get(Number(s.id_station)) || [s];
    if (group.length > 1) {
      // ONE true union perimeter for the entire overlapping service.
      // Every internal circle arc has been discarded by the geometry helper.
      const groupId = group.map(station => station.id_station).sort((a, b) => a - b).join(',');
      if (paintedGroups.has(groupId)) return;
      paintedGroups.add(groupId);
      const contours = geometry.unionContours(group.map(stationPatch));
      if (contours.length) {
        const c = zoneColors.get('station:' + group[0].id_station);
        const owners = group.map(station => 'station:' + station.id_station);
        const title = safeTooltip(group.map(station => station.name).join(' + ') +
          ' · merged same-price coverage');
        const mergedTier = (kind, rings, fillOpacity) => {
          if (!rings.length) return;
          const outer = kind === 'far';
          const style = {pane:'grtrans-coverage-patches',fill:true,
            className:'grtrans-organic-patch grtrans-tier-'+kind,
            fillColor:c,color:c,fillOpacity,weight:outer?1:0,
            stroke:outer,opacity:outer?0.65:0};
          const latlngs = rings.length === 1 ? rings[0] : rings.map(ring=>[ring]);
          const layer=L.polygon(latlngs,style).addTo(map).bindTooltip(title);
          registerPatch(owners,layer,style);
          layer.on('click',event=>{
            L.DomEvent.stopPropagation(event);
            const p=[event.latlng.lat,event.latlng.lng];
            select(group.reduce((best,station)=>
              distance(p,point(station))<distance(p,point(best))?station:best,group[0]));
          });
        };
        // Outer silhouette merges as ONE border. Inner tier is also unioned
        // (without any border) so no old internal station circles reappear.
        mergedTier('far',contours,0.16);
        const innerRecords=[];
        group.forEach(station=>{
          const inner=geometry.coverageTiers(stationPatch(station),
            station.inner_radius_km).find(tier=>tier.kind==='normal');
          if (!inner) return;
          inner.paths.forEach((poly,index)=>{
            const coordinates=poly.map(ring=>{
              const closed=ring.concat([ring[0]]);
              return closed.map(p=>[p[1],p[0]]);
            });
            innerRecords.push({lat:station.lat,lng:station.lng,
              radiusKm:Number(station.inner_radius_km),
              key:'normal:'+station.id_station+':'+index,
              coverage_geojson:JSON.stringify({type:'Polygon',coordinates})});
          });
        });
        mergedTier('normal',geometry.unionContours(innerRecords),0.42);
      }
      return;
    }
    const pos = point(s);
    const c = zoneColors.get('station:' + s.id_station);
    const title = s.name + (s.is_origin_hub == 1 ? ' · Origin hub' : '')
      + (Object.prototype.hasOwnProperty.call(costs, s.id_station)
        ? ' · cheapest ' + fmt(costs[s.id_station]) + ' EUR' : ' · no priced path');
    geometry.coverageTiers(stationPatch(s),s.inner_radius_km).forEach(tier=>{
      if(!tier.paths.length) return;
      const style={
        pane:'grtrans-coverage-patches',fill:true,
        className:'grtrans-organic-patch grtrans-tier-'+tier.kind,
        fillColor:c,color:c,fillOpacity:tier.fillOpacity,
        opacity:tier.outline?0.6:0,weight:tier.outline?1:0,
        stroke:tier.outline
      };
      const layer=L.polygon(geometry.leafletPaths(tier.paths),style)
        .addTo(map).bindTooltip(safeTooltip(title+' · '+(tier.kind==='normal'
          ? 'normal service reach' : 'FAR zone: customer confirmation required')));
      registerPatch(['station:'+s.id_station],layer,style);
      layer.on('click',event=>{
        L.DomEvent.stopPropagation(event);
        select(s);
      });
    });
  });
  // Pins are independent even when their filled coverage has one silhouette.
  data.stations.forEach(s => {
    const title = s.name + (Number(s.is_origin_hub) === 1 ? ' · Origin hub' : '');
    L.marker(point(s), {title: s.name, alt: s.name,
      ...(Number(s.is_origin_hub) === 1 ? {
        icon: new L.Icon.Default({className: 'grtrans-origin-marker'})
      } : {})})
      .addTo(map).bindTooltip(safeTooltip(title)).on('click', event => {
        L.DomEvent.stopPropagation(event);
        select(s);
      });
  });

  // Retain every priced record. Sea connections are NEVER grouped into a
  // fan-out vessel; distinct prices and destinations receive distinct stitches
  // and independent hover targets. Unknown kinds get local stitches only.
  data.legs.forEach(leg => {
    const from = stations.get(Number(leg.from_station_id));
    if (!from || !Number.isFinite(Number(leg.price)) || Number(leg.price) < 0) return;
    const toStation = stations.get(Number(leg.to_station_id));
    const isArea = !toStation && leg.to_station_id == null &&
      leg.area_lat != null && leg.area_lng != null &&
      (Number(leg.area_radius_km) > 0 || !!leg.area_coverage_geojson);
    const to = toStation || (isArea ? {lat: leg.area_lat, lng: leg.area_lng} : null);
    if (!to) return;
    const mode = geometry.kind(leg.mode, isArea);
    const a = 'station:' + from.id_station;
    const b = isArea ? 'area:' + leg.id_leg : 'station:' + to.id_station;
    if (mode === 'air' || mode === 'sea' || isArea) linkGroup(a, b);
    const label=(leg.operator_label || leg.mode) + ' · ' + fmt(leg.price) + ' EUR' + staleLabel(leg);
    if (isArea) {
      // A direct-area service is the explicit priced link to its own origin
      // island, not a link to every unrelated patch that happens to be inside
      // the same sea. A same-priced station union is ONE island silhouette.
      const group = groupsByStation.get(Number(from.id_station)) || [from];
      const island = group.length > 1
        ? {...stationPatch(from),
          paths: geometry.unionContours(group.map(stationPatch)).map(ring => [ring])}
        : stationPatch(from);
      const linkedOwners = group.map(station => 'station:' + station.id_station).concat(b);
      if (!drawIslandPerimeter(areaPatch(leg), island, label, leg._stale, linkedOwners)) {
        // A station at the sea edge is an ordinary short transfer,
        // not an artificial full ring around an island outside the sea.
        drawStitch(stationPatch(from), areaPatch(leg), label,
          leg._stale, a, b, 0);
      }
      return;
    }
    renderConnection(mode, stationPatch(from), stationPatch(to),
      label, leg._stale, a, b, Number(leg.bidirectional) === 1,
      mode==='station-bound' ? {...leg,fromStation:from,toStation:to} : null);
  });
  data.transfers.forEach(transfer => {
    const from = stations.get(Number(transfer.from_station_id));
    const to = stations.get(Number(transfer.to_station_id));
    if (!from || !to || !Number.isFinite(Number(transfer.price)) ||
        Number(transfer.price) < 0) return;
    const mode = geometry.kind(transfer.connection_type, false);
    const a = 'station:' + from.id_station, b = 'station:' + to.id_station;
    if (mode === 'air' || mode === 'sea') linkGroup(a, b);
    renderConnection(mode, stationPatch(from), stationPatch(to),
      transfer.connection_type + ' transfer · ' + fmt(transfer.price) + ' EUR' + staleLabel(transfer),
      transfer._stale, a, b, false);
  });
  map.on('click', event => {
    if (previewSelecting) { addPreviewPin(event.latlng); return; }
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
