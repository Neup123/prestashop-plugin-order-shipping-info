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
  // A line is a connected, non-branching chain of existing priced legs, not a new entity.
  // Labels and modes must agree; unrelated services sharing an operator are not grouped.
  const lineFor = (data, legId) => {
    const all=data.legs || [];
    const chosen=all.find(leg=>number(leg.id_leg)===number(legId));
    if(!chosen)return null;
    if(!chosen.to_station_id)return {legs:[chosen],stops:[number(chosen.from_station_id)],reorderable:false,area:true};
    const same=all.filter(leg=>leg.to_station_id && leg.mode===chosen.mode
      && leg.operator_label===chosen.operator_label && number(leg.active??1)===1);
    const connected=new Map([[number(chosen.id_leg),chosen]]);
    let changed=true;
    while(changed){
      changed=false;
      const stops=new Set([...connected.values()].flatMap(leg=>[number(leg.from_station_id),number(leg.to_station_id)]));
      for(const leg of same){
        if(connected.has(number(leg.id_leg)))continue;
        if(stops.has(number(leg.from_station_id))||stops.has(number(leg.to_station_id))){
          connected.set(number(leg.id_leg),leg);changed=true;
        }
      }
    }
    const legs=[...connected.values()];
    const out=new Map(),incoming=new Map();
    for(const leg of legs){
      const from=number(leg.from_station_id),to=number(leg.to_station_id);
      out.set(from,[...(out.get(from)||[]),leg]);
      incoming.set(to,(incoming.get(to)||0)+1);
    }
    const branched=[...out.values()].some(edges=>edges.length!==1)
      || [...incoming.values()].some(count=>count!==1);
    const first=[...out.keys()].filter(id=>!incoming.has(id));
    if(branched||first.length!==1){
      return {legs:legs.sort((a,b)=>number(a.stop_order)-number(b.stop_order)||number(a.id_leg)-number(b.id_leg)),
        stops:[],reorderable:false,area:false};
    }
    const ordered=[],stops=[first[0]],seen=new Set();
    let cursor=first[0];
    while(out.has(cursor)&&!seen.has(cursor)){
      seen.add(cursor);
      const leg=out.get(cursor)[0];ordered.push(leg);
      cursor=number(leg.to_station_id);stops.push(cursor);
    }
    return {legs:ordered,stops,reorderable:ordered.length===legs.length && new Set(stops).size===stops.length,area:false};
  };
  const reorderLine = (line, stopIds) => {
    if(!line?.reorderable||stopIds.length!==line.stops.length
      ||new Set(stopIds.map(number)).size!==line.stops.length
      ||line.stops.some(id=>!stopIds.map(number).includes(id))) return null;
    return line.legs.map((leg,i)=>({...leg,from_station_id:number(stopIds[i]),
      to_station_id:number(stopIds[i+1]),stop_order:i+1}));
  };
  const stationPriceShade = line => {
    if(!line?.reorderable)return new Map();
    const cumulative=[0];
    for(const leg of line.legs)cumulative.push(cumulative[cumulative.length-1]+Math.max(0,number(leg.price)||0));
    const max=Math.max(...cumulative);
    return new Map(line.stops.map((id,i)=>[id,max>0?cumulative[i]/max:0]));
  };
  const needsDraft = mode => ['place','move','connect-leg','connect-transfer','draw','edit-coverage','place-area','radius','edit-route'].includes(mode);
  const modesEnabled = mode => MODES.includes(mode);
  return Object.freeze({MODES,ENTITY,validPoint,normalizedPoint,nudge,pair,originFor,recordsAt,recordId,needsDraft,modesEnabled,lineFor,reorderLine,stationPriceShade});
});
