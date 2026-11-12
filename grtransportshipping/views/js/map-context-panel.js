/* #131: contextual views over existing station/leg records; writes use the signed network form only. */
(() => {
  'use strict';
  const workspace=window.GrtransMapWorkspace;
  const model=window.GrtransMapWorkspaceModel;
  if(!workspace||!model)return;
  const {bridge,stage}=workspace;
  const context=stage.querySelector('.grtrans-map-context');
  const data=bridge.data;
  const node=(tag,label,cls='')=>{
    const e=document.createElement(tag);if(cls)e.className=cls;
    if(label!==undefined)e.textContent=label;return e;
  };
  const makeButton=(label,icon,handler)=>{
    const b=node('button',undefined,'btn btn-default btn-sm grtrans-context-icon');b.type='button';
    b.title=label;b.setAttribute('aria-label',label);
    b.append(node('span',icon,'material-icons'));
    b.addEventListener('click',handler);return b;
  };
  const station=id=>(data.stations||[]).find(s=>Number(s.id_station)===Number(id));
  const entityNames={stations:'Station',legs:'Connection',transfers:'Transfer',branches:'Dispatch location',branch_stock:'Product availability'};
  let drawId=0;
  const message=(host,label,isError=false)=>{
    const out=node('output',label,'grtrans-context-feedback');out.setAttribute('aria-live','polite');
    if(isError)out.dataset.state='error';host.append(out);return out;
  };
  const clearShading=()=>bridge.shadeStationPrices?.(new Map());
  const recordControls=(entity,id,host)=>{
    host.append(makeButton('Edit '+entityNames[entity]+' #'+id,'edit',()=>workspace.editNetwork(entity,id)));
  };
  const centre=(id)=>{
    const s=station(id);if(!s)return;
    bridge.map.panTo([Number(s.lat),Number(s.lng)],{animate:false});
    bridge.focusStation(id);
  };
  const stationDetails=(id)=>{
    const s=station(id);
    const details=node('details',undefined,'grtrans-context-station-details');
    details.append(node('summary','Station settings'));
    if(!s)return details;
    const fields=[['Name',s.name],['Type',s.type],['Address',s.address_text],
      ['Latitude',s.lat],['Longitude',s.lng],['Normal reach (km)',s.inner_radius_km],
      ['Extended reach (km)',s.outer_radius_km],['Dispatch hub',Number(s.is_origin_hub)===1?'Yes':'No']];
    const grid=node('dl',undefined,'grtrans-context-station-grid');
    for(const [label,value] of fields){grid.append(node('dt',label),node('dd',value==null?'—':String(value)));}
    details.append(grid);return details;
  };
  const stationRow=(id,shading,onDrop,mode='bus')=>{
    const s=station(id);if(!s)return null;
    const row=node('li',undefined,'grtrans-context-stop');row.dataset.stationId=String(id);
    const handle=node('span','drag_indicator','material-icons grtrans-stop-drag');
    handle.draggable=Boolean(onDrop);handle.title=onDrop?'Drag to reorder stops':'Stop order cannot be changed';
    if(onDrop){
      handle.addEventListener('dragstart',event=>{
        event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(id));
        row.classList.add('grtrans-dragging');
      });
      handle.addEventListener('dragend',()=>row.classList.remove('grtrans-dragging'));
      row.addEventListener('dragover',event=>{event.preventDefault();event.dataTransfer.dropEffect='move';});
      row.addEventListener('drop',event=>{
        event.preventDefault();const source=Number(event.dataTransfer.getData('text/plain'));
        if(source>0&&source!==id)onDrop(source,id);
      });
    }
    row.append(handle);
    const toggle=node('input');toggle.type='checkbox';toggle.checked=Number(s.active??1)===1;
    toggle.setAttribute('aria-label','Enable '+s.name);toggle.title='Enable or disable this station';
    const result=node('output',undefined,'grtrans-inline-state');result.setAttribute('aria-live','polite');
    toggle.addEventListener('change',async()=>{
      toggle.disabled=true;
      const ok=await window.GrtransCRM?.saveNetworkRecord('stations',id,
        {active:toggle.checked?'1':'0'},result);
      if(ok){message(row,'Station saved; refreshing map.');location.reload();}
      else {toggle.checked=!toggle.checked;toggle.disabled=false;}
    });
    row.append(toggle);
    const name=node('span',s.name,'grtrans-stop-name');
    name.append(node('small',' · '+(s.type||'station')));row.append(name);
    row.append(makeButton('Centre map on '+s.name,'my_location',()=>centre(id)));
    const menu=node('details',undefined,'grtrans-stop-menu');
    const summary=node('summary','more_vert','material-icons');summary.title='Station actions';summary.setAttribute('aria-label','Station actions');
    menu.append(summary);
    menu.append(makeButton('Edit '+s.name,'edit',()=>workspace.editNetwork('stations',id)));
    row.append(menu,result,stationDetails(id));
    if(shading?.has(id))row.style.setProperty('--grtrans-stop-shade',String(shading.get(id)));
    return row;
  };
  const renderLine=(leg,host)=>{
    const line=model.lineFor(data,leg.id_leg);
    if(!line)return;
    const mode=leg.mode||'other';
    const heading=node('h4',(mode==='bus'?'Bus':mode==='ferry'?'Ship':mode==='flight'?'Plane':'Service')
      +' · '+(leg.operator_label||('#'+leg.id_leg)));
    host.append(heading);
    const summary=node('dl',undefined,'grtrans-context-line-facts');
    for(const [label,value] of [['Method',mode],['Carrier',leg.operator_label],
      ['Price calculation',leg.tariff_type],['Base price (EUR)',leg.price],
      ['Runs both directions',Number(leg.bidirectional)===1?'Yes':'No']]){
      summary.append(node('dt',label),node('dd',String(value??'—')));
    }
    host.append(summary);
    recordControls('legs',Number(leg.id_leg),host);
    if(line.area){
      host.append(node('p','Direct service area: '+(leg.area_name||'unnamed area')));
      host.append(node('p','Centre: '+(leg.area_lat??'—')+', '+(leg.area_lng??'—')));
      host.append(makeButton('Edit area boundary','edit_location',()=>{
        workspace.editNetwork('legs',Number(leg.id_leg));workspace.changeMode('edit-coverage');
      }));
      return;
    }
    if(mode==='ferry'||mode==='flight'){
      const endpoints=node('div',undefined,'grtrans-context-endpoints');
      const a=station(leg.from_station_id),b=station(leg.to_station_id);
      for(const [label,s] of [['Departure',a],['Arrival',b]]){
        const item=node('div',undefined,'grtrans-endpoint');item.append(node('strong',label));
        if(s)item.append(node('span',s.name),makeButton('Centre '+s.name,'my_location',()=>centre(s.id_station)));
        endpoints.append(item);
      }
      host.append(endpoints);return;
    }
    const count=line.stops.length;
    if(!count){host.append(node('p','This service has branching connections; open each leg to edit its route.'));return;}
    const different=line.legs.length>1 && new Set(line.legs.map(item=>Number(item.price))).size>1;
    const setting=node('label',undefined,'grtrans-price-variation');
    const variation=node('input');variation.type='checkbox';variation.checked=different;
    variation.disabled=!different;
    setting.append(variation,node('span','Highlight different prices at stations'));
    host.append(setting);
    host.append(node('p',different?'Segment prices vary. Shading shows cumulative stored prices; it does not change tariffs.':
      'Segment prices do not vary; line pricing is defined by the existing legs.','grtrans-context-note'));
    const stops=node('ol',undefined,'grtrans-context-stops');host.append(stops);
    const status=message(host,'');
    const shading=model.stationPriceShade(line);
    const paint=()=>{
      const visible=variation.checked?shading:new Map();bridge.shadeStationPrices?.(visible);
      for(const item of stops.children){
        const ratio=visible.get(Number(item.dataset.stationId))||0;
        item.style.setProperty('--grtrans-stop-shade',String(ratio));
      }
    };
    variation.addEventListener('change',paint);
    const run=++drawId;
    async function saveStopOrder(fromId,toId){
      if(!line.reorderable)return;
      const ids=line.stops.slice();const from=ids.indexOf(fromId),to=ids.indexOf(toId);
      if(from<0||to<0||from===to)return;
      ids.splice(to,0,ids.splice(from,1)[0]);
      const replacements=model.reorderLine(line,ids);
      if(!replacements)return;
      status.dataset.state='saving';status.textContent='Checking road geometry…';
      const planned=[];
      try{
        for(const item of replacements){
          const a=station(item.from_station_id),b=station(item.to_station_id);
          if(!a||!b)throw new Error('A line station has no saved map coordinates.');
          // Reuse the same routed-geometry service as the map preview. Do not
          // save a reordered road line if routing fails; retain stored geometry.
          const road=await bridge.roadRoute({lat:Number(a.lat),lng:Number(a.lng)},
            {lat:Number(b.lat),lng:Number(b.lng)});
          item.route_geojson=JSON.stringify({type:'LineString',coordinates:road.points.map(p=>[p[1],p[0]])});
          planned.push(item);
        }
      }catch(error){
        status.dataset.state='error';status.textContent='Not saved: '+error.message+'. Stored route retained.';return;
      }
      status.textContent='Saving ordered legs…';
      let saved=0;
      for(const item of planned){
        if(run!==drawId)return;
        const original=(data.legs||[]).find(r=>Number(r.id_leg)===Number(item.id_leg));
        const changes={from_station_id:item.from_station_id,to_station_id:item.to_station_id,
          stop_order:item.stop_order,route_geojson:item.route_geojson};
        if(!original||!await window.GrtransCRM?.saveNetworkRecord('legs',item.id_leg,changes,status)){
          status.dataset.state='error';
          status.textContent='Reorder incomplete: '+saved+' of '+planned.length+
            ' legs saved. Reload and reconcile before retrying.';
          return;
        }
        saved++;
      }
      status.dataset.state='saved';status.textContent='Saved. Refreshing routed line…';
      location.reload();
    }
    for(const id of line.stops){const row=stationRow(id,different?shading:null,
      line.reorderable&&mode==='bus'?saveStopOrder:null);if(row)stops.append(row);}
    paint();
    if(line.reorderable && mode==='bus'){
      status.textContent='Drag a station handle to rebuild the line on roads.';
      Promise.all(line.legs.map(async item=>{
        const a=station(item.from_station_id),b=station(item.to_station_id);
        return bridge.roadRoute({lat:Number(a.lat),lng:Number(a.lng)},
          {lat:Number(b.lat),lng:Number(b.lng)});
      })).then(routes=>{
        if(run===drawId)status.textContent='Road distance: '+routes.reduce((sum,r)=>sum+r.distanceKm,0).toFixed(1)+' km';
      }).catch(error=>{if(run===drawId)status.textContent='Road distance unavailable: '+error.message;});
    }else status.textContent='Station order is read-only for this service.';
  };
  const render=(selected,host=context)=>{
    if(!host)return;
    drawId++;clearShading();
    host.closest('.grtrans-map-sidebar')?.classList.toggle('grtrans-has-context',Boolean(selected));
    if(!selected)return; // Preserve the existing Add control when no record was selected.
    host.replaceChildren();
    host.append(makeButton('Back to all records','arrow_back',()=>{
      workspace.clearSelection?.();
    }));
    const records=data[selected.entity]||[];
    const idKey='id_'+(selected.entity==='branch_stock'?'branch_stock':selected.entity.slice(0,-1));
    const record=records.find(item=>Number(item[idKey])===Number(selected.id));
    if(!record){host.append(node('p','This record is disabled or is not present in the current map dataset.'));return;}
    if(selected.entity==='legs'){renderLine(record,host);return;}
    const title=record.name||record.area_name||record.operator_label||record.connection_type||
      entityNames[selected.entity];
    host.append(node('h4',title+' #'+selected.id));
    if(selected.entity==='stations'){
      host.append(stationDetails(selected.id));
      host.append(makeButton('Centre map on station','my_location',()=>centre(selected.id)));
    }else{
      const facts=node('dl',undefined,'grtrans-context-line-facts');
      for(const [key,value] of Object.entries(record)){
        if(key.startsWith('_')||key==='coverage_geojson'||key==='route_geojson'||key==='area_coverage_geojson')continue;
        facts.append(node('dt',key.replaceAll('_',' ')),node('dd',String(value??'—')));
      }
      host.append(facts);
    }
    recordControls(selected.entity,selected.id,host);
    if(selected.entity==='branches'){
      host.append(makeButton('Manage products','inventory_2',()=>workspace.editNetwork('branch_stock',0)));
    }
  };
  window.GrtransMapContext={render};
  render(workspace.selected(),context);
})();
