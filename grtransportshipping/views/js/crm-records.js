/* CRM enhancements for the existing authenticated Configure forms. No new writer. */
(() => {
  'use strict';
  const root=document.getElementById('grtrans-configure');
  if(!root)return;
  const by=(selector,base=root)=>base.querySelector(selector);
  const E=(tag,cls='',label)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(label!==undefined)e.textContent=label;return e;};
  const safeJSON=(value,fallback)=>{try{return JSON.parse(value);}catch(_){return fallback;}};
  const labelMap=window.GrtransCRMLabels||{};
  const employee=root.dataset.grtransEmployee||'0';
  const endpoint=by('#grtrans-map-link-form');
  const token=endpoint?.querySelector('input[name="token"]')?.value
    ||by('#grtrans-route-editor input[name="token"]')?.value;
  const routeEndpoint=by('#grtrans-route-editor form')?.action;
  const tables=new Map();
  const idField={stations:'id_station',legs:'id_leg',transfers:'id_transfer',branches:'id_branch',branch_stock:'id_branch_stock'};
  const defaultRouteColumns=[1,2,3,4,5,7,9,10,12];
  const readonlyNetwork=new Set(['geojson_polygon','geojson_line']);
  const tableFields={
    routes:['id_route','mode','origin_name','station_name','city','postal_prefix','price',
      'pickup_only','route_status','demo','notice','Actions']
  };
  const setResult=(element,state,message)=>{
    element.dataset.state=state;element.textContent=message;
  };
  const serialize=(value)=>value==null?'':String(value);
  const textValue=(entry,def,field,entity)=>{
    if(entity==='routes'){
      if(field==='route_status')return {draft:'Draft',available:'Available',suspended:'Suspended'}[entry]||serialize(entry);
      if(field==='demo')return Number(entry)===1?'Unverified estimate':'Operator-confirmed';
      if(field==='pickup_only')return Number(entry)===1?'Yes':'No';
      if(def.choices&&Object.prototype.hasOwnProperty.call(def.choices,String(entry)))return def.choices[String(entry)];
    }
    return serialize(entry);
  };
  const applyRouteStatus=(row,record)=>{
    const status=String(record.route_status);
    row.classList.toggle('grtrans-row-suspended',status==='suspended');
    row.classList.toggle('grtrans-row-demo',status!=='suspended'&&Number(record.demo)===1);
    const statusCell=row.querySelector('[data-grtrans-field="route_status"]');
    const pill=statusCell?.querySelector('.grtrans-pill');
    if(pill){pill.className='grtrans-pill grtrans-state-'+status;pill.textContent=textValue(status,{},'route_status','routes');}
    const demoPill=row.querySelector('[data-grtrans-field="demo"] .grtrans-pill');
    if(demoPill){demoPill.className='grtrans-pill '+(Number(record.demo)===1?'grtrans-demo':'grtrans-verified');demoPill.textContent=textValue(record.demo,{},'demo','routes');}
  };
  const active=(record)=>Number(record.active??1)===1;
  const updateRow=(model,field,value)=>{
    const {entity,row,record,specs}=model;
    record[field]=value;
    const cell=row.querySelector('[data-grtrans-field="'+field+'"]');
    if(cell){
      if(entity==='routes'&&(field==='route_status'||field==='demo'))applyRouteStatus(row,record);
      else cell.querySelector('[data-grtrans-cell-value]')?.replaceChildren(document.createTextNode(textValue(value,specs[field]||{},field,entity)));
    }
    if(entity==='routes'){
      applyRouteStatus(row,record);
      const sw=by('[data-grtrans-toggle-active]',row);
      if(sw){sw.disabled=record.route_status==='draft';sw.checked=record.route_status==='available';}
    }else row.classList.toggle('grtrans-disabled-record',!active(record));
    row.dataset.grtransRecord=JSON.stringify(record);
    const all=window.GrtransMapWorkspace?.bridge?.data?.[entity];
    const live=Array.isArray(all)?all.find(item=>Number(item[idField[entity]])===model.id):null;
    if(live)live[field]=value;
  };
  const post=async(model,field,value,result)=>{
    if(model.saving)return false;
    if(!token){setResult(result,'error','Not saved: administrator session unavailable.');return false;}
    const payload=new FormData();payload.set('token',token);
    let url=endpoint?.action;
    if(model.entity==='routes'){
      url=new URL(routeEndpoint||location.href,location.href);
      url.searchParams.set('grtrans_edit',String(model.id));
      payload.set('grtrans_save','1');payload.set('grtrans_edit_proof',model.proof);
    } else {
      payload.set('grtrans_network_action','save');payload.set('network_entity',model.entity);
      payload.set('network_id',String(model.id));
    }
    for(const [key,entry] of Object.entries(model.record)){
      if(Object.prototype.hasOwnProperty.call(model.specs,key))payload.set(key,key===field?serialize(value):serialize(entry));
    }
    model.saving=true;setResult(result,'saving','Saving…');
    try {
      const response=await fetch(String(url),{method:'POST',body:payload,credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest'}});
      if(!response.ok)throw new Error('HTTP '+response.status);
      const html=new DOMParser().parseFromString(await response.text(),'text/html');
      const success=model.entity==='routes'
        ?[...html.querySelectorAll('.alert-success')].some(el=>el.textContent.includes('Route dataset updated.'))
        :[...html.querySelectorAll('.alert-success[role="status"]')].some(el=>el.textContent.includes('Network record updated.'));
      if(!success){
        const detail=html.querySelector('.alert-danger details code')?.textContent
          ||html.querySelector('#grtrans-form-errors')?.textContent
          ||html.querySelector('.alert-danger')?.textContent||'Server did not confirm this change.';
        throw new Error(detail.trim());
      }
      // Use the server's post-normalization record when it is present (for
      // example, a comma price is stored as a decimal dot).
      const persisted=html.querySelector((model.entity==='routes'
        ? '#grtrans-routes ' : '#grtrans-network-list ')
        +'tr[data-grtrans-record-id="'+model.id+'"]');
      const confirmed=persisted&&safeJSON(persisted.dataset.grtransRecord,null);
      if(confirmed&&typeof confirmed==='object')Object.assign(model.record,confirmed);
      updateRow(model,field,confirmed?.[field]??value);
      setResult(result,'saved','Saved');
      root.dispatchEvent(new CustomEvent('grtrans:crm-saved',{bubbles:true,detail:{entity:model.entity,id:model.id,field,value}}));
      // Map painting is derived from the immutable server snapshot. Refresh only
      // after a successful active change; preserve the authenticated saved feedback.
      if(field==='active')setTimeout(()=>location.reload(),650);
      return true;
    }catch(error){setResult(result,'error','Not saved: '+error.message);return false;}
    finally{model.saving=false;}
  };
  const controlFor=(model,field)=>{
    const spec=model.specs[field]||{},kind=spec.kind||spec[0]||'text';
    let control;
    if(model.entity==='routes'&&['demo','pickup_only'].includes(field)||kind==='bool'){
      control=E('input');control.type='checkbox';control.checked=Number(model.record[field])===1;
    }else if(kind==='choice'||kind==='select'){
      control=E('select','form-control');
      const choices=spec.choices||spec[1]||[];
      const options=Array.isArray(choices)?choices.map(value=>[String(value),String(value)]):Object.entries(choices);
      for(const [value,label] of options){
        const option=new Option(typeof label==='string'?label:String(value),value);
        control.append(option);
      }
      control.value=serialize(model.record[field]);
    }else if(kind==='textarea'){
      control=E('textarea','form-control');control.rows=3;
      control.maxLength=Number(spec.max||2000);
      control.value=serialize(model.record[field]);
    }else {
      control=E('input','form-control');
      const numeric=['range','nullable_range','latitude','longitude','nullable_latitude','nullable_longitude','id','nullable_id','nonnegative_int'].includes(kind);
      control.type=numeric?'number':'text';
      if(numeric){
        const min=kind.includes('latitude')?-90:kind.includes('longitude')?-180:kind==='id'||kind==='nullable_id'?1:Number(spec[1]??0);
        const max=kind.includes('latitude')?90:kind.includes('longitude')?180:kind==='nonnegative_int'||kind==='id'||kind==='nullable_id'?2147483647:spec[2];
        control.min=String(min);if(max!==undefined)control.max=String(max);
        control.step=kind==='nonnegative_int'||kind==='id'||kind==='nullable_id'?'1':'any';
      }else if(spec.max||spec[1])control.maxLength=Number(spec.max||spec[1]);
      control.value=serialize(model.record[field]);
      if(kind==='id'||kind==='nullable_id'){
        // Use existing station/branch options where the real editor exposes them.
        const existing=by('#grtrans-network-editor [name="'+field+'"]');
        if(existing?.tagName==='SELECT'){
          const select=existing.cloneNode(true);select.removeAttribute('id');select.value=serialize(model.record[field]);
          control=select;
        }
      }
    }
    if(model.entity==='stations'&&['name','lat','lng'].includes(field))control.required=true;
    if(field==='id_product'){
      const list=by('#grtrans-product-catalog');
      if(list?.options?.length){
        const picker=E('input','form-control');picker.type='search';
        picker.setAttribute('list',list.id);
        picker.dataset.grtransProductSearch='1';
        picker.value=[...list.options].find(item=>item.value.endsWith('(#'+model.record[field]+')'))?.value
          ||serialize(model.record[field]);
        control=picker;
      }
    }
    control.setAttribute('aria-label',(labelMap[field]||field)+' for record #'+model.id);
    return control;
  };
  let opened=null;
  const close=()=>{if(opened){opened.cancel();opened=null;}};
  const open=(model,field)=>{
    const spec=model.specs[field];if(!spec)return;
    const kind=spec.kind||spec[0];
    if(readonlyNetwork.has(kind))return;
    const cell=model.row.querySelector('[data-grtrans-field="'+field+'"]');if(!cell)return;
    close();
    const display=by('[data-grtrans-cell-value]',cell);
    const editor=E('div','grtrans-crm-inline');
    const input=controlFor(model,field);
    const state=E('output','grtrans-inline-state');state.setAttribute('aria-live','polite');
    const controls=E('div','grtrans-crm-inline-controls');
    const save=E('button','btn btn-default btn-sm grtrans-crm-save');save.type='button';
    save.title='Save';save.setAttribute('aria-label','Save '+(labelMap[field]||field));
    save.append(E('span','material-icons','check'));
    controls.append(input,save,state);editor.append(controls);cell.append(editor);
    if(display)display.hidden=true;
    let canceled=false,committing=false;
    const cancel=()=>{if(canceled)return;canceled=true;editor.remove();if(display)display.hidden=false;};
    const read=()=>{
      if(input.dataset.grtransProductSearch==='1'){
        const list=by('#grtrans-product-catalog');
        const item=[...(list?.options||[])].find(option=>option.value===input.value);
        const match=item?.value.match(/\(#([1-9][0-9]*)\)$/);
        return match?match[1]:input.value;
      }
      return input.type==='checkbox'?(input.checked?'1':'0'):input.value;
    };
    const commit=async()=>{
      if(canceled||committing)return;
      const value=read();if(value===serialize(model.record[field])){cancel();return;}
      if(!input.checkValidity()){input.reportValidity();setResult(state,'error','Not saved: invalid value.');return;}
      committing=true;save.disabled=true;
      const ok=await post(model,field,value,state);
      committing=false;save.disabled=false;
      if(ok){cancel();const stable=E('output','grtrans-inline-state','Saved');stable.dataset.state='saved';stable.setAttribute('aria-live','polite');cell.append(stable);setTimeout(()=>stable.remove(),2500);}
    };
    save.addEventListener('click',commit);
    input.addEventListener('keydown',event=>{
      if(event.key==='Escape'){event.stopPropagation();event.preventDefault();cancel();}
      if(event.key==='Enter'&&!event.shiftKey){event.stopPropagation();event.preventDefault();commit();}
    });
    input.addEventListener('blur',()=>{if(!canceled&&!committing)setTimeout(()=>{
      if(!editor.contains(document.activeElement))commit();
    },80);});
    if(input.tagName==='SELECT'||input.type==='checkbox')input.addEventListener('change',commit);
    opened={cancel};input.focus();if(input.select)input.select();
  };
  const installSwitch=(model)=>{
    const {row,entity,record}=model;
    const sw=by('[data-grtrans-toggle-active]',row);if(!sw)return;
    const draft=entity==='routes'&&record.route_status==='draft';
    sw.disabled=draft;
    sw.checked=entity==='routes'?record.route_status==='available':active(record);
    sw.title=draft?'Draft routes can only be enabled in the editor.':'Enable or disable this record';
    if(entity!=='routes')row.classList.toggle('grtrans-disabled-record',!active(record));
    sw.addEventListener('click',event=>event.stopPropagation());
    sw.addEventListener('change',async()=>{
      const previous=entity==='routes'?record.route_status:serialize(record.active);
      const next=entity==='routes'?(sw.checked?'available':'suspended'):(sw.checked?'1':'0');
      const result=by('.grtrans-switch-state',sw.closest('label'));
      if(!result)return;
      sw.disabled=true;
      const ok=await post(model,entity==='routes'?'route_status':'active',next,result);
      sw.disabled=draft;
      if(!ok)sw.checked=entity==='routes'?previous==='available':Number(previous)===1;
    });
  };
  const stored=(key,defaults,max)=>{
    try{const data=safeJSON(localStorage.getItem(key),null);
      if(Array.isArray(data))return new Set(data.filter(i=>Number.isInteger(i)&&i>=1&&i<=max));
    }catch(_){}
    return new Set(defaults);
  };
  const columns=(entity,table,headings,models)=>{
    const key='grtrans-columns:'+employee+':'+entity;
    const defaults=entity==='routes'?defaultRouteColumns:
      headings.map((th,i)=>th.classList.contains('grtrans-keep')?i+1:0).filter(Boolean);
    const visible=stored(key,defaults,headings.length);
    visible.add(1);visible.add(headings.length);
    const apply=()=>{
      headings.forEach((head,i)=>head.classList.toggle('grtrans-keep',visible.has(i+1)));
      models.forEach(model=>[...model.row.cells].forEach((cell,i)=>cell.classList.toggle('grtrans-keep',visible.has(i+1))));
      table.classList.toggle('grtrans-custom-columns',entity==='stations'
        && (visible.size!==defaults.length||defaults.some(v=>!visible.has(v))));
      for(const model of models){
        const dl=by('.grtrans-record-facts',model.row);if(!dl)continue;
        for(const item of dl.querySelectorAll('[data-grtrans-fact-index]'))item.hidden=visible.has(Number(item.dataset.grtransFactIndex));
      }
    };
    apply();
    const details=E('details','grtrans-columns-chooser');
    const trigger=E('summary','btn btn-default btn-sm grtrans-row-icon');
    trigger.title='Choose columns';trigger.setAttribute('aria-label','Choose columns');
    trigger.append(E('span','material-icons','view_column'));details.append(trigger);
    const panel=E('div','grtrans-columns-panel');
    for(let i=1;i<=headings.length;i++){
      const name=tableFields[entity]?.[i-1]||headings[i-1].dataset.grtransField||headings[i-1].textContent.trim();
      const item=E('label');const check=E('input');check.type='checkbox';check.checked=visible.has(i);
      if(i===1||i===headings.length)check.disabled=true;
      item.append(check,E('span','',labelMap[name]||headings[i-1].textContent.trim()));
      check.addEventListener('change',()=>{
        if(check.checked)visible.add(i);else visible.delete(i);
        visible.add(1);visible.add(headings.length);
        try{localStorage.setItem(key,JSON.stringify([...visible].sort((a,b)=>a-b)));}catch(_){}
        apply();
      });
      panel.append(item);
    }
    details.append(panel);
    const container=entity==='routes'?table.closest('.grtrans-route-grid'):
      table.closest('.grtrans-network-list')?.querySelector('.grtrans-record-list-head');
    if(container){if(entity==='routes')container.before(details);else container.append(details);}
  };
  const setup=(table,entity)=>{
    if(!table||!entity)return;
    const specs=safeJSON(table.dataset.grtransCrmSchema,'');if(!specs||typeof specs!=='object')return;
    const headings=[...table.querySelectorAll('thead th')];
    const names=entity==='routes'?tableFields.routes:headings.map((th,i)=>th.dataset.grtransField||String(i));
    if(entity==='routes')headings.forEach((th,i)=>th.classList.toggle('grtrans-keep',defaultRouteColumns.includes(i+1)));
    const models=[...table.querySelectorAll('tbody tr')].map(row=>{
      const record=safeJSON(row.dataset.grtransRecord,'');if(!record||typeof record!=='object')return null;
      const id=Number(row.dataset.grtransRecordId||row.cells[0]?.textContent.trim());
      const model={row,entity,id,record,specs,proof:row.dataset.grtransRouteProof||'',saving:false};
      row.dataset.grtransRecordId=String(id);
      for(const [i,field] of names.entries()){
        if(!Object.prototype.hasOwnProperty.call(specs,field))continue;
        const cell=row.cells[i];if(!cell)continue;
        cell.dataset.grtransField=field;
        const def=specs[field],kind=def.kind||def[0];
        if(kind==='geojson_line'||kind==='geojson_polygon'){
          cell.title='Edit geometry in the record form';continue;
        }
        if(field==='active')continue; // Actions-column switch is the only active writer.
        const current=E('span','grtrans-crm-cell-value');current.dataset.grtransCellValue='';
        if(field==='route_status'||field==='demo')current.hidden=true;
        else current.textContent=cell.textContent.trim();
        if(!current.hidden)cell.replaceChildren(current);else cell.append(current);
        cell.classList.add('grtrans-crm-editable');cell.title='Edit '+(labelMap[field]||field);
        cell.addEventListener('click',event=>{
          if(event.target.closest('input,select,textarea,button,output,.grtrans-crm-inline'))return;
          open(model,field);
        });
        cell.tabIndex=0;
        cell.addEventListener('keydown',event=>{
          if(event.target===cell&&(event.key==='Enter'||event.key===' ')){
            event.preventDefault();event.stopPropagation();open(model,field);
          }
        });
      }
      installSwitch(model);
      return model;
    }).filter(Boolean);
    columns(entity,table,headings,models);
    tables.set(entity,models);
  };
  setup(by('#grtrans-routes .grtrans-route-grid table'),'routes');
  for(const table of root.querySelectorAll('#grtrans-network .grtrans-network-list table')){
    setup(table,table.closest('[data-grtrans-entity]')?.dataset.grtransEntity);
  }
  window.GrtransCRM={
    openEditor:(entity,id,field)=>{
      const model=tables.get(entity)?.find(model=>model.id===Number(id));
      if(model)open(model,field);
    },
    tables
  };
})();
