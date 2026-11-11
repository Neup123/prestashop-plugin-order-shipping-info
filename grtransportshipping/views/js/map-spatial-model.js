/* Spatial form-draft helpers. WGS84 and the existing NetworkGeometry field contract only. */
(function (host, make) {
  const api = make();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else host.GrtransMapSpatialModel = api;
})(typeof window === 'object' ? window : this, function () {
  'use strict';
  const EARTH_KM = 6371.0088;
  const rad = degrees => degrees * Math.PI / 180;
  const deg = radians => radians * 180 / Math.PI;
  const point = value => {
    if (!value || value.lat == null || value.lng == null ||
        String(value.lat).trim() === '' || String(value.lng).trim() === '') return null;
    const lat = Number(value.lat), lng = Number(value.lng);
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180
      ? {lat: Number(lat.toFixed(7)), lng: Number(lng.toFixed(7))} : null;
  };
  const pair = p => [point(p).lng, point(p).lat];
  const latLng = value => ({lat: Number(value[1]), lng: Number(value[0])});
  const distanceKm = (a, b) => {
    const x = point(a), y = point(b);
    if (!x || !y) return NaN;
    const dLat = rad(y.lat - x.lat), dLng = rad(y.lng - x.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(x.lat)) * Math.cos(rad(y.lat)) * Math.sin(dLng / 2) ** 2;
    return EARTH_KM * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
  };
  const eastHandle = (centre, km) => {
    const p = point(centre), distance = Number(km);
    if (!p || !Number.isFinite(distance) || distance < 0) return null;
    const angular = distance / EARTH_KM, lat = rad(p.lat), lng = rad(p.lng);
    const nextLat = Math.asin(Math.sin(lat) * Math.cos(angular));
    const nextLng = lng + Math.atan2(Math.sin(angular) * Math.cos(lat), Math.cos(angular) - Math.sin(lat) * Math.sin(nextLat));
    return point({lat: deg(nextLat), lng: ((deg(nextLng) + 540) % 360) - 180});
  };
  const open = (raw, category) => {
    if (!raw || !String(raw).trim()) return null;
    const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
    const shape = data?.type === 'Feature' ? data.geometry : data;
    if (!shape || !Array.isArray(shape.coordinates)) throw new Error('Expected GeoJSON geometry with coordinates.');
    const allowed = category === 'polygon' ? ['Polygon', 'MultiPolygon'] : ['LineString', 'MultiLineString'];
    if (!allowed.includes(shape.type)) throw new Error('Expected '+allowed.join(' or ')+'.');
    // Keep original geometry kind, rings, islands, holes and segments intact.
    return JSON.parse(JSON.stringify({type:shape.type, coordinates:shape.coordinates}));
  };
  const polygons = geometry => geometry?.type === 'Polygon' ? [geometry.coordinates]
    : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
  const lines = geometry => geometry?.type === 'LineString' ? [geometry.coordinates]
    : geometry?.type === 'MultiLineString' ? geometry.coordinates : [];
  const assess = (geometry, category) => {
    if (!geometry) return {ok:false, message:'Choose points on the map before saving.'};
    let count = 0;
    const fail = message => ({ok:false,message});
    if (category === 'polygon' && ['Polygon','MultiPolygon'].includes(geometry.type)) {
      const islands = polygons(geometry);
      if (!islands.length || islands.length > 12) return fail('Coverage must have 1–12 islands.');
      for (const island of islands) {
        if (!Array.isArray(island) || !island.length || island.length > 20) return fail('Each island must have 1–20 rings.');
        for (const ring of island) {
          if (!Array.isArray(ring) || ring.length < 4) return fail('A ring needs at least three vertices.');
          for (const coordinate of ring) {
            if (!coordinateValid(coordinate)) return fail('Use valid signed longitude and latitude.');
            if (++count > 1500) return fail('Geometry exceeds the 1,500-vertex limit.');
          }
          const first = ring[0], last = ring[ring.length - 1];
          if (Number(first[0]) !== Number(last[0]) || Number(first[1]) !== Number(last[1])) return fail('Polygon rings must be closed.');
        }
      }
    } else if (category === 'line' && ['LineString','MultiLineString'].includes(geometry.type)) {
      const segments = lines(geometry);
      if (!segments.length || segments.length > 12) return fail('Route must have 1–12 segments.');
      for (const segment of segments) {
        if (!Array.isArray(segment) || segment.length < 2) return fail('A route segment needs two or more vertices.');
        for (const coordinate of segment) {
          if (!coordinateValid(coordinate)) return fail('Use valid signed longitude and latitude.');
          if (++count > 1500) return fail('Geometry exceeds the 1,500-vertex limit.');
        }
      }
    } else return fail('Shape type does not match its saved network field.');
    if (new TextEncoder().encode(JSON.stringify(geometry)).length > 60000) return fail('GeoJSON exceeds the 60 KB limit.');
    return {ok:true, message:'Geometry is ready to save.'};
  };
  const coordinateValid = coordinate => Array.isArray(coordinate) && coordinate.length === 2 &&
    coordinate.every(value => (typeof value === 'number' || typeof value === 'string') &&
      String(value).trim() !== '' && Number.isFinite(Number(value))) &&
    Math.abs(Number(coordinate[0])) <= 180 && Math.abs(Number(coordinate[1])) <= 90;
  const close = ring => {
    const result = ring.map(p => [Number(p[0]), Number(p[1])]);
    if (!result.length) return result;
    const first = result[0], last = result[result.length - 1];
    if (first[0] !== last[0] || first[1] !== last[1]) result.push([...first]);
    return result;
  };
  const move = (geometry, category, island, ring, index, value) => {
    const p = point(value); if (!p) return null;
    const copy = JSON.parse(JSON.stringify(geometry));
    const arr = category === 'polygon' ? polygons(copy)[island]?.[ring] : lines(copy)[island];
    if (!arr || index < 0 || index >= arr.length) return null;
    arr[index] = pair(p);
    if (category === 'polygon' && (index === 0 || index === arr.length - 1)) {
      arr[0] = pair(p); arr[arr.length - 1] = pair(p);
    }
    return copy;
  };
  const insert = (geometry, category, island, ring, after, value) => {
    const p = point(value); if (!p) return null;
    const copy = JSON.parse(JSON.stringify(geometry));
    const arr = category === 'polygon' ? polygons(copy)[island]?.[ring] : lines(copy)[island];
    if (!arr || after < 0 || after >= arr.length) return null;
    arr.splice(after + 1, 0, pair(p));
    if (category === 'polygon') {arr[arr.length - 1] = [...arr[0]];}
    return assess(copy,category).ok ? copy : null;
  };
  const remove = (geometry, category, island, ring, index) => {
    const copy = JSON.parse(JSON.stringify(geometry));
    const arr = category === 'polygon' ? polygons(copy)[island]?.[ring] : lines(copy)[island];
    if (!arr) return null;
    if (category === 'polygon') {
      if (arr.length <= 4) return null;
      arr.splice(index === arr.length - 1 ? 0 : index,1);
      arr[arr.length - 1] = [...arr[0]];
    } else {
      if (arr.length <= 2) return null;
      arr.splice(index,1);
    }
    return assess(copy,category).ok ? copy : null;
  };
  const appendRing = (geometry, ring, asIsland = false, islandIndex = 0) => {
    const closed = close(ring);
    const copy = geometry ? JSON.parse(JSON.stringify(geometry)) : {type:'Polygon',coordinates:[]};
    if (!geometry) copy.coordinates = [closed];
    else if (asIsland) {
      if (copy.type === 'Polygon') {copy.type = 'MultiPolygon';copy.coordinates = [copy.coordinates,[closed]];}
      else copy.coordinates.push([closed]);
    } else if (copy.type === 'Polygon') copy.coordinates.push(closed);
    else {
      if (!copy.coordinates[islandIndex]) return null;
      copy.coordinates[islandIndex].push(closed);
    }
    return assess(copy,'polygon').ok ? copy : null;
  };
  const appendSegment = (geometry, segment) => {
    const list = segment.map(pair);
    const copy = geometry ? JSON.parse(JSON.stringify(geometry)) : {type:'LineString',coordinates:list};
    if (geometry && copy.type === 'LineString') {copy.type='MultiLineString';copy.coordinates=[copy.coordinates,list];}
    else if (geometry) copy.coordinates.push(list);
    return assess(copy,'line').ok ? copy : null;
  };
  const deletePart = (geometry, category, island, ring) => {
    const copy = JSON.parse(JSON.stringify(geometry));
    if (category === 'polygon') {
      if (ring === 0 && polygons(copy).length <= 1) return null;
      if (ring === 0) {
        if (copy.type === 'Polygon') return null;
        copy.coordinates.splice(island,1);
      } else polygons(copy)[island]?.splice(ring,1);
    } else {
      if (lines(copy).length <= 1) return null;
      if (copy.type === 'MultiLineString') copy.coordinates.splice(island,1);
    }
    return assess(copy,category).ok ? copy : null;
  };
  return Object.freeze({point,latLng,pair,distanceKm,eastHandle,open,polygons,lines,assess,close,move,insert,remove,
    appendRing,appendSegment,deletePart});
});
