/* Pure, bundled geometry for the admin visual map.
 * The stored radius is authoritative for pricing; soft blobs are visual hints
 * clipped INSIDE that radius. Union contours include only exposed blob edges. */
(function (global) {
  'use strict';
  const KM = 111.195;
  const TAU = 2 * Math.PI;
  const SAMPLES = 144;
  const EPS = 1e-8;
  const radians = angle => angle * Math.PI / 180;
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
    const scale = KM * Math.max(0.01, Math.cos(radians(lat)));
    return {
      xy: p => ({x: Number(p[1]) * scale, y: Number(p[0]) * KM}),
      ll: p => [p.y / KM, p.x / scale]
    };
  };
  const length = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  const cross = (a, b) => a.x * b.y - a.y * b.x;
  const difference = (a, b) => ({x: a.x - b.x, y: a.y - b.y});
  const move = (a, b, factor) => ({x: a.x + b.x * factor, y: a.y + b.y * factor});
  const seed = record => {
    const token = String(record.key ?? (record.lat + ':' + record.lng));
    let hash = 2166136261;
    for (let i = 0; i < token.length; i++) {
      hash = Math.imul(hash ^ token.charCodeAt(i), 16777619) >>> 0;
    }
    return hash / 4294967296 * TAU;
  };
  const isValid = record => Number(record.radiusKm) > 0 &&
    Number.isFinite(Number(record.lat)) && Number.isFinite(Number(record.lng));
  // Three low-frequency harmonics provide gentle paint-like scallops. Their
  // combined amplitude stays below the radius: we never assert new coverage.
  const blobRadius = (radius, angle, phase) => Number(radius) * (
    0.964 + 0.018 * Math.sin(3 * angle + phase) +
    0.011 * Math.sin(5 * angle + 2.3 * phase) +
    0.006 * Math.sin(8 * angle - 1.7 * phase));
  const shapeXY = (record, projection) => {
    const centre = projection.xy([record.lat, record.lng]);
    const phase = seed(record);
    return Array.from({length: SAMPLES}, (_, i) => {
      const angle = TAU * i / SAMPLES;
      const radius = blobRadius(record.radiusKm, angle, phase);
      return {x: centre.x + Math.cos(angle) * radius,
        y: centre.y + Math.sin(angle) * radius};
    });
  };
  const patchContour = record => {
    if (!isValid(record)) return [];
    const projection = projector([[record.lat, record.lng]]);
    return shapeXY(record, projection).map(projection.ll);
  };
  const inside = (p, poly) => {
    let contained = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const a = poly[i], b = poly[j];
      if ((a.y > p.y) !== (b.y > p.y) &&
          p.x < (b.x - a.x) * (p.y - a.y) / (b.y - a.y) + a.x) contained = !contained;
    }
    return contained;
  };
  const intersection = (p, q, a, b) => {
    const r = difference(q, p), s = difference(b, a);
    const denom = cross(r, s);
    if (Math.abs(denom) < EPS) return null;
    const offset = difference(a, p);
    const t = cross(offset, s) / denom;
    const u = cross(offset, r) / denom;
    return t >= -EPS && t <= 1 + EPS && u >= -EPS && u <= 1 + EPS ? t : null;
  };
  const nearby = (a, b, projection) => {
    const p = projection.xy([a.lat, a.lng]), q = projection.xy([b.lat, b.lng]);
    return length(p, q) < Number(a.radiusKm) + Number(b.radiusKm);
  };
  const overlaps = (a, b) => {
    if (!isValid(a) || !isValid(b)) return false;
    const projection = projector([[a.lat, a.lng], [b.lat, b.lng]]);
    if (!nearby(a, b, projection)) return false;
    const first = shapeXY(a, projection), second = shapeXY(b, projection);
    if (inside(first[0], second) || inside(second[0], first)) return true;
    for (let i = 0; i < first.length; i++) {
      const p = first[i], q = first[(i + 1) % first.length];
      for (let j = 0; j < second.length; j++) {
        if (intersection(p, q, second[j], second[(j + 1) % second.length]) !== null) return true;
      }
    }
    return false;
  };
  // Union of sampled organic polygons: split each boundary at all intersections,
  // drop every piece contained by any other patch, then link exposed pieces.
  // This is NOT overpainting full station discs: no buried boundary survives.
  const unionContours = input => {
    const records = input.filter(isValid);
    if (!records.length) return [];
    if (records.length === 1) return [patchContour(records[0])];
    const projection = projector(records.map(record => [record.lat, record.lng]));
    const shapes = records.map(record => shapeXY(record, projection));
    const exposed = [];
    for (let i = 0; i < shapes.length; i++) {
      const poly = shapes[i];
      const neighbours = records.map((other, j) => j).filter(j => j !== i &&
        nearby(records[i], records[j], projection));
      for (let n = 0; n < poly.length; n++) {
        const a = poly[n], b = poly[(n + 1) % poly.length];
        const cuts = [0, 1];
        for (const j of neighbours) {
          const other = shapes[j];
          for (let k = 0; k < other.length; k++) {
            const t = intersection(a, b, other[k], other[(k + 1) % other.length]);
            if (t !== null && t > EPS && t < 1 - EPS) cuts.push(t);
          }
        }
        cuts.sort((x, y) => x - y);
        for (let k = 1; k < cuts.length; k++) {
          if (cuts[k] - cuts[k - 1] < EPS) continue;
          const from = move(a, difference(b, a), cuts[k - 1]);
          const to = move(a, difference(b, a), cuts[k]);
          const midpoint = {x: (from.x + to.x) / 2, y: (from.y + to.y) / 2};
          if (neighbours.some(j => inside(midpoint, shapes[j]))) continue;
          exposed.push({from, to});
        }
      }
    }
    const id = p => Math.round(p.x * 1e5) + ':' + Math.round(p.y * 1e5);
    const starts = new Map();
    exposed.forEach((edge, index) => {
      const key = id(edge.from);
      if (!starts.has(key)) starts.set(key, []);
      starts.get(key).push(index);
    });
    const unused = new Set(exposed.map((_, index) => index));
    const rings = [];
    while (unused.size) {
      const initial = unused.values().next().value;
      const firstKey = id(exposed[initial].from);
      const points = [exposed[initial].from];
      let cursor = initial, closed = false;
      while (unused.has(cursor)) {
        unused.delete(cursor);
        const edge = exposed[cursor];
        if (id(edge.to) === firstKey) {closed = true; break;}
        points.push(edge.to);
        const next = (starts.get(id(edge.to)) || []).find(index => unused.has(index));
        if (next === undefined) break;
        cursor = next;
      }
      if (closed && points.length >= 3) rings.push(points.map(projection.ll));
    }
    return rings;
  };
  // Only an explicit priced station-bound link can establish same-service
  // membership. Shared mode labels alone NEVER merge separate operators.
  const stationGroups = (stations, legs) => {
    const lookup = new Map(stations.map(s => [Number(s.id_station), s]));
    const parents = new Map([...lookup.keys()].map(id => [id, id]));
    const root = id => {
      let cursor = id;
      while (parents.get(cursor) !== cursor) cursor = parents.get(cursor);
      const result = cursor;
      cursor = id;
      while (parents.get(cursor) !== result) {
        const next = parents.get(cursor); parents.set(cursor, result); cursor = next;
      }
      return result;
    };
    for (const leg of legs) {
      if (kind(leg.mode, false) !== 'station-bound' ||
          !Number.isFinite(Number(leg.price)) || Number(leg.price) < 0) continue;
      const from = lookup.get(Number(leg.from_station_id));
      const to = lookup.get(Number(leg.to_station_id));
      if (!from || !to || from.type !== to.type) continue;
      if (!overlaps(
        {lat: from.lat, lng: from.lng, radiusKm: from.outer_radius_km, key: 'station:' + from.id_station},
        {lat: to.lat, lng: to.lng, radiusKm: to.outer_radius_km, key: 'station:' + to.id_station})) continue;
      const a = root(Number(from.id_station)), b = root(Number(to.id_station));
      if (a !== b) parents.set(b, a);
    }
    const groups = new Map();
    for (const station of stations) {
      const id = root(Number(station.id_station));
      if (!groups.has(id)) groups.set(id, []);
      groups.get(id).push(station);
    }
    return [...groups.values()];
  };

  // Short zigzag follows the shared interior boundary perpendicular to the
  // station axis, and every vertex is guaranteed to lie INSIDE both blobs.
  const zigzagSeam = (first, second, lane = 0) => {
    if (!overlaps(first, second)) return null;
    const projection = projector([[first.lat, first.lng], [second.lat, second.lng]]);
    const A = projection.xy([first.lat, first.lng]);
    const B = projection.xy([second.lat, second.lng]);
    const differenceAB = difference(B, A);
    const d = length(A, B);
    const unit = d > EPS ? {x: differenceAB.x / d, y: differenceAB.y / d} : {x: 1, y: 0};
    const tangent = {x: -unit.y, y: unit.x};
    const a = shapeXY(first, projection), b = shapeXY(second, projection);
    const common = [];
    for (let i = 0; i <= 160; i++) {
      const centre = move(A, unit, d * i / 160);
      if (inside(centre, a) && inside(centre, b)) common.push(d * i / 160);
    }
    if (!common.length) return null;
    const interval = (common[0] + common[common.length - 1]) / 2;
    const offset = Math.max(-0.18, Math.min(0.18, lane * 0.08));
    const centre = move(A, unit, interval + offset);
    let half = Math.min(0.52, Number(first.radiusKm) * 0.14,
      Number(second.radiusKm) * 0.14);
    for (let retry = 0; retry < 12; retry++) {
      const amp = Math.min(0.065, half * 0.22);
      const vertices = Array.from({length: 9}, (_, i) => {
        const along = half * (i / 4 - 1);
        const across = (i % 2 ? -amp : amp);
        return move(move(centre, tangent, along), unit, across);
      });
      const pathLength = vertices.slice(1).reduce((sum, point, i) =>
        sum + length(point, vertices[i]), 0);
      if (pathLength <= 1.75 && vertices.every(point =>
        inside(point, a) && inside(point, b))) return vertices.map(projection.ll);
      half *= 0.7;
    }
    return null;
  };
  // Fallback for distant, unresolved land/sea links: only two small, local,
  // facing-patch marks, with a fully empty interstation gap.
  const stitches = (first, second, lane = 0) => {
    const insideLens = zigzagSeam(first, second, lane);
    if (insideLens) return [insideLens];
    if (overlaps(first, second) || !isValid(first) || !isValid(second)) return [];
    const projection = projector([[first.lat, first.lng], [second.lat, second.lng]]);
    const a = projection.xy([first.lat, first.lng]);
    const b = projection.xy([second.lat, second.lng]);
    const delta = difference(b, a);
    const dist = length(a, b);
    const unit = dist > EPS ? {x: delta.x / dist, y: delta.y / dist} : {x: 1, y: 0};
    const tangent = {x: -unit.y, y: unit.x};
    const stroke = (record, centre, direction) => {
      const radius = Number(record.radiusKm);
      const half = Math.min(0.75, radius * 0.19);
      const shift = Math.min(0.2, lane * 0.07);
      const origin = move(centre, unit, direction * radius * 0.70 + shift);
      return [
        projection.ll(move(origin, tangent, -half)),
        projection.ll(move(origin, tangent, half))
      ];
    };
    return [stroke(first, a, 1), stroke(second, b, -1)];
  };
  const airArc = (from, to, bidirectional = false) => {
    const projection = projector([from, to]);
    const a = projection.xy(from), b = projection.xy(to);
    const delta = difference(b, a);
    const dist = length(a, b);
    if (dist < 0.1) return null;
    const unit = {x: delta.x / dist, y: delta.y / dist};
    const perpendicular = {x: -unit.y, y: unit.x};
    const radius = dist / 2;
    const sample = t => move(move(a, unit, dist * t),
      perpendicular, radius * Math.sin(Math.PI * t));
    const curve = Array.from({length: 41}, (_, i) => sample(i / 40));
    const size = Math.min(14, Math.max(2.5, dist * 0.04));
    const tip = (end, tangent) => {
      const tangentLength = Math.hypot(tangent.x, tangent.y);
      const forward = {x: tangent.x / tangentLength, y: tangent.y / tangentLength};
      const side = {x: -forward.y, y: forward.x};
      const rear = move(end, forward, -size);
      return [projection.ll(move(rear, side, size * 0.40)),
        projection.ll(end),
        projection.ll(move(rear, side, -size * 0.40))];
    };
    const towardEnd = difference(curve[40], curve[39]);
    const towardStart = difference(curve[0], curve[1]);
    return {
      points: curve.map(projection.ll),
      arrow: tip(curve[40], towardEnd),
      reverseArrow: bidirectional ? tip(curve[0], towardStart) : null
    };
  };
  global.GrtransCoverage = Object.freeze({
    kind, patchContour, overlaps, stationGroups, unionContours,
    zigzagSeam, stitches, airArc
  });
}(window));
