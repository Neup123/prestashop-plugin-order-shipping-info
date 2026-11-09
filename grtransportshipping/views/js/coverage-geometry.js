/* Geometry for the admin coverage map. Local kilometre projection; no external map service.
 * Circle-union boundaries contain ONLY exposed circular arcs, never internal rims.
 * This is a display model: it does not extend the quote engine's delivery geography. */
(function (global) {
  'use strict';
  const KM = 111.195;
  const TAU = 2 * Math.PI;
  const EPS = 1e-7;
  const radians = degrees => degrees * Math.PI / 180;
  const kind = (value, directArea) => {
    if (directArea) return 'dynamic';
    const mode = String(value || '').trim().toLowerCase();
    if (['flight', 'air', 'airplane', 'plane'].includes(mode)) return 'air';
    if (['ferry', 'ship', 'boat', 'sea'].includes(mode)) return 'sea';
    if (['bus', 'coach', 'train', 'rail', 'tram', 'metro'].includes(mode)) return 'station-bound';
    if (['taxi', 'courier', 'delivery', 'van', 'car'].includes(mode)) return 'dynamic';
    return 'unknown';
  };
  const projector = coordinates => {
    const lat = coordinates.reduce((sum, c) => sum + Number(c[0]), 0) / coordinates.length;
    const sx = KM * Math.max(0.01, Math.cos(radians(lat)));
    return {
      xy: point => ({x: Number(point[1]) * sx, y: Number(point[0]) * KM}),
      ll: point => [point.y / KM, point.x / sx]
    };
  };
  const circleDistance = (a, b) => {
    const projection = projector([[a.lat, a.lng], [b.lat, b.lng]]);
    const p = projection.xy([a.lat, a.lng]), q = projection.xy([b.lat, b.lng]);
    return Math.hypot(p.x - q.x, p.y - q.y);
  };
  const intersects = (a, b) =>
    circleDistance(a, b) < Number(a.radiusKm) + Number(b.radiusKm) - EPS;

  // A shared transport kind is not itself proof of one service. Merge only
  // overlapping stations explicitly joined by a priced station-bound leg.
  // An absent service identity in the stored schema must not merge unrelated lines.
  const stationGroups = (stations, legs) => {
    const lookup = new Map(stations.map(s => [Number(s.id_station), s]));
    const parent = new Map([...lookup.keys()].map(id => [id, id]));
    const find = id => {
      let root = id;
      while (parent.get(root) !== root) root = parent.get(root);
      let cursor = id;
      while (parent.get(cursor) !== root) {
        const next = parent.get(cursor); parent.set(cursor, root); cursor = next;
      }
      return root;
    };
    for (const leg of legs) {
      if (kind(leg.mode, false) !== 'station-bound' || !Number.isFinite(Number(leg.price)) ||
          Number(leg.price) < 0) continue;
      const a = lookup.get(Number(leg.from_station_id));
      const b = lookup.get(Number(leg.to_station_id));
      if (!a || !b || a.type !== b.type || !intersects({
        lat: a.lat, lng: a.lng, radiusKm: a.outer_radius_km
      }, {lat: b.lat, lng: b.lng, radiusKm: b.outer_radius_km})) continue;
      const ra = find(Number(a.id_station)), rb = find(Number(b.id_station));
      if (ra !== rb) parent.set(rb, ra);
    }
    const groups = new Map();
    for (const station of stations) {
      const id = Number(station.id_station);
      const root = find(id);
      if (!groups.has(root)) groups.set(root, []);
      groups.get(root).push(station);
    }
    return [...groups.values()];
  };
  const normalizedAngle = angle => ((angle % TAU) + TAU) % TAU;

  // Exact union topology of circles, with exposed arcs sampled for Leaflet's
  // polygon renderer. Each connected component is ONE polygon ring; arcs
  // buried inside any other circle are excluded before the path is assembled.
  const unionContours = input => {
    const usable = input.filter(c => Number(c.radiusKm) > 0 &&
      Number.isFinite(Number(c.lat)) && Number.isFinite(Number(c.lng)));
    if (!usable.length) return [];
    const projection = projector(usable.map(c => [c.lat, c.lng]));
    const circles = [];
    for (const record of usable) {
      const p = projection.xy([record.lat, record.lng]);
      const r = Number(record.radiusKm);
      if (!circles.some(c => Math.hypot(c.x - p.x, c.y - p.y) < EPS &&
          Math.abs(c.r - r) < EPS)) circles.push({...p, r});
    }
    const visibleArcs = [];
    for (let i = 0; i < circles.length; i++) {
      const c = circles[i];
      const cuts = [0, TAU];
      for (let j = 0; j < circles.length; j++) {
        if (i === j) continue;
        const other = circles[j];
        const dx = other.x - c.x, dy = other.y - c.y;
        const d = Math.hypot(dx, dy);
        if (d <= Math.abs(c.r - other.r) + EPS || d >= c.r + other.r - EPS) continue;
        const base = Math.atan2(dy, dx);
        const delta = Math.acos(Math.max(-1, Math.min(1,
          (c.r * c.r + d * d - other.r * other.r) / (2 * c.r * d))));
        cuts.push(normalizedAngle(base - delta), normalizedAngle(base + delta));
      }
      cuts.sort((a, b) => a - b);
      for (let n = 1; n < cuts.length; n++) {
        const start = cuts[n - 1], end = cuts[n];
        if (end - start < 1e-10) continue;
        const middle = (start + end) / 2;
        const x = c.x + c.r * Math.cos(middle), y = c.y + c.r * Math.sin(middle);
        if (circles.some((other, j) => j !== i &&
          Math.hypot(x - other.x, y - other.y) < other.r - EPS)) continue;
        const count = Math.max(1, Math.ceil((end - start) / (Math.PI / 90)));
        const points = Array.from({length: count + 1}, (_, index) => {
          const angle = start + (end - start) * index / count;
          return {x: c.x + c.r * Math.cos(angle), y: c.y + c.r * Math.sin(angle)};
        });
        visibleArcs.push(points);
      }
    }
    const key = p => Math.round(p.x * 1e5) + ':' + Math.round(p.y * 1e5);
    const starts = new Map();
    visibleArcs.forEach((arc, index) => {
      const id = key(arc[0]);
      if (!starts.has(id)) starts.set(id, []);
      starts.get(id).push(index);
    });
    const unused = new Set(visibleArcs.map((_, index) => index));
    const rings = [];
    while (unused.size) {
      const startIndex = unused.values().next().value;
      const firstKey = key(visibleArcs[startIndex][0]);
      const coords = [];
      let cursor = startIndex, closed = false;
      while (unused.has(cursor)) {
        unused.delete(cursor);
        const arc = visibleArcs[cursor];
        coords.push(...arc.slice(coords.length ? 1 : 0));
        const endKey = key(arc[arc.length - 1]);
        if (endKey === firstKey) { closed = true; break; }
        const next = (starts.get(endKey) || []).find(index => unused.has(index));
        if (next === undefined) break;
        cursor = next;
      }
      if (closed && coords.length > 3) {
        if (key(coords[0]) === key(coords[coords.length - 1])) coords.pop();
        rings.push(coords.map(projection.ll));
      }
    }
    return rings;
  };

  // Stitches never cross an uncovered gap. For overlapping patches the mark
  // sits at their lens joining point; for disjoint patches each facing rim
  // receives its own short mark, never a connecting ribbon or line.
  const stitches = (from, to, fromRadius, toRadius, lane = 0) => {
    const projection = projector([from, to]);
    const a = projection.xy(from), b = projection.xy(to);
    const dx = b.x - a.x, dy = b.y - a.y;
    const d = Math.hypot(dx, dy);
    const ux = d > EPS ? dx / d : 1, uy = d > EPS ? dy / d : 0;
    const tx = -uy, ty = ux;
    const ra = Math.max(0, Number(fromRadius) || 0);
    const rb = Math.max(0, Number(toRadius) || 0);
    if (!ra || !rb) return [];
    const stroke = (centre, radius) => {
      const half = Math.min(0.85, radius * 0.23);
      const shift = Math.max(-0.3, Math.min(0.3, lane * 0.18));
      return [
        projection.ll({x: centre.x + tx * (shift - half), y: centre.y + ty * (shift - half)}),
        projection.ll({x: centre.x + tx * (shift + half), y: centre.y + ty * (shift + half)})
      ];
    };
    if (d < ra + rb - EPS && d > Math.abs(ra - rb) + EPS) {
      const along = (ra * ra - rb * rb + d * d) / (2 * d);
      return [stroke({x: a.x + ux * along, y: a.y + uy * along}, Math.min(ra, rb))];
    }
    if (d <= Math.abs(ra - rb) + EPS) {
      const smaller = ra <= rb ? a : b;
      return [stroke(smaller, Math.min(ra, rb))];
    }
    const at = (origin, direction, radius) => ({
      x: origin.x + direction * ux * radius * 0.75,
      y: origin.y + direction * uy * radius * 0.75
    });
    return [stroke(at(a, 1, ra), ra), stroke(at(b, -1, rb), rb)];
  };
  global.GrtransCoverage = Object.freeze({kind, stationGroups, unionContours, stitches});
}(window));
