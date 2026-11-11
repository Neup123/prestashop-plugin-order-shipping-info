/* Pure interaction model: no repository mutations and no geometry-model changes. */
(function (host, create) {
  const api = create();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else host.GrtransMapWorkspaceModel = api;
})(typeof window === 'object' ? window : this, function () {
  'use strict';
  const MODES = Object.freeze(['select','place','move','connect-leg','connect-transfer','preview','draw','edit-coverage',
    'place-area','radius','edit-route']);
  const ENTITY = Object.freeze(['stations','legs','transfers','branches','branch_stock']);
  const number = value => Number(value);
  const validPoint = point => point && point.lat !== null && point.lng !== null
    && point.lat !== undefined && point.lng !== undefined
    && String(point.lat).trim() !== '' && String(point.lng).trim() !== ''
    && Number.isFinite(number(point.lat)) && Number.isFinite(number(point.lng))
    && number(point.lat) >= -90 && number(point.lat) <= 90 && number(point.lng) >= -180 && number(point.lng) <= 180;
  const normalizedPoint = point => validPoint(point)
    ? {lat: Number(number(point.lat).toFixed(7)), lng: Number(number(point.lng).toFixed(7))} : null;
  const nudge = (point, direction, fine = false) => {
    const p = normalizedPoint(point);
    if (!p) return null;
    const step = fine ? 0.00005 : 0.0005;
    if (direction === 'ArrowUp') p.lat += step;
    else if (direction === 'ArrowDown') p.lat -= step;
    else if (direction === 'ArrowLeft') p.lng -= step;
    else if (direction === 'ArrowRight') p.lng += step;
    else return null;
    return normalizedPoint(p);
  };
  const pair = (first, second) => {
    const a = number(first), b = number(second);
    if (!Number.isInteger(a) || !Number.isInteger(b) || a <= 0 || b <= 0 || a === b) return null;
    return {from_station_id: a, to_station_id: b};
  };
  const originFor = (data, entity, id) => {
    const key = number(id);
    if (entity === 'stations') return key;
    if (entity === 'legs' || entity === 'transfers') {
      return number((data[entity] || []).find(row => number(row['id_' + entity.slice(0,-1)]) === key)?.from_station_id || 0);
    }
    if (entity === 'branches') return number((data.branches || []).find(row => number(row.id_branch) === key)?.origin_station_id || 0);
    if (entity === 'branch_stock') {
      const stock = (data.branch_stock || []).find(row => number(row.id_branch_stock) === key);
      return number((data.branches || []).find(row => number(row.id_branch) === number(stock?.id_branch))?.origin_station_id || 0);
    }
    return 0;
  };
  const recordsAt = (data, entity, stationId) => {
    const id = number(stationId);
    if (entity === 'stations') return (data.stations || []).filter(s => number(s.id_station) === id);
    if (entity === 'legs' || entity === 'transfers') return (data[entity] || []).filter(r =>
      number(r.from_station_id) === id || number(r.to_station_id) === id);
    if (entity === 'branches') return (data.branches || []).filter(r => number(r.origin_station_id) === id);
    if (entity === 'branch_stock') {
      const ids = new Set((data.branches || []).filter(r => number(r.origin_station_id) === id).map(r => number(r.id_branch)));
      return (data.branch_stock || []).filter(r => ids.has(number(r.id_branch)));
    }
    return [];
  };
  const recordId = (entity, record) => number(record && record[{stations:'id_station', legs:'id_leg',transfers:'id_transfer',branches:'id_branch',branch_stock:'id_branch_stock'}[entity]]);
  const needsDraft = mode => ['place','move','connect-leg','connect-transfer','draw','edit-coverage','place-area','radius','edit-route'].includes(mode);
  const modesEnabled = mode => MODES.includes(mode);
  return Object.freeze({MODES,ENTITY,validPoint,normalizedPoint,nudge,pair,originFor,recordsAt,recordId,needsDraft,modesEnabled});
});
