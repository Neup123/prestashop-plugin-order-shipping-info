/* The coverage map is a view of the persisted API/repository network, not a second dataset. */
(function () {
  'use strict';
  // Clear-back-to-inherit remains usable even if the map library fails.
  document.querySelectorAll?.('.grtrans-clear-far').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.target);
      if (input) input.value = '';
    });
  });
  const root = document.getElementById('grtrans-network-map');
  if (!root) return;
  const status = document.getElementById('grtrans-map-status');
  if (!window.L) {
    status.textContent = 'The local Leaflet map library is unavailable. Network tables and editor remain usable.';
    return;
  }
  const data = JSON.parse(root.dataset.config);
  // Resolve nullable per-place overrides only from the provided configuration.
  // A valid normal radius itself defines reach; never substitute an arbitrary FAR.
  const effectiveOuter = (override, inner) => {
    const normal=Number(inner);
    const configured=override == null || override === ''
      ? Number(data.farDefaultKm) : Number(override);
    const reach=[normal,configured].filter(v=>Number.isFinite(v)&&v>0);
    return reach.length ? Math.max(...reach) : null;
  };
  data.stations = data.stations.map(s => ({...s,
    outer_radius_km: effectiveOuter(s.outer_radius_km, s.inner_radius_km)}));
  data.legs = data.legs.map(leg => leg.to_station_id == null ? {...leg,
    area_outer_radius_km: effectiveOuter(leg.area_outer_radius_km, leg.area_radius_km)} : leg);
  const resolution = data.resolution || null;
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
  // Merchant-provided labels remain text, never tooltip HTML.
  // The tooltip inherits the actual painted colour of its source element.
  const bindColoredTooltip = (layer, title, color) => {
    layer.bindTooltip(safeTooltip(title), {
      className: 'grtrans-colored-tooltip'
    });
    layer.on('tooltipopen', event => {
      const element = event.tooltip.getElement();
      if (element) {
        element.style.setProperty('--grtrans-tooltip-color', color || '#586b7a');
      }
    });
    return layer;
  };
  const toggleHoverClass = (layer, className, enabled, color) => {
    const element = typeof layer.getElement === 'function'
      ? layer.getElement() : null;
    if (!element) return;
    element.classList.toggle(className, enabled);
    if (enabled) {
      element.style.setProperty('--grtrans-hover-color', color);
    } else {
      element.style.removeProperty('--grtrans-hover-color');
    }
  };
  const makePreviewMarker = (latlng, options) => {
    const marker = L.marker(latlng, options);
    bindColoredTooltip(marker, options.alt, '#2259b5');
    marker.on('mouseover', () => {
      toggleHoverClass(marker, 'grtrans-hovered-marker', true, '#2259b5');
    });
    marker.on('mouseout', () => {
      toggleHoverClass(marker, 'grtrans-hovered-marker', false);
    });
    return marker;
  };
  const staleLabel = record => record._stale ? ' · STALE (last verified ' + (record.verified_at || 'unknown') + ')' : '';
  const point = s => [Number(s.lat), Number(s.lng)];
  const costs = data.cost || {};
  // Consume the server's resolution output; retain #106's standalone fallback.
  const resolutions = (resolution && resolution.links) || data.resolutionByLink ||
    geometry.defaultResolutions(data.legs,data.transfers,costs);
  // A palette with a different hue for every actual source-defined zone.
  // Records without valid effective reach do not acquire a phantom legend patch.
  const zones = data.stations.filter(s => geometry.isValid({
    lat:s.lat,lng:s.lng,radiusKm:s.outer_radius_km,coverage_geojson:s.coverage_geojson
  })).map(s => ({
    key: 'station:' + s.id_station, name: s.name
  })).concat(data.legs.filter(leg => leg.to_station_id == null &&
    stations.has(Number(leg.from_station_id)) && geometry.isValid({
      lat:leg.area_lat,lng:leg.area_lng,radiusKm:leg.area_outer_radius_km,
      area_coverage_geojson:leg.area_coverage_geojson
    })).map(leg => ({
    key: 'area:' + leg.id_leg, name: leg.area_name || 'Direct area #' + leg.id_leg
  }))).sort((a, b) => a.key.localeCompare(b.key));
  const palette = zones.map((zone, index) =>
    'hsl(' + ((17 + 360 * index / zones.length) % 360).toFixed(5) + ' 67% 42%)');
  const zoneColors = new Map(zones.map((zone, index) => [zone.key, palette[index]]));
  // The priced fixed-route link groups equal-cost overlapping stations by
  // colour and hover, NOT by a synthetic union outline.
  const stationGroups = geometry.stationGroups(data.stations,
    data.legs.filter(leg => !['link_override','patch_precedence'].includes(
      resolutions['leg:' + leg.id_leg]?.reason)), costs);
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
  // Source-defined radius circles and stored polygons form the base map. Air is
  // the ONE owner-confirmed symbolic exception; distant land/sea routes stay blank.
  const patchStyles = new Map();
  const mentalLinks = new Map();
  const linkGroup = (a, b) => {
    for (const [first, second] of [[a, b], [b, a]]) {
      if (!mentalLinks.has(first)) mentalLinks.set(first, new Set());
      mentalLinks.get(first).add(second);
    }
  };
  let pinnedOwners=[];
  const restoreFocus = () => {
    patchStyles.forEach((entry, layer) => layer.setStyle(entry.style));
    if(pinnedOwners.length)focusExactly(pinnedOwners);
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
    layer.on('mouseover', () => {
      focusOwners(owners);
      toggleHoverClass(layer, 'grtrans-hovered-patch', true,
        style.fillColor || style.color);
    });
    layer.on('mouseout', () => {
      toggleHoverClass(layer, 'grtrans-hovered-patch', false);
      restoreFocus();
    });
    return layer;
  };
  // For invisible hit-targets, highlight only their visible partner.
  // Explicit rest styles also support the browser-test Leaflet stub, which
  // does not expose the real Leaflet layer.options property.
  const registerLineHover = (target, visible, owners, restingStyle) => {
    const resting = {
      weight: restingStyle.weight,
      opacity: restingStyle.opacity
    };
    target.on('mouseover', () => {
      focusExactly(owners);
      visible.setStyle({
        weight: Number(resting.weight) + 0.85,
        opacity: Math.min(0.92, Number(resting.opacity) + 0.12)
      });
    });
    target.on('mouseout', () => {
      visible.setStyle(resting);
      restoreFocus();
    });
    return target;
  };
  const stationPatch = station => ({
    lat: station.lat, lng: station.lng,
    radiusKm: station.outer_radius_km, key: 'station:' + station.id_station,
    coverage_kind: station.coverage_kind, coverage_geojson: station.coverage_geojson
  });
  const areaPatch = (leg) => ({
    lat: leg.area_lat, lng: leg.area_lng,
    radiusKm: leg.area_outer_radius_km, key: 'area:' + leg.id_leg,
    area_coverage_kind: leg.area_coverage_kind,
    area_coverage_geojson: leg.area_coverage_geojson
  });
  const reachLayer = (tier,style) => tier.circle
    ? L.circle(tier.circle.centre,{...style,radius:tier.circle.radiusMeters})
    : L.polygon(geometry.leafletPaths(tier.paths),style);
  const pairLanes = new Map();
  const drawStitch = (from, to, label, stale, fromOwner, toOwner, lane, decision) => {
    // Adapt #107's resolved winner/radius to #106's unchanged stitch geometry.
    const winnerKey = decision?.winner_key || decision?.boundaryOwner;
    const radius = Number(decision?.reach_boundary_km);
    const fromShape = winnerKey === fromOwner && Number.isFinite(radius) && radius > 0
      ? {...from, radiusKm: radius} : from;
    const toShape = winnerKey === toOwner && Number.isFinite(radius) && radius > 0
      ? {...to, radiusKm: radius} : to;
    const geometryDecision = winnerKey ? {...decision, boundaryOwner: winnerKey} : decision;
    const pieces = geometry.stitches(fromShape, toShape, lane, geometryDecision);
    for (const endpoints of pieces) {
      const visibleStyle = {
        color: '#637384', weight: 3,
        opacity: stale ? 0.35 : 0.9, dashArray: '3 2', interactive: false,
        className: 'grtrans-zigzag-stitch'
      };
      const visible = paintSeam(L.polyline(endpoints, visibleStyle));
      const target = paintSeam(L.polyline(endpoints, {
        color: '#637384', weight: 10, opacity: 0.001, interactive: true
      }));
      // An individual hover target selects only this priced pair. Hovering a
      // multi-vessel origin patch shows its separate, legitimately linked ends.
      bindColoredTooltip(target, label, '#637384');
      registerLineHover(target, visible, [fromOwner, toOwner], visibleStyle);
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
      const visibleStyle = {
        color: '#ad5165', weight: 1.25, opacity: stale ? 0.22 : 0.55,
        dashArray: '2 5', interactive: false,
        className: 'grtrans-linked-island-perimeter'
      };
      const visible = paintSeam(L.polyline(ring, visibleStyle));
      const target = paintSeam(L.polyline(ring, {
        color: '#ad5165', weight: 10, opacity: 0.001, interactive: true,
        className: 'grtrans-linked-island-perimeter-hover'
      }));
      bindColoredTooltip(target, label + ' · entire linked island boundary', '#ad5165');
      registerLineHover(target, visible, owners, visibleStyle);
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
    const visibleStyle = {
      color: '#7453a7', weight: 1.8, opacity: stale ? 0.35 : 0.78,
      dashArray: '7 7', interactive: true, className: 'grtrans-air-arc'
    };
    const visible = paintSeam(L.polyline(arc.points, visibleStyle));
    bindColoredTooltip(visible, label, '#7453a7');
    registerLineHover(visible, visible, [fromOwner, toOwner], visibleStyle);
    paintArrow(arc.arrow);
    if (arc.reverseArrow) paintArrow(arc.reverseArrow);
  };
  // A sea vessel is an individual priced pair, not a fan-out unit.
  const drawSeaLink = (from, to, label, stale, fromOwner, toOwner) => {
    const sea=geometry.seaLine(from,to);
    if (!sea) return;
    const shade = '#168ca2';
    const visibleStyle = {
      color: shade, dashArray: '8 7', opacity: stale ? 0.32 : 0.8,
      weight: 2.2, interactive: true, className: 'grtrans-sea-line'
    };
    const visible = paintSeam(L.polyline(sea.points, visibleStyle));
    bindColoredTooltip(visible, label, shade);
    registerLineHover(visible, visible, [fromOwner, toOwner], visibleStyle);
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
  const renderConnection = (mode, from, to, label, stale, fromOwner, toOwner, bidirectional, leg = null, resolution = null) => {
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
      drawStitch(from, to, label, stale, fromOwner, toOwner, lane, resolution);
    }
    if (mode === 'station-bound' && leg) {
      if (geometry.overlaps(from,to)) drawStitch(from,to,label,stale,fromOwner,toOwner,lane,resolution);
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
    // Stage-1 tools own spatial gestures when selected. The legacy read-only
    // preview and non-enhanced behavior remain available without the bridge.
    if (window.GrtransMapWorkspaceHooks?.stationPick?.(station) === true) return;
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
      linkForm.hidden = false;
      const override = document.getElementById('grtrans-link-override');
      if (override) override.value = 'none';
      status.textContent = nearby
        ? 'Review the free walk link and its optional Advanced override before saving.'
        : 'Stations are far apart. Define the transfer and optional Advanced override.';
      document.getElementById('grtrans-link-type').focus();
      return;
    }
    chosen = station;
    linking = false;
    selectedButton.disabled = false;
    status.textContent = 'Selected ' + station.name + '. Choose Link to select a second station.';
    // A small contract/VM harness may not provide browser event constructors.
    // Use the owning window realm, and emit only when events are supported.
    if (typeof window.dispatchEvent === 'function') {
      const detail = {id:Number(station.id_station)};
      let event = null;
      if (typeof window.CustomEvent === 'function') {
        event = new window.CustomEvent('grtrans:map-station-selected', {detail});
      } else if (typeof document.createEvent === 'function') {
        event = document.createEvent('CustomEvent');
        event.initCustomEvent('grtrans:map-station-selected', false, false, detail);
      }
      if (event) window.dispatchEvent(event);
    }
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
    const marker = makePreviewMarker(latlng, {
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
  // Direct-area paint is behind station paint. Each layer is its actual source
  // circle or stored polygon, never an ornamental sea boundary.
  data.legs.forEach(leg => {
    const from = stations.get(Number(leg.from_station_id));
    if (!from || leg.to_station_id != null ||
        !geometry.isValid(areaPatch(leg))) return;
    const area = {lat: leg.area_lat, lng: leg.area_lng};
    const c = zoneColors.get('area:' + leg.id_leg);
    // Paint both area tiers before station fills, retaining source geometry.
    const owner = 'area:' + leg.id_leg;
    geometry.coverageTiers(areaPatch(leg), leg.area_radius_km).forEach(tier => {
      if (!tier.paths.length && !tier.circle) return;
      const far = tier.kind === 'far';
      const style = {pane:'grtrans-coverage-patches', fill:true,
        className:'grtrans-source-patch grtrans-tier-' + tier.kind,
        fillColor:c, color:c, fillOpacity:leg._stale ? (far ? 0.1 : 0.18) : (far ? 0.16 : 0.36),
        stroke:false, opacity:0, weight:0};
      const layer=bindColoredTooltip(reachLayer(tier,style).addTo(map),
        (leg.area_name || 'Direct area #' + leg.id_leg)
          + ' · ' + (far ? 'Far tier' : 'Normal tier'), c);
      registerPatch([owner],layer,style);
    });
    if (geometry.hasPolygon(areaPatch(leg))) includeShape(geometry.patchPaths(areaPatch(leg)));
    else includeCoverage(point(area), leg.area_outer_radius_km);
  });

  // Use #107's server paint order; #106 fixture fallback remains unchanged.
  const paintRank = new Map((resolution?.paint_order || []).map((key, index) => [key, index]));
  const painted = data.stations.slice().sort((a, b) => {
    if (resolution) {
      const rank = station => paintRank.get('station:' + station.id_station) ?? -1;
      return rank(a) - rank(b);
    }
    const aCost = Object.prototype.hasOwnProperty.call(costs, a.id_station) ? Number(costs[a.id_station]) : Infinity;
    const bCost = Object.prototype.hasOwnProperty.call(costs, b.id_station) ? Number(costs[b.id_station]) : Infinity;
    return bCost - aCost;
  });
  painted.forEach(s => {
    const patch=stationPatch(s);
    if (!geometry.isValid(patch)) return;
    if (geometry.hasPolygon(patch)) includeShape(geometry.patchPaths(patch));
    else includeCoverage(point(s), s.outer_radius_km);
  });
  painted.forEach(s => {
    const group = groupsByStation.get(Number(s.id_station)) || [s];
    const c = zoneColors.get('station:' + s.id_station);
    const title = s.name;
    geometry.coverageTiers(stationPatch(s),s.inner_radius_km).forEach(tier=>{
      if(!tier.paths.length && !tier.circle) return;
      const style={
        pane:'grtrans-coverage-patches',fill:true,
        className:'grtrans-source-patch grtrans-tier-'+tier.kind,
        fillColor:c,color:c,fillOpacity:tier.fillOpacity,
        opacity:tier.outline?0.6:0,weight:tier.outline?1:0,
        stroke:tier.outline
      };
      const layer=bindColoredTooltip(reachLayer(tier,style).addTo(map),
        title+' · '+(tier.kind==='normal' ? 'Normal tier' : 'Far tier'), c);
      registerPatch(group.map(station=>'station:'+station.id_station),layer,style);
      layer.on('click',event=>{
        L.DomEvent.stopPropagation(event);
        select(s);
      });
    });
  });
  // Pins and their source boundaries remain independent within a shared colour group.
  // Keep references for reversible draft dragging; the persisted station
  // coordinates and coverage paint are untouched until the existing Save.
  const stationMarkers = new Map();
  data.stations.forEach(s => {
    const title = s.name + (Number(s.is_origin_hub) === 1 ? ' · Origin hub' : '');
    const color = zoneColors.get('station:' + s.id_station) || '#3874ae';
    // Omit native title: it duplicates the custom Leaflet tooltip.
    const marker = L.marker(point(s), {alt: s.name,
      ...(Number(s.is_origin_hub) === 1 ? {
        icon: new L.Icon.Default({className: 'grtrans-origin-marker'})
      } : {})})
      .addTo(map);
    bindColoredTooltip(marker, title, color);
    marker.on('mouseover', () => {
      toggleHoverClass(marker, 'grtrans-hovered-marker', true, color);
    });
    marker.on('mouseout', () => {
      toggleHoverClass(marker, 'grtrans-hovered-marker', false);
    });
    marker.on('click', event => {
        L.DomEvent.stopPropagation(event);
        select(s);
      });
    stationMarkers.set(Number(s.id_station),marker);
    const element=typeof marker.getElement==='function' ? marker.getElement() : null;
    if (element) {
      element.setAttribute('role','button');
      element.setAttribute('aria-label','Select station '+s.name);
      element.setAttribute('tabindex','0');
      element.addEventListener('keydown',event=>{
        if (event.key==='Enter'||event.key===' ') {event.preventDefault();select(s);}
      });
    }
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
          paths: geometry.unionCoveragePaths(group.map(stationPatch))}
        : stationPatch(from);
      const linkedOwners = group.map(station => 'station:' + station.id_station).concat(b);
      if (!drawIslandPerimeter(areaPatch(leg), island, label, leg._stale, linkedOwners)) {
        // A station at the sea edge is an ordinary short transfer,
        // not an artificial full ring around an island outside the sea.
        drawStitch(stationPatch(from), areaPatch(leg), label,
          leg._stale, a, b, 0,
          resolutions['leg:' + leg.id_leg]?.reason === 'cheapest' ? null :
            (resolutions['leg:' + leg.id_leg] || null));
      }
      return;
    }
    renderConnection(mode, stationPatch(from), stationPatch(to),
      label, leg._stale, a, b, Number(leg.bidirectional) === 1,
      mode==='station-bound' ? {...leg,fromStation:from,toStation:to} : null,
      resolutions['leg:' + leg.id_leg] || null);
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
      transfer._stale, a, b, false, null,
      resolutions['transfer:' + transfer.id_transfer] || null);
  });
  map.on('click', event => {
    if (window.GrtransMapWorkspaceHooks?.mapClick?.(event.latlng) === true) return;
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
  // An intentionally small adapter: interaction-only calls, no new graph,
  // pricing, coverage or persistence implementation.
  window.GrtransMapBridge = {
    map, data,
    station:id=>stations.get(Number(id)),
    marker:id=>stationMarkers.get(Number(id)),
    focusStation:id=>{pinnedOwners=['station:'+Number(id)];focusExactly(pinnedOwners);},
    focusStations:ids=>{pinnedOwners=ids.map(id=>'station:'+Number(id));focusExactly(pinnedOwners);},
    clearFocus:()=>{pinnedOwners=[];restoreFocus();},
    clearLegacy:()=>{linking=false;linkForm.hidden=true;clearPreview();},
    startPreview:()=>previewButton?.click(),
    clearPreview:()=>clearButton?.click()
  };
}());
