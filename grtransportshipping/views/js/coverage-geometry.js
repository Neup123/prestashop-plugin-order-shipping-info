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
  const decoded = record => {
    const raw = record.coverage_geojson || record.area_coverage_geojson || '';
    if (!raw) return null;
    try {
      const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
      return data.type === 'Feature' ? data.geometry : data;
    } catch (_) {return null;}
  };
  const geoPaths = record => {
    const feature = decoded(record);
    if (!feature) return null;
    const polygon = ring => ring.map(p => [Number(p[1]), Number(p[0])]);
    if (feature.type === 'Polygon') return [feature.coordinates.map(polygon)];
    if (feature.type === 'MultiPolygon') return feature.coordinates.map(part => part.map(polygon));
    return null;
  };
  const isValid = record => Number.isFinite(Number(record.lat)) &&
    Number.isFinite(Number(record.lng)) &&
    (Number(record.radiusKm) > 0 || geoPaths(record) !== null);
  const patchPaths = record => {
    // Internal precomputed union contours can be rendered as one island too.
    if (Array.isArray(record.paths)) return record.paths;
    const geo = geoPaths(record);
    if (geo) return geo;
    if (!(Number(record.radiusKm) > 0)) return [];
    return [[patchContour(record)]];
  };
  // Tier model: outer reach is the source's complete coverage shape;
  // inner reach is the intersection of that shape with the station's normal
  // walking radius. Polygon/isochrone holes remain holes. No invented reach.
  const clipConvex = (subject, clip) => {
    const ring = subject.slice();
    if (ring.length>1 && Math.abs(ring[0][0]-ring[ring.length-1][0])<EPS &&
        Math.abs(ring[0][1]-ring[ring.length-1][1])<EPS) ring.pop();
    const signed = clip.reduce((sum,p,i)=>{
      const q=clip[(i+1)%clip.length];
      return sum+p[1]*q[0]-q[1]*p[0];
    },0);
    const sign = signed < 0 ? -1 : 1;
    let output=ring;
    for (let i=0;i<clip.length;i++) {
      const p=clip[i],q=clip[(i+1)%clip.length];
      const edge=(v)=>sign*((q[1]-p[1])*(v[0]-p[0])-
        (q[0]-p[0])*(v[1]-p[1]));
      const insideClip=v=>edge(v)>=-1e-10;
      const crossAt=(a,b)=>{
        const fa=edge(a),fb=edge(b);
        const t=Math.abs(fa-fb)<EPS?0:fa/(fa-fb);
        return [a[0]+(b[0]-a[0])*t,a[1]+(b[1]-a[1])*t];
      };
      const input=output;
      output=[];
      if(!input.length)break;
      for(let j=0;j<input.length;j++) {
        const a=input[j],z=input[(j+1)%input.length];
        const ai=insideClip(a),zi=insideClip(z);
        if(ai&&zi)output.push(z);
        else if(ai&&!zi)output.push(crossAt(a,z));
        else if(!ai&&zi)output.push(crossAt(a,z),z);
      }
    }
    return output.length>=3?output:[];
  };
  const coverageTiers = (record, innerRadiusKm) => {
    const outer=patchPaths(record);
    const tiers=[{kind:'far',paths:outer,fillOpacity:0.16,outline:true}];
    const inner=Number(innerRadiusKm)||0;
    if (inner<=0 || !outer.length) return tiers;
    const clip=patchContour({lat:record.lat,lng:record.lng,
      radiusKm:inner,key:record.key}).slice(0,-0||undefined);
    const normal=[];
    for (const part of outer) {
      if(!part.length)continue;
      const ring=clipConvex(part[0],clip);
      if(ring.length<3)continue;
      const holes=part.slice(1).map(hole=>clipConvex(hole,clip))
        .filter(hole=>hole.length>=3);
      normal.push([ring].concat(holes));
    }
    if(normal.length) tiers.push({kind:'normal',paths:normal,
      fillOpacity:0.42,outline:false});
    return tiers;
  };
  const leafletPaths = paths => paths.length === 1
    ? (paths[0].length === 1 ? paths[0][0] : paths[0]) : paths;
  // Radius-based walking reach is a true circle, as in panel A. There is no
  // arbitrary wavy alteration to priced geography. CSS softens its paint edge.
  const blobRadius = radius => Number(radius);
  const shapeXY = (record, projection) => {
    const geometry = geoPaths(record);
    if (geometry) return geometry[0][0].map(projection.xy).slice(0, -1);
    const centre = projection.xy([record.lat, record.lng]);
    return Array.from({length: SAMPLES}, (_, i) => {
      const angle = TAU * i / SAMPLES;
      const radius = blobRadius(record.radiusKm, angle);
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
    if (geoPaths(a) || geoPaths(b)) {
      const polyA = shapeXY(a, projection), polyB = shapeXY(b, projection);
      const ax = polyA.map(p => p.x), ay = polyA.map(p => p.y);
      const bx = polyB.map(p => p.x), by = polyB.map(p => p.y);
      return Math.min(...ax) <= Math.max(...bx) && Math.min(...bx) <= Math.max(...ax)
        && Math.min(...ay) <= Math.max(...by) && Math.min(...by) <= Math.max(...ay);
    }
    const p = projection.xy([a.lat, a.lng]), q = projection.xy([b.lat, b.lng]);
    return length(p, q) < Number(a.radiusKm) + Number(b.radiusKm);
  };
  const overlaps = (a, b) => {
    if (!isValid(a) || !isValid(b)) return false;
    const projection = projector([[a.lat, a.lng], [b.lat, b.lng]]);
    if (geoPaths(a) || geoPaths(b)) {
      // A circle entirely inside a stored polygon HOLE is not overlapping
      // service. Use every Polygon/MultiPolygon ring, not its bounding shell.
      const footprint = record => patchPaths(record).map(polygon =>
        polygon.map(ring => {
          const points=ring.map(projection.xy);
          if(points.length>1 && length(points[0],points[points.length-1])<EPS)
            points.pop();
          return points;
        }).filter(ring=>ring.length>=3)).filter(polygon=>polygon.length);
      const first=footprint(a), second=footprint(b);
      const covers=(point,polygons)=>polygons.some(poly=>
        inside(point,poly[0])&&!poly.slice(1).some(hole=>inside(point,hole)));
      const exposedEdgeInside=(polys,other)=>{
        for(const poly of polys) {
          const ring=poly[0];
          for(let i=0;i<ring.length;i++){
            const p=ring[i],q=ring[(i+1)%ring.length];
            const mid=move(p,difference(q,p),0.5);
            const delta=difference(q,p),dist=length(p,q);
            if(dist<EPS)continue;
            const side={x:-delta.y/dist*0.0001,y:delta.x/dist*0.0001};
            if((covers(move(mid,side,1),polys)&&
                covers(move(mid,side,1),other))||
               (covers(move(mid,side,-1),polys)&&
                covers(move(mid,side,-1),other)))return true;
          }
        }
        return false;
      };
      if(exposedEdgeInside(first,second)||exposedEdgeInside(second,first))
        return true;
      const edges=polys=>polys.flatMap(poly=>poly.flatMap(ring=>
        ring.map((point,i)=>[point,ring[(i+1)%ring.length]])));
      const one=edges(first),two=edges(second);
      for(const [p,q] of one)for(const [s,t] of two){
        const hit=intersection(p,q,s,t);
        if(hit!==null&&hit>EPS&&hit<1-EPS)return true;
      }
      return false;
    }
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
  // True union of stored polygon/multipolygon coverage. The fast contour
  // union above handles circles, but its historical first-ring projection
  // would discard GeoJSON islands and holes. For sourced geometries, retain
  // every ring and keep only boundary segments with coverage on one side.
  // This computes a paint union of EXISTING coverage, never new reach.
  const unionCoveragePaths = input => {
    const records = input.filter(isValid);
    if (!records.length) return [];
    if (!records.some(record => geoPaths(record)))
      return unionContours(records).map(ring => [ring]);
    const projection = projector(records.map(r => [r.lat, r.lng]));
    const parts = records.flatMap(record => patchPaths(record).map(poly =>
      poly.map(ring => {
        const xy = ring.map(projection.xy);
        if (xy.length > 1 && length(xy[0], xy[xy.length - 1]) < EPS) xy.pop();
        return xy;
      }).filter(ring => ring.length >= 3)
    )).filter(poly => poly.length);
    const covered = point => parts.some(poly =>
      inside(point, poly[0]) && !poly.slice(1).some(hole => inside(point, hole)));
    const segments = parts.flatMap(poly => poly.flatMap(ring =>
      ring.map((from, i) => ({from, to:ring[(i + 1) % ring.length]}))
        .filter(edge => length(edge.from, edge.to) > EPS)));
    const exposed = [];
    const seen = new Set();
    const id = p => Math.round(p.x * 1e5) + ':' + Math.round(p.y * 1e5);
    segments.forEach((edge, index) => {
      const delta = difference(edge.to, edge.from);
      const cuts = [0, 1];
      segments.forEach((other, otherIndex) => {
        if (index === otherIndex) return;
        const t = intersection(edge.from, edge.to, other.from, other.to);
        if (t !== null && t > EPS && t < 1 - EPS) cuts.push(t);
      });
      cuts.sort((a, b) => a - b);
      for (let i = 1; i < cuts.length; i++) {
        if (cuts[i] - cuts[i-1] < EPS) continue;
        const start = move(edge.from, delta, cuts[i-1]);
        const end = move(edge.from, delta, cuts[i]);
        const distance = length(start, end);
        if (distance < EPS) continue;
        const midpoint = move(start, difference(end, start), 0.5);
        const normal = {x: -(end.y-start.y)/distance, y: (end.x-start.x)/distance};
        const offset = Math.min(0.001, Math.max(1e-6, distance * 0.02));
        const left = covered(move(midpoint, normal, offset));
        const right = covered(move(midpoint, normal, -offset));
        if (left === right) continue;
        // Orient every exterior or hole boundary with covered area on LEFT.
        const from = left ? start : end, to = left ? end : start;
        const key = id(from) + '>' + id(to);
        if (seen.has(key)) continue;
        seen.add(key);
        exposed.push({from, to});
      }
    });
    const outgoing = new Map();
    exposed.forEach((edge, index) => {
      const key = id(edge.from);
      if (!outgoing.has(key)) outgoing.set(key, []);
      outgoing.get(key).push(index);
    });
    const remaining = new Set(exposed.map((_, i) => i));
    const rings = [];
    while (remaining.size) {
      let cursor = remaining.values().next().value;
      const firstKey = id(exposed[cursor].from);
      const points = [exposed[cursor].from];
      let closed = false;
      while (remaining.has(cursor)) {
        remaining.delete(cursor);
        const edge = exposed[cursor];
        if (id(edge.to) === firstKey) {closed = true; break;}
        points.push(edge.to);
        const next = (outgoing.get(id(edge.to)) || []).find(i => remaining.has(i));
        if (next === undefined) break;
        cursor = next;
      }
      if (closed && points.length >= 3) {
        const signed = points.reduce((sum, p, i) =>
          sum + cross(p, points[(i + 1) % points.length]), 0);
        if (Math.abs(signed) > EPS) rings.push({xy:points, signed});
      }
    }
    const outer = rings.filter(ring => ring.signed > 0)
      .map(ring => ({...ring, holes:[]}));
    for (const hole of rings.filter(ring => ring.signed < 0)) {
      const parent = outer.filter(ring => inside(hole.xy[0], ring.xy))
        .sort((a, b) => a.signed - b.signed)[0];
      if (parent) parent.holes.push(hole.xy);
    }
    return outer.map(part => [part.xy, ...part.holes].map(ring =>
      ring.map(projection.ll)));
  };

  // Only an explicit priced station-bound link can establish same-service
  // membership. Shared mode labels alone NEVER merge separate operators.
  const stationGroups = (stations, legs, costs = {}) => {
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
      // A port can also be a bus/train stop: the LINK kind, not station labels,
      // determines whether equal-cost overlapping reaches share a silhouette.
      if (!from || !to) continue;
      // Same-priced station reaches unite; expensive and cheaper reaches
      // must keep their individual fill silhouettes for panel G.
      const fromCost = Number(costs[from.id_station]), toCost = Number(costs[to.id_station]);
      if (!Object.hasOwn(costs, from.id_station) || !Object.hasOwn(costs, to.id_station) ||
          !Number.isFinite(fromCost) || !Number.isFinite(toCost) ||
          Math.abs(fromCost - toCost) > 0.005) continue;
      if (!overlaps(
        {lat: from.lat, lng: from.lng, radiusKm: from.outer_radius_km,
          coverage_geojson: from.coverage_geojson, key: 'station:' + from.id_station},
        {lat: to.lat, lng: to.lng, radiusKm: to.outer_radius_km,
          coverage_geojson: to.coverage_geojson, key: 'station:' + to.id_station})) continue;
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

  // Panel C: follow the actual resolved winning coverage boundary within
  // the losing reach. Stored MultiPolygon islands and hole boundaries are
  // eligible handovers too; never silently default to the first outer ring.
  const zigzagSeam = (cheap, expensive, lane = 0) => {
    if (!overlaps(cheap, expensive)) return null;
    const projection = projector([[cheap.lat, cheap.lng], [expensive.lat, expensive.lng]]);
    const shape = record => patchPaths(record).map(part => part.map(ring => {
      const xy=ring.map(projection.xy);
      if(xy.length>1&&length(xy[0],xy[xy.length-1])<EPS)xy.pop();
      return xy;
    }).filter(ring=>ring.length>=3)).filter(part=>part.length);
    const cheapParts=shape(cheap),expensiveParts=shape(expensive);
    const contains=(point,parts)=>parts.some(poly=>
      inside(point,poly[0])&&!poly.slice(1).some(hole=>inside(point,hole)));
    const expensiveCentre=projection.xy([expensive.lat,expensive.lng]);
    const candidates=[];
    for(const part of cheapParts)for(const ring of part)for(let i=0;i<ring.length;i++){
      const from=ring[i],to=ring[(i+1)%ring.length];
      const mid=move(from,difference(to,from),0.5);
      if(contains(mid,expensiveParts))
        candidates.push({ring,i,score:length(mid,expensiveCentre)});
    }
    candidates.sort((a,b)=>a.score-b.score);
    // Try neighbouring candidate arcs rather than omitting a valid stitch
    // because a single highly concave edge cannot accommodate nine points.
    for(const candidate of candidates.slice(0,32)){
      const {ring,i:chosen}=candidate;
      for(let scale=1;scale>=0.08;scale*=0.7){
        const half=Math.min(0.65,Number(cheap.radiusKm||2)*0.14)*scale;
        const wiggle=0.035*scale;
        const vertices=[];
        for(let k=0;k<9;k++){
          const signed=(k/4-1)*half;
          let index=chosen;
          let pos=move(ring[index],
            difference(ring[(index+1)%ring.length],ring[index]),0.5);
          let remaining=Math.abs(signed);
          const dir=signed<0?-1:1;
          while(remaining>EPS){
            const next=dir>0?ring[(index+1)%ring.length]:ring[index];
            const dist=length(pos,next);
            if(dist>=remaining||dist<EPS){
              if(dist>EPS)pos=move(pos,difference(next,pos),remaining/dist);
              break;
            }
            remaining-=dist;
            pos=next;
            index=(index+dir+ring.length)%ring.length;
          }
          const p0=ring[index],p1=ring[(index+1)%ring.length];
          const tangent=difference(p1,p0),norm=length(p0,p1);
          if(norm<EPS){vertices.length=0;break;}
          const left={x:-tangent.y/norm,y:tangent.x/norm};
          const mid=move(p0,tangent,0.5);
          const inward=contains(move(mid,left,0.0001),cheapParts)
            ? left:{x:-left.x,y:-left.y};
          vertices.push(move(pos,inward,wiggle*(1+(k%2?0.6:0))));
        }
        if(vertices.length!==9)continue;
        const kmLength=vertices.slice(1).reduce((sum,p,i)=>
          sum+length(p,vertices[i]),0);
        if(kmLength<=1.8&&vertices.every(p=>
          contains(p,cheapParts)&&contains(p,expensiveParts)))
          return vertices.map(projection.ll);
      }
    }
    return null;
  };
  // Fallback for distant, unresolved land/sea links: only two small, local,
  // facing-patch marks, with a fully empty interstation gap.
  // Resolution is deliberately an INPUT to seam geometry. This module
  // only supplies today's default cheapest-reachable resolver; a later
  // override-aware resolver may provide boundaryOwner and boundaryShape.
  const defaultResolutions = (legs, transfers, costs) => {
    const output = {};
    const add = (key, from, to) => {
      if (!Object.hasOwn(costs, from) || !Object.hasOwn(costs, to)) return;
      const fromCost = Number(costs[from]), toCost = Number(costs[to]);
      if (!Number.isFinite(fromCost) || !Number.isFinite(toCost) ||
          Math.abs(fromCost - toCost) <= 0.005) return;
      // Equal-cost reach has no cheaper-to-dearer boundary: a linked land
      // service merges instead (panel F). Never manufacture a transfer.
      output[key] = {
        boundaryOwner: 'station:' + (fromCost < toCost ? from : to),
        rule: 'cheapest-reachable'
      };
    };
    legs.forEach(leg => {
      if (leg.to_station_id != null)
        add('leg:' + leg.id_leg, leg.from_station_id, leg.to_station_id);
    });
    transfers.forEach(transfer =>
      add('transfer:' + transfer.id_transfer,
        transfer.from_station_id, transfer.to_station_id));
    return output;
  };
  const stitches = (first, second, lane = 0, resolution = null) => {
    const boundary = resolution?.boundaryShape ||
      (resolution?.boundaryOwner === first.key ? first :
        resolution?.boundaryOwner === second.key ? second : null);
    const other = boundary === first ? second : first;
    // No winner / boundary result means no fabricated transfer seam.
    const insideLens = boundary ? zigzagSeam(boundary, other, lane) : null;
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
    // True semicircle: every sampled point is radius d/2 from the
    // midpoint of the stations, rather than a merely sinusoidal arch.
    const sample = t => move(move(a, unit, radius * (1 - Math.cos(Math.PI * t))),
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

  // Panel E: the symbolic sea line is STRAIGHT, clipped to the two local
  // patches rather than painting a maritime coverage band.
  const seaLine = (from, to) => {
    const projection = projector([[from.lat, from.lng], [to.lat, to.lng]]);
    const a = projection.xy([from.lat, from.lng]);
    const b = projection.xy([to.lat, to.lng]);
    const delta = difference(b, a), distance = length(a, b);
    if (distance < EPS) return null;
    const unit={x:delta.x/distance,y:delta.y/distance};
    const edgeAt = (record, start, direction) => {
      const ring=shapeXY(record,projection);
      const dir={x:unit.x*direction,y:unit.y*direction};
      let closest=null, best=Infinity;
      for(let i=0;i<ring.length;i++) {
        const p=ring[i],q=ring[(i+1)%ring.length];
        const ray=move(start,dir,distance*3+1000);
        const t=intersection(start,ray,p,q);
        if(t!==null && t<best) {best=t;closest=move(start,difference(ray,start),t);}
      }
      return closest||move(start,dir,Math.max(0,Number(record.radiusKm)||0));
    };
    const left=edgeAt(from,a,1),right=edgeAt(to,b,-1);
    const tip=right;
    const arrowSize=Math.min(3,Math.max(0.3,distance*0.02));
    const side={x:-unit.y,y:unit.x};
    const rear=move(tip,unit,-arrowSize);
    return {points:[projection.ll(left),projection.ll(right)],
      arrow:[projection.ll(move(rear,side,arrowSize*0.45)),
        projection.ll(tip),projection.ll(move(rear,side,-arrowSize*0.45))]};
  };
  // Panel H/v7: classify a whole island, not merely an intersecting lens.
  // Treat the sea polygon as continuous underneath the island; containment
  // controls only whether a FULL-perimeter stitch is appropriate.
  const containsPatch = (sea, island) => {
    const seaParts = patchPaths(sea), islandParts = patchPaths(island);
    if (!seaParts.length || !islandParts.length) return false;
    const projection = projector([[sea.lat, sea.lng], [island.lat, island.lng]]);
    const area = seaParts.map(polygon => polygon.map(ring => ring.map(projection.xy)));
    const inSea = xy => area.some(polygon =>
      inside(xy, polygon[0]) && !polygon.slice(1).some(hole => inside(xy, hole)));
    return islandParts.every(polygon => polygon[0].length >= 3 &&
      polygon[0].every(vertex => inSea(projection.xy(vertex))));
  };

  // Full zigzag around EACH exterior island ring (including multipolygon
  // islands). No artificial gap: this overlays the island boundary while the
  // sea's uninterrupted fill remains visible through the translucent island.
  const islandStitchRings = island => {
    const parts = patchPaths(island);
    if (!parts.length) return [];
    const projection = projector([[island.lat, island.lng]]);
    const all = [];
    for (const part of parts) {
      if (!part.length || part[0].length < 3) continue;
      const boundary = part[0].map(projection.xy);
      if (length(boundary[0], boundary[boundary.length - 1]) < 1e-7) boundary.pop();
      if (boundary.length < 3) continue;
      const outline = [];
      const radius = Number(island.radiusKm) || 1;
      const amplitude = Math.min(0.09, Math.max(0.012, radius * 0.012));
      for (let i = 0; i < boundary.length; i++) {
        const p = boundary[i], q = boundary[(i + 1) % boundary.length];
        const delta = difference(q, p), d = length(p, q);
        if (d < EPS) continue;
        const normal = {x: -delta.y / d, y: delta.x / d};
        outline.push(projection.ll(p));
        // A true alternating zigzag, not the smooth edge duplicated.
        outline.push(projection.ll(move(
          move(p, delta, 0.5), normal, (i % 2 === 0 ? amplitude : -amplitude)
        )));
      }
      if (outline.length >= 6) {
        outline.push(outline[0].slice());
        all.push(outline);
      }
    }
    return all;
  };
  const routePaths = value => {
    if (!value) return [];
    try {
      const geo=typeof value==='string'?JSON.parse(value):value;
      const segments=geo.type==='LineString'?[geo.coordinates]:
        geo.type==='MultiLineString'?geo.coordinates:[];
      return segments.map(seg=>seg.map(p=>[Number(p[1]),Number(p[0])]))
        .filter(seg=>seg.length>1);
    }catch(_){return [];}
  };
  global.GrtransCoverage = Object.freeze({
    kind, patchContour, patchPaths, leafletPaths, coverageTiers, overlaps, stationGroups, unionContours,
    zigzagSeam, stitches, defaultResolutions, airArc, seaLine, routePaths,
    unionCoveragePaths,
    containsPatch, islandStitchRings
  });
}(window));
