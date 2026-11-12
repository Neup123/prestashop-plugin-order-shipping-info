/* Stage 1: task-oriented admin workspace. Map paint, geometry and persistence are owned by existing code. */
(() => {
  'use strict';
  const root = document.getElementById('grtrans-configure');
  const network = root?.querySelector('#grtrans-network');
  const bridge = window.GrtransMapBridge;
  const model = window.GrtransMapWorkspaceModel;
  if (!network || !bridge || !model || !window.L) return;
  const byId = id => document.getElementById(id);
  const text = (tag, value, cls = '') => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (value !== undefined) el.textContent = value;
    return el;
  };
  const node = (tag, cls = '') => text(tag, undefined, cls);
  const status = byId('grtrans-map-status');
  const mapRoot = byId('grtrans-network-map');
  const editor = byId('grtrans-network-editor');
  const routeEditor = byId('grtrans-route-editor');
  const routeForm=routeEditor?.querySelector('form');
  const originalNav = network.querySelector('.grtrans-network-toolbar');
  const lists = [...network.querySelectorAll('section.grtrans-network-list')];
  if (!mapRoot || !editor || !originalNav || !lists.length) return;
  const storedKey = 'grtrans-map-workspace-view';
  const modeKey = 'grtrans-map-workspace-intent';
  // Explicit Add survives navigation to the server-rendered dataset.
  const pendingAddKey = 'grtrans-map-pending-add';
  const form = editor.querySelector('form');
  const formEntity = form?.querySelector('[name="network_entity"]')?.value || '';
  let formId = Number(form?.querySelector('[name="network_id"]')?.value || 0);
  const recordData = bridge.data;
  const tabs = [...originalNav.querySelectorAll('a')];
  const entities = model.ENTITY;
  let activeEntity = entities.includes(formEntity) ? formEntity : 'stations';
  let selected = null, firstStation = 0, mode = 'select';
  let originalPosition = null, originalFields = null, originalPair = null;
  let draftMarker = null, dragMarker = null, selectionOverlay = null;
  let stockFilter = 0, working = false;
  let navigationPending = false;
  const stage = node('div','grtrans-map-workspace');stage.id = 'grtrans-map-workspace';
  const tools = node('div','grtrans-map-workspace-tools');
  tools.setAttribute('role','toolbar');tools.setAttribute('aria-label','Service map editing tools');
  const body = node('div','grtrans-map-workspace-body');
  const mapColumn = node('div','grtrans-map-column');
  const side = node('aside','grtrans-map-sidebar');side.setAttribute('aria-label','Manage selected network records');
  const mapDock = node('div','grtrans-map-dock');
  const navDock = node('div','grtrans-map-nav');
  const listDock = node('div','grtrans-map-lists');
  const context = node('div','grtrans-map-context');context.setAttribute('aria-live','polite');
  const editorDock = node('div','grtrans-map-editor-dock');
  const feedback = node('output','grtrans-map-feedback');feedback.setAttribute('aria-live','polite');
  const mapAnchor = network.querySelector('h3');
  mapAnchor.textContent='Service map';
  mapAnchor.after(stage);
  stage.append(tools,body);body.append(mapColumn,side);
  mapColumn.append(mapDock,feedback);
  side.append(navDock,listDock,context,editorDock);
  const intro = mapAnchor.nextElementSibling === stage ? stage.nextElementSibling : null;
  if (intro?.tagName === 'P' && intro !== status?.parentElement) {
    const help = node('details','grtrans-map-help');help.append(text('summary','About this map'));
    help.append(intro);stage.before(help);
  }
  mapDock.append(mapRoot);
  for (const id of ['grtrans-zone-legend','grtrans-network-map-tools','grtrans-preview-status','grtrans-map-link-form']) {
    const el = byId(id);if (el) mapDock.append(el);
  }
  const oldTools = byId('grtrans-network-map-tools');if (oldTools) oldTools.hidden = true;
  const legacyLinkForm = byId('grtrans-map-link-form');if (legacyLinkForm) legacyLinkForm.hidden = true;
  const oldPreviewStatus = byId('grtrans-preview-status');if (oldPreviewStatus) oldPreviewStatus.hidden = true;
  const newStatus = text('span','Select a station or record to begin.','grtrans-map-status');
  newStatus.setAttribute('role','status');newStatus.setAttribute('aria-live','polite');
  tools.append(newStatus);
  navDock.append(originalNav);
  for (const list of lists) listDock.append(list);
  editorDock.append(editor);
  if(routeEditor){editorDock.append(routeEditor);routeEditor.hidden=true;}
  // Retain the submitted edit and the values of any rejected signed network POST.
  let retainedNetworkDraft=Boolean(editor.querySelector('.grtrans-record-error'));
  editor.hidden=!(formId>0 || retainedNetworkDraft);
  const objectNames={stations:'station',legs:'transport connection',transfers:'station transfer',
    branches:'dispatch location',branch_stock:'product availability'};
  const editHeading=editor.querySelector('h4');
  if(editHeading&&formEntity)editHeading.textContent=(formId>0?'Edit ':'Add ')+(objectNames[formEntity]||'record');
  const modeButtons = new Map();
  const toolsGroup = node('div','grtrans-mode-buttons');
  const modes = [
    ['select','near_me','Select'], ['place','add_location_alt','Place station'],
    ['move','open_with','Move station'], ['connect-leg','timeline','Connect stations'],
    ['connect-transfer','device_hub','Add transfer'], ['preview','route','Preview route'],
    ['draw','polyline','Draw area'], ['edit-coverage','edit_location','Edit coverage']
  ];
  for (const [key,icon,label] of modes) {
    const button=node('button','btn btn-default grtrans-map-mode');
    button.type='button';button.dataset.grtransMode=key;
    button.title=label;button.setAttribute('aria-label',label);button.setAttribute('aria-pressed','false');
    button.append(text('span',icon,'material-icons'),text('span',label,'grtrans-map-mode-caption'));
    if (!model.modesEnabled(key)) {button.disabled=true;button.title=label+' — available in map editing stage 2';}
    toolsGroup.append(button);modeButtons.set(key,button);
    button.addEventListener('click',()=>switchMode(key));
  }
  tools.prepend(toolsGroup);
  const actions=node('div','grtrans-map-draft-actions');actions.hidden=true;
  const centreButton=text('button','Use map centre','btn btn-default btn-sm');centreButton.type='button';
  const nudgeLabel=text('span','Move tentative position:','sr-only');actions.append(centreButton,nudgeLabel);
  const moves=[['ArrowUp','north','keyboard_arrow_up'],['ArrowDown','south','keyboard_arrow_down'],
    ['ArrowLeft','west','keyboard_arrow_left'],['ArrowRight','east','keyboard_arrow_right']];
  for (const [key,label,icon] of moves) {
    const b=node('button','btn btn-default btn-sm');b.type='button';b.title='Move '+label;
    b.setAttribute('aria-label','Move tentative point '+label);b.append(text('span',icon,'material-icons'));
    b.addEventListener('click',event=>nudge(key,event.shiftKey));actions.append(b);
  }
  const cancel=text('button','Cancel draft','btn btn-default btn-sm');cancel.type='button';
  cancel.addEventListener('click',()=>switchMode('select'));actions.append(cancel);
  const expand=node('button','btn btn-default grtrans-map-expand');expand.type='button';
  expand.title='Expand or exit fullscreen map';expand.setAttribute('aria-label','Expand or exit fullscreen map');
  expand.append(text('span','fullscreen','material-icons'),text('span','Expand','grtrans-map-mode-caption'));
  tools.append(expand,actions);
  const notice = (message,error = false) => {
    newStatus.textContent=message;
    feedback.textContent=error ? message : '';
    feedback.dataset.state=error ? 'error' : '';
  };
  function getMapPoint() {const p=bridge.map.getCenter();return {lat:p.lat,lng:p.lng};}
  const pointFields=()=>[form?.querySelector('[name="lat"]'),form?.querySelector('[name="lng"]')];
  const matchingEditor=(entity,id)=>form && formEntity===entity && formId===id;
  const fromJSON=(value)=>{try{return JSON.parse(value);}catch(_){return null;}};
  const networkRecord=(entity,id)=>{
    const target=(recordData[entity]||[]).find(r=>model.recordId(entity,r)===Number(id));
    if(target)return bridge.rawRecords?.[entity]?.find(r=>model.recordId(entity,r)===Number(id))||target;
    const row=listDock.querySelector('[data-grtrans-entity="'+entity+'"] tr[data-grtrans-record-id="'+id+'"]');
    return fromJSON(row?.dataset.grtransRecord)||null;
  };
  function editNetwork(entity,id=0,nextMode=mode) {
    if(!matchingEditor(entity,formId)){
      if(id===0){try{sessionStorage.setItem(pendingAddKey,entity);}catch(_){}}
      visit(entity,id,nextMode);return false;
    }
    // Reopening a failed server-rendered edit must preserve its submitted values.
    if(id===formId && retainedNetworkDraft) {
      editor.hidden=false;if(routeEditor)routeEditor.hidden=true;editorDock.hidden=false;
      showContext();return true;
    }
    if(retainedNetworkDraft) {
      // A different record does not inherit the previous record's error chrome.
      editor.querySelector('.grtrans-record-error')?.remove();retainedNetworkDraft=false;
    }
    const values=id>0?networkRecord(entity,id):recordData.fieldDefaults?.[entity];
    if(!values){notice('Record not in the current dataset. Reload before editing.',true);return false;}
    formId=Number(id);form.querySelector('[name="network_id"]').value=String(id);
    const specs=recordData.fieldSpecs?.[entity]||{};
    for(const field of Object.keys(specs)){
      const input=form.elements.namedItem(field);if(!input)continue;
      const value=values[field]==null?'':String(values[field]);
      if(input.type==='checkbox')input.checked=value==='1';else input.value=value;
    }
    editor.querySelector('h4').textContent=(id?'Edit ':'Add ')+(objectNames[entity]||entity)+(id?' #'+id:'');
    // The server renders Create/Save and the ready-state label for its initial mode.
    // In-place editing must synchronize that chrome with the signed network_id.
    const submit=form.querySelector('button[name="grtrans_network_submit"]');
    if(submit)submit.textContent=id>0?'Save changes':'Create record';
    const readyState=editor.querySelector('[data-grtrans-network-state]');
    if(readyState) {
      const readyLabel=id>0?'No changes':'Ready to create';
      readyState.dataset.readyLabel=readyLabel;
      readyState.dataset.state='ready';readyState.textContent=readyLabel;
    }
    const networkUrl=new URL(form.action,location.href);
    networkUrl.searchParams.set('network_entity',entity);
    if(id>0)networkUrl.searchParams.set('network_edit',String(id));
    else networkUrl.searchParams.delete('network_edit');
    networkUrl.hash='grtrans-network-editor';form.action=networkUrl.toString();
    editor.hidden=false;if(routeEditor)routeEditor.hidden=true;editorDock.hidden=false;
    stage.dispatchEvent(new CustomEvent('grtrans:map-editor-record',
      {detail:{entity,id:Number(id)},bubbles:true}));
    selected=id>0?{entity,id:Number(id)}:null;
    showContext();return true;
  }
  function editRoute(id=0){
    if(!routeForm||!routeEditor)return false;
    const table=root.querySelector('#grtrans-routes .grtrans-route-grid table');
    const schema=fromJSON(table?.dataset.grtransCrmSchema)||{};
    const row=id>0?root.querySelector('#grtrans-routes tr[data-grtrans-record-id="'+id+'"]'):null;
    const record=id>0?fromJSON(row?.dataset.grtransRecord):
      Object.fromEntries(Object.entries(schema).map(([key,spec])=>[key,spec.default]));
    if(!record){notice('Route not found. Refresh the list.',true);return false;}
    for(const [field,value] of Object.entries(record)){
      if(!Object.hasOwn(schema,field))continue;
      const input=routeForm.elements.namedItem(field);if(!input)continue;
      if(input.type==='checkbox')input.checked=Number(value)===1;
      else input.value=value==null?'':String(value);
    }
    let proof=routeForm.querySelector('[name="grtrans_edit_proof"]');
    const priorRouteId=Number(new URL(routeForm.action,location.href).searchParams.get('grtrans_edit')||0);
    if(id>0){
      if(!proof){proof=node('input');proof.type='hidden';proof.name='grtrans_edit_proof';routeForm.append(proof);}
      proof.value=row?.dataset.grtransRouteProof||'';
    }else proof?.remove();
    const url=new URL(routeForm.action,location.href);
    url.searchParams.delete('grtrans_edit');if(id>0)url.searchParams.set('grtrans_edit',String(id));
    url.hash='grtrans-route-editor';routeForm.action=url.toString();
    routeEditor.querySelector('h3').textContent=id?'Editing route #'+id:'Add route';
    const routeSubmit=routeForm.querySelector('button[name="grtrans_save"]');
    if(routeSubmit)routeSubmit.textContent=id?'Save route changes':'Add route';
    if(priorRouteId!==Number(id)) {
      // The server's edit-only notice, verification note, and failure details
      // describe the previous record and must not leak into the newly opened one.
      routeEditor.querySelector(':scope > .alert-info')?.remove();
      routeEditor.querySelector('#grtrans-form-errors')?.remove();
      routeForm.querySelector(':scope > p')?.remove();
    }
    routeEditor.classList.toggle('is-editing',id>0);
    routeEditor.hidden=false;editor.hidden=true;editorDock.hidden=false;
    history.replaceState(history.state,'',location.pathname+location.search+'#grtrans-route-editor');
    routeEditor.scrollIntoView({block:'nearest'});
    selected=null;showContext();return true;
  }
  const saveView=()=>{
    const centre=getMapPoint();const view={lat:centre.lat,lng:centre.lng,zoom:bridge.map.getZoom()};
    try {sessionStorage.setItem(storedKey,JSON.stringify(view));} catch(_) { /* optional */ }
  };
  function visit(entity,id=0,nextMode=mode) {
    // A marker gesture can reach selection and editing handlers synchronously.
    // The first navigation owns this page; later requests must not supersede it.
    if (navigationPending) return;
    navigationPending = true;
    saveView();
    try {
      sessionStorage.setItem(modeKey,nextMode);
      if(id===0 && nextMode==='place-area')sessionStorage.setItem(pendingAddKey,entity);
    } catch(_) { /* optional */ }
    const url=new URL(location.href);
    url.searchParams.set('network_entity',entity);
    if (id > 0) url.searchParams.set('network_edit',String(id));
    else url.searchParams.delete('network_edit');
    url.hash='grtrans-map-workspace';
    location.assign(url.toString());
  }
  function restoreView() {
    try {
      const v=JSON.parse(sessionStorage.getItem(storedKey)||'null');
      if (v && model.validPoint(v) && Number.isFinite(Number(v.zoom))) {
        bridge.map.setView([v.lat,v.lng],Math.max(2,Math.min(19,v.zoom)),{animate:false});
      }
    } catch(_) { /* ignored */ }
  }
  let highlightGeneration=0;
  function highlight(entity,id,pan = true) {
    const generation=++highlightGeneration;
    if (selectionOverlay) {bridge.map.removeLayer(selectionOverlay);selectionOverlay=null;}
    const origin=model.originFor(recordData,entity,id);
    if (origin>0) {
      bridge.focusStation(origin);
      const station=bridge.station(origin);
      if (station && pan) bridge.map.panTo([Number(station.lat),Number(station.lng)],{animate:false});
    } else bridge.clearFocus();
    if (entity==='legs'||entity==='transfers') {
      const record=(recordData[entity]||[]).find(r=>model.recordId(entity,r)===Number(id));
      const from=record && bridge.station(record.from_station_id);
      const to=record && bridge.station(record.to_station_id);
      if(from&&to&&record?.mode!=='flight'&&record?.mode!=='ferry') {
        const saved=record?.route_geojson ? window.GrtransCoverage?.routePaths?.(record.route_geojson) : null;
        const path=saved?.length ? Promise.resolve(saved) : bridge.roadRoute(
          {lat:Number(from.lat),lng:Number(from.lng)},{lat:Number(to.lat),lng:Number(to.lng)}
        ).then(result=>[result.points]);
        path.then(paths=>{
          if(generation!==highlightGeneration)return;
          selectionOverlay=L.polyline(paths,{
            color:'#0a4fa1',weight:5,opacity:.8,dashArray:'5 5',interactive:false,
            className:'grtrans-map-selection-overlay'
          }).addTo(bridge.map);
        }).catch(error=>{
          if(generation===highlightGeneration)notice('Road selection unavailable: '+error.message,true);
        });
      }
    }
  }
  function activateRow(entity,id) {
    if (!entities.includes(entity)||id<=0) return;
    if (!lists.some(list=>list.dataset.grtransEntity===entity)) {
      // The bridge already has all active records. Selecting a different type
      // is contextual; only opening its server form needs tab navigation.
      selected={entity,id};highlight(entity,id);showContext();return;
    }
    setActive(entity);
    const section=listDock.querySelector('[data-grtrans-entity="'+entity+'"]');
    const row=[...(section?.querySelectorAll('tr[data-grtrans-record-id]')||[])].find(r=>Number(r.dataset.grtransRecordId)===Number(id));
    const button=row?.querySelector('[data-grtrans-select-record]');
    if (button && !button.checked) button.click();
    else { selected={entity,id};highlight(entity,id);showContext(); }
  }
  function setActive(entity) {
    if (!entities.includes(entity)) return;
    // Missing categories are fetched through normal server navigation.
    if (!lists.some(list=>list.dataset.grtransEntity===entity)) {
      visit(entity,0,'select');return;
    }
    activeEntity=entity;
    for (const list of lists) list.hidden=list.dataset.grtransEntity!==entity;
    for (const link of tabs) {
      const linked=new URL(link.href,location.href).searchParams.get('network_entity');
      const yes=linked===entity;link.setAttribute('aria-current',String(yes));link.classList.toggle('grtrans-active-tab',yes);
    }
    editorDock.hidden=editor.hidden && (!routeEditor || routeEditor.hidden);
    let reopenAdd=false;
    try {
      reopenAdd=sessionStorage.getItem(pendingAddKey)===entity;
      if(reopenAdd)sessionStorage.removeItem(pendingAddKey);
    } catch(_) { /* storage is optional */ }
    // Plain tab navigation stays read-only; only explicit Add opens the dock.
    if(reopenAdd && entity===formEntity && formId===0 && !retainedNetworkDraft){
      editNetwork(entity,0,'select');return;
    }
    showContext();
  }
  function showContext() {
    context.replaceChildren();
    if (selected && (activeEntity==='stations'||activeEntity==='branches')) {
      const stationId=selected.entity==='stations' ? selected.id
        : selected.entity==='branches' ? model.originFor(recordData,'branches',selected.id) : 0;
      const s=bridge.station(stationId);
      if(s&&Number(s.is_origin_hub)===1){
        const add=text('button','Add dispatch location here','btn btn-default btn-sm');add.type='button';
        add.addEventListener('click',()=>{
          try{sessionStorage.setItem('grtrans-map-selected-station',String(s.id_station));}catch(_){}
          visit('branches',0,'select');
        });
        context.append(add);
      }
    }
    if (selected?.entity==='branches' && activeEntity==='branches') {
      const stock=text('button','Manage products at this location','btn btn-default btn-sm');stock.type='button';
      stock.addEventListener('click',()=>{stockFilter=selected.id;try{sessionStorage.setItem('grtrans-map-selected-branch',String(stockFilter));}catch(_){}setActive('branch_stock');filterStock();});
      context.append(stock);
    }
    if (form && !editorDock.hidden) {
      const id=Number(form.querySelector('[name="network_id"]')?.value||0);
      const target=text('p',id>0?'Editing record #'+id+'. Save changes when ready.':'Adding a new record. Select a map tool or complete the fields.','grtrans-map-editor-hint');
      context.append(target);
    } else {
      const add=text('button','Add '+activeEntity.replace('_',' '),'btn btn-default btn-sm');add.type='button';
      add.addEventListener('click',()=>editNetwork(activeEntity,0,'select'));context.append(add);
    }
    window.GrtransMapContext?.render(selected,context);
  }
  function filterStock() {
    const section=listDock.querySelector('[data-grtrans-entity="branch_stock"]');
    if (!section||!stockFilter) return;
    const rows=section.querySelectorAll('tr[data-grtrans-record-id]');
    rows.forEach(row=>{const branch=Number(row.cells[1]?.textContent.trim());row.hidden=branch!==stockFilter;});
    const filter=text('p','Showing products at dispatch location #'+stockFilter+'.','grtrans-map-filter-note');
    const clear=text('button','Show all products','btn btn-default btn-sm');clear.type='button';
    clear.addEventListener('click',()=>{stockFilter=0;rows.forEach(row=>row.hidden=false);filter.remove();});
    filter.append(clear);section.prepend(filter);
  }
  function revertDraft() {
    if (draftMarker) {bridge.map.removeLayer(draftMarker);draftMarker=null;}
    if (dragMarker) {
      dragMarker.dragging?.disable();
      if (originalPosition) dragMarker.setLatLng(originalPosition);
      dragMarker=null;
    }
    originalPosition=null;firstStation=0;
    const alert=editor.querySelector('.grtrans-map-unsaved');alert?.remove();
    if (originalFields) {
      const [lat,lng]=pointFields();
      if(lat)lat.value=originalFields[0];
      if(lng)lng.value=originalFields[1];
      if(lat)lat.dispatchEvent(new Event('input',{bubbles:true}));
      if(lng)lng.dispatchEvent(new Event('input',{bubbles:true}));
    }
    originalFields=null;
    if (originalPair) {
      const from=form?.querySelector('[name="from_station_id"]');
      const to=form?.querySelector('[name="to_station_id"]');
      if(from) {from.value=originalPair[0];from.dispatchEvent(new Event('change',{bubbles:true}));}
      if(to) {to.value=originalPair[1];to.dispatchEvent(new Event('change',{bubbles:true}));}
    }
    originalPair=null;
  }
  function draft(point) {
    const p=model.normalizedPoint(point);
    if (!p||!matchingEditor('stations',mode==='move'?formId:0)) {
      notice('Choose a valid station position. No changes were saved.',true);return;
    }
    const [lat,lng]=pointFields();if(!lat||!lng)return;
    if(!originalFields)originalFields=[lat.value,lng.value];
    lat.value=p.lat.toFixed(7);lng.value=p.lng.toFixed(7);
    lat.dispatchEvent(new Event('input',{bubbles:true}));lng.dispatchEvent(new Event('input',{bubbles:true}));
    if (mode==='place') {
      if(!draftMarker) {
        draftMarker=L.marker([p.lat,p.lng],{draggable:true,opacity:.7,title:'Unsaved station position'}).addTo(bridge.map);
        draftMarker.on('dragend',()=>{if(mode==='place'&&draftMarker)draft(draftMarker.getLatLng());});
      } else draftMarker.setLatLng([p.lat,p.lng]);
    } else if(dragMarker) dragMarker.setLatLng([p.lat,p.lng]);
    if (!editor.querySelector('.grtrans-map-unsaved')) {
      const callout=text('p','Position draft — not saved. Save the station, or Cancel draft to revert.','grtrans-map-unsaved');
      form?.prepend(callout);
    }
    notice('Position draft at '+p.lat.toFixed(5)+', '+p.lng.toFixed(5)+'. Save to commit.');
  }
  function nudge(key,fine) {
    if(mode!=='place'&&mode!=='move')return;
    const current=(mode==='place'&&draftMarker?.getLatLng()) || (mode==='move'&&dragMarker?.getLatLng()) || getMapPoint();
    const next=model.nudge(current,key,fine);
    if(next){draft(next);bridge.map.panTo([next.lat,next.lng],{animate:false});}
  }
  function enableMove(id) {
    if(!matchingEditor('stations',id)){visit('stations',id,'move');return;}
    revertDraft();mode='move';
    const marker=bridge.marker(id);
    if(!marker){notice('The selected station is not on this map.',true);return;}
    dragMarker=marker;originalPosition=marker.getLatLng();
    marker.dragging?.enable();
    const finish=()=>{if(mode==='move'&&dragMarker===marker)draft(marker.getLatLng());};
    marker.off('dragend',marker._grtransStageDrag);
    marker._grtransStageDrag=finish;marker.on('dragend',finish);
    notice('Drag the selected station pin, or use the arrow buttons. Save to commit.');
  }
  function switchMode(next) {
    if (!model.modesEnabled(next)) return;
    if (window.GrtransMapSpatialEditor?.beforeSwitch?.(next) === false) return;
    if(mode==='preview') bridge.clearPreview();
    revertDraft();mode=next;working=false;
    if(next!=='select') bridge.clearLegacy();
    for (const [key,button] of modeButtons) button.setAttribute('aria-pressed',String(key===next));
    actions.hidden=next!=='place'&&next!=='move';
    if(next==='place'&&!matchingEditor('stations',0)){visit('stations',0,'place');return;}
    if(next==='connect-leg'&&!matchingEditor('legs',0)){visit('legs',0,'connect-leg');return;}
    if(next==='connect-transfer'&&!matchingEditor('transfers',0)){visit('transfers',0,'connect-transfer');return;}
    if(next==='move'&&selected?.entity==='stations'){enableMove(selected.id);return;}
    if(next==='preview'){bridge.startPreview();notice('Choose two map points or station pins to preview a route.');return;}
    const labels={select:'Select a station, line, or list record.',place:'Choose a map point to place a new station.',
      move:'Select the station you wish to move.',
      'connect-leg':'Click a sending station, then a receiving station.',
      'connect-transfer':'Click the first station, then the second station.'};
    notice(labels[next]||'Select a map action.');
    window.GrtransMapSpatialEditor?.afterSwitch?.(next);
  }
  function stationPick(station) {
    const id=Number(station.id_station);
    if(mode==='preview')return false;
    if(mode==='place'){notice('Click empty map space to place the new station.');return true;}
    if(mode==='move'){
      // From another dataset, activateRow and enableMove would each navigate.
      // Go directly to the station editor once, preserving the Move intent.
      if(!matchingEditor('stations',id)){visit('stations',id,'move');return true;}
      selected={entity:'stations',id};activateRow('stations',id);enableMove(id);return true;
    }
    if(mode==='connect-leg'||mode==='connect-transfer'){
      const entity=mode==='connect-leg'?'legs':'transfers';
      if(!matchingEditor(entity,0)){visit(entity,0,mode);return true;}
      if(!firstStation){firstStation=id;bridge.focusStation(id);notice('From '+station.name+'. Select a different destination station.');return true;}
      const ids=model.pair(firstStation,id);
      if(!ids){notice('Choose a different destination station.',true);return true;}
      const source=form.querySelector('[name="from_station_id"]'),target=form.querySelector('[name="to_station_id"]');
      if(source&&target){
        if(!originalPair)originalPair=[source.value,target.value];
        source.value=String(ids.from_station_id);target.value=String(ids.to_station_id);
        source.dispatchEvent(new Event('change',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));
        bridge.focusStations([ids.from_station_id,ids.to_station_id]);
        notice('Connection draft: #'+ids.from_station_id+' → #'+ids.to_station_id+'. Set the method and price, then save.');
        const price=form.querySelector('[name="price"]');price?.focus();
        const msg=text('p','Connection draft — no new record exists until you save.','grtrans-map-unsaved');
        form.querySelector('.grtrans-map-unsaved')?.remove();form.prepend(msg);
      }
      firstStation=0;return true;
    }
    if(activeEntity==='stations'){activateRow('stations',id);return false;}
    const options=model.recordsAt(recordData,activeEntity,id);
    if(activeEntity==='branches'&&options.length===0&&Number(station.is_origin_hub)===1){
      selected={entity:'stations',id};showContext();
      const add=text('button','Add dispatch location at '+station.name,'btn btn-default btn-sm');
      add.type='button';add.addEventListener('click',()=>{try{sessionStorage.setItem('grtrans-map-selected-station',String(id));}catch(_){}visit('branches',0,'select');});
      context.append(add);return true;
    }
    if(options.length===1){activateRow(activeEntity,model.recordId(activeEntity,options[0]));return true;}
    if(options.length>1){showChoices(activeEntity,options,markerPosition(id));return true;}
    notice('No '+activeEntity.replace('_',' ')+' record is linked to '+station.name+'.');return true;
  }
  const markerPosition = id => bridge.station(id) ? [Number(bridge.station(id).lat),Number(bridge.station(id).lng)] : null;
  function showChoices(entity,records,latlng) {
    const box=node('div','grtrans-map-choice-list');
    box.append(text('strong','Choose a '+entity.replace('_',' ')+' record'));
    for(const record of records){
      const id=model.recordId(entity,record);
      const title=record.name||record.operator_label||record.connection_type||('Record #'+id);
      const button=text('button',title+' (#'+id+')','btn btn-default btn-sm');button.type='button';
      button.addEventListener('click',()=>{bridge.map.closePopup();activateRow(entity,id);});box.append(button);
    }
    L.popup({maxWidth:310}).setLatLng(latlng||bridge.map.getCenter()).setContent(box).openOn(bridge.map);
  }
  function mapClick(latlng) {
    if(mode==='preview')return false;
    if(mode==='place'){draft(latlng);return true;}
    if(mode==='move'){notice('Select or drag the station pin to move it.');return true;}
    if(mode.startsWith('connect')){notice('Choose two station pins, not empty map space.');return true;}
    return true; // Select mode never mutates coordinate inputs on bare map clicks.
  }
  window.GrtransMapWorkspaceHooks={stationPick,mapClick};
  root.addEventListener('grtrans:record-selected',event=>{
    const {entity,id}=event.detail||{};
    if(!entities.includes(entity)||!(Number(id)>0))return;
    selected={entity,id:Number(id)};highlight(entity,Number(id));showContext();
  });
  // Stock uses the same signed inline autosave helper as all CRM table cells.
  root.addEventListener('grtrans:record-selected',event=>{
    const entity=event.detail?.entity,id=Number(event.detail?.id);
    if(entities.includes(entity) && id>0) {
      selected={entity,id};highlight(entity,id);showContext();
    } else if(selected?.entity===entity) {
      selected=null;bridge.clearFocus();showContext();
    }
    if(entity==='branch_stock'&&id>0)window.GrtransCRM?.openEditor('branch_stock',id,'quantity');
  });
  window.addEventListener('grtrans:map-station-selected',event=>{
    if(mode==='select'&&activeEntity==='stations')activateRow('stations',Number(event.detail?.id));
  });
  window.addEventListener('grtrans:map-record-selected',event=>{
    const {entity,id}=event.detail||{};if(entities.includes(entity)&&Number(id)>0)activateRow(entity,Number(id));
  });
  for(const link of tabs)link.addEventListener('click',event=>{
    const entity=new URL(link.href,location.href).searchParams.get('network_entity');
    if(!entities.includes(entity))return;
    if(!lists.some(list=>list.dataset.grtransEntity===entity)) {
      saveView();return; // Load the selected category through its server link.
    }
    event.preventDefault();if(mode!=='select')switchMode('select');setActive(entity);
    const url=new URL(location.href);url.searchParams.set('network_entity',entity);
    history.replaceState(history.state,'',url.toString());
  });
  for(const link of editorDock.querySelectorAll('a[href*="network_entity="]')){
    link.addEventListener('click',saveView);
  }
  for(const link of listDock.querySelectorAll('a[href*="network_entity="]')){
    const url=new URL(link.href,location.href);
    url.hash='grtrans-map-workspace';link.href=url.toString();link.addEventListener('click',saveView);
  }
  const fieldStation=form?.querySelector('[name="origin_station_id"]');
  if(formEntity==='branches'&&formId===0&&fieldStation){
    try {const prior=sessionStorage.getItem('grtrans-map-selected-station');
      if(prior&&[...fieldStation.options].some(option=>option.value===prior))fieldStation.value=prior;
      sessionStorage.removeItem('grtrans-map-selected-station');}catch(_){}
  }
  if(formEntity==='branch_stock'&&formId===0){
    try {const prior=sessionStorage.getItem('grtrans-map-selected-branch');
      const branch=form?.querySelector('[name="id_branch"]');
      if(prior&&branch&&[...branch.options].some(option=>option.value===prior))branch.value=prior;
      sessionStorage.removeItem('grtrans-map-selected-branch');}catch(_){}
  }
  centreButton.addEventListener('click',()=>draft(getMapPoint()));
  mapRoot.setAttribute('tabindex','0');
  mapRoot.setAttribute('aria-label','Service map. Use the toolbar to choose Select, Place, Move or Connect.');
  mapRoot.addEventListener('keydown',event=>{
    if(event.key==='Enter'&&(mode==='place'||mode==='move')){event.preventDefault();draft(getMapPoint());}
  });
  stage.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&mode!=='select') {event.preventDefault();switchMode('select');}
    else if(event.key==='Escape'&&stage.classList.contains('grtrans-map-maximized')){
      stage.classList.remove('grtrans-map-maximized');bridge.map.invalidateSize();
    }
    if(mode==='move'&&event.key.startsWith('Arrow') && event.target.closest('.grtrans-map-workspace-tools')) {
      event.preventDefault();nudge(event.key,event.shiftKey);
    }
  });
  expand.addEventListener('click',()=>{
    if(document.fullscreenElement===stage){document.exitFullscreen?.();return;}
    if(stage.requestFullscreen)stage.requestFullscreen().catch(()=>stage.classList.toggle('grtrans-map-maximized'));
    else stage.classList.toggle('grtrans-map-maximized');
    setTimeout(()=>bridge.map.invalidateSize(),80);
  });
  document.addEventListener('fullscreenchange',()=>{
    expand.setAttribute('aria-pressed',String(document.fullscreenElement===stage));
    bridge.map.invalidateSize();
  });
  const resize=()=>bridge.map.invalidateSize();
  if(typeof ResizeObserver==='function')new ResizeObserver(resize).observe(mapDock);
  for(const entity of entities){
    const list=listDock.querySelector('[data-grtrans-entity="'+entity+'"]');
    list?.querySelectorAll('a[href*="network_edit="]').forEach(link=>{const url=new URL(link.href,location.href);
      url.hash='grtrans-map-workspace';link.href=url.toString();link.addEventListener('click',saveView);});
  }
  // A transparent record hit target adds selection without altering coverage
  // or the persisted geometry/transport paint model. Shared endpoints get one
  // disambiguation picker instead of choosing a random record.
  const targets = new Map();
  for(const entity of ['legs','transfers'])for(const record of recordData[entity]||[]) {
    const a=bridge.station(record.from_station_id),b=bridge.station(record.to_station_id);
    if(!a||!b)continue;
    const key=[Number(a.id_station),Number(b.id_station)].sort((x,y)=>x-y).join(':');
    if(!targets.has(key))targets.set(key,[]);
    targets.get(key).push({entity,record});
  }
  for(const [key,records] of targets){
    const [a,b]=key.split(':').map(id=>bridge.station(id));if(!a||!b)continue;
    const hit=L.polyline([[Number(a.lat),Number(a.lng)],[Number(b.lat),Number(b.lng)]],{
      color:'#111',opacity:.001,weight:15,interactive:true,className:'grtrans-map-record-hit'}).addTo(bridge.map);
    hit.on('click',event=>{
      L.DomEvent.stopPropagation(event);
      if(mode==='preview') {bridge.map.fire('click',{latlng:event.latlng});return;}
      if(mode!=='select') {notice('Finish or cancel the active map tool before selecting a connection.');return;}
      const available=records.filter(item=>item.entity===activeEntity);
      const choices=available.length?available:records;
      if(choices.length===1){activateRow(choices[0].entity,model.recordId(choices[0].entity,choices[0].record));return;}
      const box=node('div','grtrans-map-choice-list');box.append(text('strong','Select a connection'));
      for(const item of choices){const id=model.recordId(item.entity,item.record);
        const button=text('button',(item.record.operator_label||item.record.connection_type||item.entity)+' #'+id,'btn btn-default btn-sm');
        button.type='button';button.addEventListener('click',()=>{bridge.map.closePopup();activateRow(item.entity,id);});box.append(button);}
      L.popup({maxWidth:310}).setLatLng(event.latlng).setContent(box).openOn(bridge.map);
    });
  }
  restoreView();
  let initialMode='select';
  try {initialMode=sessionStorage.getItem(modeKey)||'select';sessionStorage.removeItem(modeKey);}catch(_){}
  if(!model.modesEnabled(initialMode))initialMode='select';
  setActive(activeEntity);
  if(formId>0)activateRow(activeEntity,formId);
  switchMode(initialMode);
  if(formEntity==='stations'&&formId>0&&initialMode==='move'&&!dragMarker)enableMove(formId);
  // Failed POSTs retain the previous submitted position in the server-rendered
  // form. Show it as a *draft*, never as persisted station coverage.
  if(formEntity==='stations' && form && editor.querySelector('.grtrans-record-error')) {
    const [lat,lng]=pointFields();
    const p=model.normalizedPoint({lat:lat?.value,lng:lng?.value});
    const saved=formId>0?bridge.station(formId):null;
    if(p&&(!saved||Math.abs(Number(saved.lat)-p.lat)>0.0000001||Math.abs(Number(saved.lng)-p.lng)>0.0000001)) {
      draftMarker=L.marker([p.lat,p.lng],{opacity:.7,title:'Not saved — submitted station position'}).addTo(bridge.map);
      const clear=text('button','Discard unsaved position','btn btn-default btn-sm');clear.type='button';
      clear.addEventListener('click',()=>{
        if(draftMarker){bridge.map.removeLayer(draftMarker);draftMarker=null;}
        if(lat)lat.value=saved?String(saved.lat):'';
        if(lng)lng.value=saved?String(saved.lng):'';
        clear.remove();notice('Unsaved position discarded; other entered fields remain.');
      });
      editor.querySelector('.grtrans-record-error')?.append(clear);
      notice('The station change was not saved. Correct the retained draft or discard its position.',true);
    }
  }
  // The map is reparented into its dock during startup. The stage exists
  // before that layout and Leaflet's size reconciliation have completed.
  // Announce readiness only after the dock has its final map dimensions.
  requestAnimationFrame(()=>{
    bridge.map.invalidateSize();
    // Category navigation has no record focus; preserve the exact view stored
    // before navigation after Leaflet has accounted for the new container size.
    // Editing a record intentionally focuses that record, so keep its camera.
    if(formId===0)restoreView();
    stage.dataset.grtransViewReady='true';
  });
  // A new station must be a new signed record before its coordinates are drafted.
  // Preparing the existing form in the same tab avoids the old place→navigate
  // race that discarded the pending coordinates when no editor matched.
  toolsGroup.addEventListener('click',event=>{
    const button=event.target.closest?.('[data-grtrans-mode]');if(!button)return;
    const requested=button.dataset.grtransMode;
    if(requested==='place')editNetwork('stations',0,'place');
    else if(requested==='connect-leg'||requested==='connect-transfer')
      editNetwork(requested==='connect-leg'?'legs':'transfers',0,requested);
    else if(requested==='move'&&selected?.entity==='stations')editNetwork('stations',selected.id);
    else if(['draw','edit-coverage','radius','edit-route'].includes(requested)){
      const target=selected?.entity||formEntity;
      if(target==='stations'||target==='legs')editNetwork(target,selected?.entity===target?selected.id:0);
    }else if(requested==='place-area')editNetwork('legs',0);
  },true);
  // Capture before Leaflet patch/pin/line handlers. Placement is coordinates
  // only, even on polygons or markers; controls retain their normal actions.
  mapRoot.addEventListener('click',event=>{
    if(mode!=='place'||event.target.closest?.('.leaflet-control'))return;
    const point=bridge.map.mouseEventToLatLng(event);
    event.stopImmediatePropagation();event.preventDefault();draft(point);
  },true);
  root.addEventListener('click',event=>{
    const link=event.target.closest?.('a[href]');if(!link)return;
    if(link.closest('#grtrans-routes')&&new URL(link.href,location.href).searchParams.has('grtrans_edit')){
      event.preventDefault();editRoute(Number(new URL(link.href,location.href).searchParams.get('grtrans_edit')));
    }else if(link.closest('.grtrans-network-list')&&new URL(link.href,location.href).searchParams.has('network_edit')){
      event.preventDefault();const url=new URL(link.href,location.href);
      editNetwork(url.searchParams.get('network_entity'),Number(url.searchParams.get('network_edit')));
    }
  },true);
  const addRoute=text('button','Add priced route','btn btn-default grtrans-map-mode');
  addRoute.type='button';addRoute.title='Add route in the map panel';
  addRoute.addEventListener('click',()=>editRoute(0));tools.append(addRoute);
  if(routeEditor&&root.querySelector('#grtrans-form-errors')){
    routeEditor.hidden=false;editor.hidden=true;editorDock.hidden=false;
  }
  window.GrtransMapWorkspace={stage,bridge,form,editor,tools,
    selectRecord:activateRow,selected:()=>selected,getMode:()=>mode,visit,changeMode:switchMode,
    editNetwork,editRoute,clearSelection:()=>{
      selected=null;bridge.clearFocus();if(selectionOverlay){bridge.map.removeLayer(selectionOverlay);selectionOverlay=null;}
      showContext();
    }};
})();
