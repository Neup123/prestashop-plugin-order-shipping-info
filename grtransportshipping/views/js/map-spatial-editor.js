/* Stage 2: disposable map-side editing overlays, never the persisted coverage renderer. */
(() => {
  'use strict';
  const workspace = window.GrtransMapWorkspace;
  const model = window.GrtransMapSpatialModel;
  const bridge = window.GrtransMapBridge;
  const L = window.L;
  if (!workspace || !model || !bridge || !L) return;
  const {stage,form,editor,tools,bridge:mapBridge} = workspace;
  const map = bridge.map;
  const entity = form?.querySelector('[name="network_entity"]')?.value || '';
  const recordId = Number(form?.querySelector('[name="network_id"]')?.value || 0);
  const field = name => form?.querySelector('[name="'+name+'"]') || null;
  const pointFor = () => entity === 'stations' ? ['lat','lng'] : ['area_lat','area_lng'];
  const coverageField = () => entity === 'stations' ? 'coverage_geojson' : 'area_coverage_geojson';
  const kindField = () => entity === 'stations' ? 'coverage_kind' : 'area_coverage_kind';
  const radiusField = extended => entity === 'stations' ? (extended ? 'outer_radius_km' : 'inner_radius_km')
    : (extended ? 'area_outer_radius_km' : 'area_radius_km');
  const shapeAllowed = () => entity === 'stations' || (entity === 'legs' && field('to_station_id')?.value === '');
  const hasAreaFields = () => entity === 'legs' && field('area_lat') && field('area_lng');
  const validCentre = () => {
    const [lat,lng] = pointFor();
    return model.point({lat:field(lat)?.value, lng:field(lng)?.value});
  };
  const labelFor = () => entity === 'stations' ? 'station' : 'direct-area service';
  const node = (tag, text, cls = '') => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  };
  const button = (name, action, title = name) => {
    const b = node('button',name,'btn btn-default btn-sm');b.type='button';b.title=title;
    b.setAttribute('aria-label',title);b.addEventListener('click',action);return b;
  };
  const dispatch = (input, kind='input') => input?.dispatchEvent(new Event(kind,{bubbles:true}));
  const panel = node('section',null,'grtrans-spatial-panel');
  panel.setAttribute('aria-label','Spatial draft editor');panel.hidden=true;
  const heading=node('strong','Map drawing and measurements');
  const summary=node('output','Choose Draw area or Edit coverage.','grtrans-spatial-summary');
  summary.setAttribute('role','status');summary.setAttribute('aria-live','polite');
  const state = node('div',null,'grtrans-spatial-actions');
  const opts = node('div',null,'grtrans-spatial-options');
  const vertexTools=node('div',null,'grtrans-spatial-vertex-tools');
  const help=node('p','Changes are tentative until the record Save button is used. Esc cancels and restores spatial fields.',
    'grtrans-spatial-help');
  const message=node('output','', 'grtrans-spatial-feedback');message.setAttribute('role','status');message.setAttribute('aria-live','polite');
  panel.append(heading,summary,state,opts,vertexTools,message,help);
  const dock=stage.querySelector('.grtrans-map-editor-dock');dock?.prepend(panel);
  const modeGroup=stage.querySelector('.grtrans-mode-buttons');
  const extraModes=[['place-area','add_location','Place service area'],['radius','radio_button_unchecked','Set reach'],
    ['edit-route','timeline','Edit route line']];
  const buttons=new Map();
  for(const [key,icon,label] of extraModes){
    const b=node('button',null,'btn btn-default grtrans-map-mode');b.type='button';b.dataset.grtransMode=key;
    b.setAttribute('aria-label',label);b.title=label;b.setAttribute('aria-pressed','false');
    b.append(node('span',icon,'material-icons'),node('span',label,'grtrans-map-mode-caption'));
    b.addEventListener('click',()=>workspace.changeMode(key));modeGroup?.append(b);buttons.set(key,b);
  }
  const isSpatial = key => ['draw','edit-coverage','radius','place-area','edit-route'].includes(key);
  const notes = (text,failed = false) => {
    summary.textContent=text;message.textContent=failed ? text : '';
    message.dataset.state=failed ? 'error' : '';
  };
  const allFields=['lat','lng','inner_radius_km','outer_radius_km','coverage_kind','coverage_geojson',
    'area_lat','area_lng','area_radius_km','area_outer_radius_km','area_coverage_kind','area_coverage_geojson',
    'route_geojson','from_station_id','to_station_id'];
  let baseline=null, history=[], changing=false, mode='select', activeShape=null, category='',
    shape=null, activeIsland=0, activeRing=0, selectedVertex=0, building=null, radiusTarget='normal',
    layers=[], controls=[], siteMarker=null, sourceId=0, failureDraft=false, polygonTypeSelection='', sourceError='';
  const mapCenter=()=>map.getCenter();
  const values=()=>Object.fromEntries(allFields.filter(name=>field(name)).map(name=>[name,field(name).value]));
  const applied = (name,value) => {
    const input=field(name);if(!input)return;
    input.value=value == null?'':String(value);dispatch(input);
    if(input.tagName==='SELECT')dispatch(input,'change');
  };
  // The compact list retains the server-rendered, shop-scoped stored values even
  // when an unsuccessful POST has overlaid rejected edits into the form.
  const persistedSpatial = () => {
    if(recordId<=0)return null;
    const section=stage.querySelector('section.grtrans-network-list[data-grtrans-entity="'+entity+'"]');
    const row=[...(section?.querySelectorAll('tr[data-grtrans-record-id]')||[])]
      .find(candidate=>Number(candidate.dataset.grtransRecordId)===recordId);
    if(!row)return null;
    const positions=entity==='stations' ? {
      lat:4,lng:5,inner_radius_km:6,outer_radius_km:7,coverage_kind:9,coverage_geojson:10
    } : entity==='legs' ? {
      from_station_id:1,to_station_id:2,area_lat:17,area_lng:18,area_radius_km:19,
      area_outer_radius_km:20,area_coverage_kind:21,area_coverage_geojson:22,route_geojson:23
    } : null;
    if(!positions)return null;
    const stored={};
    for(const [key,index] of Object.entries(positions)) {
      const value=row.cells[index]?.textContent.trim();
      if(value!==undefined)stored[key]=value==='—'?'':value;
    }
    return stored;
  };
  const applyFields = snapshot => {
    changing=true;
    for(const [name,value] of Object.entries(snapshot)) applied(name,value);
    changing=false;
  };
  const remember = () => { if (!baseline)baseline=values(); };
  const markUnsaved = () => {
    if(!form)return;
    if (!form.querySelector('.grtrans-spatial-unsaved')) {
      const msg=node('p','Spatial draft — not saved. Check the outline, then Save changes or Cancel draft.',
        'grtrans-map-unsaved grtrans-spatial-unsaved');
      form.prepend(msg);
    }
  };
  const snapshot = () => {remember();history.push(values());if(history.length>80)history.shift();markUnsaved();};
  const dropLayers = () => {
    for(const layer of layers)map.removeLayer(layer);
    layers=[];controls=[];siteMarker=null;
  };
  const forget = () => {baseline=null;history=[];shape=null;building=null;activeShape=null;sourceId=0;dropLayers();};
  const restore = () => {
    const saved=failureDraft?persistedSpatial():null;
    if(baseline)applyFields(baseline);
    if(saved)applyFields(saved);
    form?.querySelector('.grtrans-spatial-unsaved')?.remove();
    forget();notes('Spatial draft discarded. Saved network shapes were not changed.');
  };
  const undo = () => {
    if(!history.length){notes('No spatial step to undo.');return;}
    applyFields(history.pop());building=null;
    try{shape=model.open(field(activeShape)?.value,category);}catch(_){shape=null;}
    render();notes('Previous spatial step restored. Save to commit.');
  };
  const pointFromFields = () => validCentre() || (entity==='stations' && recordId>0
    ? model.point(bridge.station(recordId)) : null);
  const fieldCanEdit = () => Boolean(form) && (shapeAllowed() || (entity==='legs' && !shapeAllowed()));
  const actualShape = name => name==='route_geojson' ? 'line' : 'polygon';
  function currentGeometry(name) {
    sourceError='';
    try{const parsed=model.open(field(name)?.value,actualShape(name));
      if(parsed){const verdict=model.assess(parsed,actualShape(name));if(!verdict.ok)throw new Error(verdict.message);}
      return parsed;}
    catch(error){sourceError='Invalid existing shape: '+error.message+'. Use the Advanced raw editor to correct it.';notes(sourceError,true);return null;}
  }
  const pointsFor = () => {
    if(!shape)return [];
    return category==='polygon' ? model.polygons(shape)[activeIsland]?.[activeRing]||[]
      : model.lines(shape)[activeIsland]||[];
  };
  const renderShape = () => {
    if(!shape)return;
    if(category==='polygon') {
      for(const island of model.polygons(shape)){
        const paths=island.map(ring=>ring.map(p=>[p[1],p[0]]));
        layers.push(L.polygon(paths,{color:'#dc6c13',weight:2,fillOpacity:.11,interactive:false,
          className:'grtrans-spatial-edit-overlay'}).addTo(map));
      }
    } else {
      for(const line of model.lines(shape))layers.push(L.polyline(line.map(p=>[p[1],p[0]]),
        {color:'#c04e20',weight:4,dashArray:'6 4',interactive:false,
          className:'grtrans-spatial-edit-overlay'}).addTo(map));
    }
    const vertices=pointsFor();const last=category==='polygon'?vertices.length-1:vertices.length;
    for(let i=0;i<last;i++){
      const id=i;
      const marker=L.marker([vertices[i][1],vertices[i][0]],{draggable:true,
        title:'Edit '+(category==='polygon'?'coverage':'route')+' vertex '+(i+1),
        opacity:i===selectedVertex?1:.75}).addTo(map);
      marker.on('click',()=>{selectedVertex=id;render();});
      marker.on('dragend',()=>moveVertex(id,marker.getLatLng()));
      const elt=typeof marker.getElement==='function'?marker.getElement():null;
      if(elt){
        elt.tabIndex=0;elt.setAttribute('role','button');
        elt.setAttribute('aria-label','Vertex '+(i+1)+'. Arrow keys move; Enter selects.');
        elt.addEventListener('keydown',event=>{
          if(event.key==='Enter'||event.key===' '){event.preventDefault();selectedVertex=id;render();}
          if(event.key.startsWith('Arrow')){event.preventDefault();event.stopPropagation();nudgeVertex(id,event.key,event.shiftKey);}
        });
      }
      layers.push(marker);controls.push(marker);
    }
  };
  const renderBuilding = () => {
    if(!building)return;
    const coords=building.points.map(p=>[p.lat,p.lng]);
    if(coords.length>1) layers.push(L.polyline(coords,{color:'#d06a17',weight:3,dashArray:'4 5',
      interactive:false,className:'grtrans-spatial-pending'}).addTo(map));
    for(const p of building.points)layers.push(L.circleMarker([p.lat,p.lng],{radius:5,color:'#b04b12',
      interactive:false,className:'grtrans-spatial-pending'}).addTo(map));
  };
  const renderRadius = () => {
    const centre=pointFromFields();if(!centre){notes('Place the service area or station centre first.',true);return;}
    const inner=Number(field(radiusField(false))?.value||0);
    const outerInput=field(radiusField(true));const custom=outerInput?.value!=='';
    const defaultKm=Number(bridge.data.farDefaultKm||0);
    const outer=custom?Number(outerInput.value):Math.max(inner,defaultKm);
    for(const [key,km,shade] of [['normal',inner,'#cf681d'],['extended',outer,'#7661af']]){
      if(!Number.isFinite(km)||km<0)continue;
      layers.push(L.circle([centre.lat,centre.lng],{radius:km*1000,weight:2,color:shade,
        fillOpacity:.06,dashArray:'5 4',interactive:false,className:'grtrans-spatial-edit-overlay'}).addTo(map));
      if(key==='extended'&&!custom)continue; // Inheritance must not silently become an override.
      const p=model.eastHandle(centre,km);
      if(!p)continue;
      const marker=L.marker([p.lat,p.lng],{draggable:true,title:'Drag '+key+' reach handle'}).addTo(map);
      marker.on('dragend',()=>setRadius(key,marker.getLatLng()));
      const elt=typeof marker.getElement==='function'?marker.getElement():null;
      if(elt){elt.tabIndex=0;elt.setAttribute('role','button');elt.setAttribute('aria-label',key+' radius handle; arrows adjust by 0.1 km');
        elt.addEventListener('keydown',event=>{if(event.key.startsWith('Arrow')){event.preventDefault();
          const delta=(event.key==='ArrowDown'||event.key==='ArrowLeft'?-1:1)*(event.shiftKey?.01:.1);
          setRadius(key,Number(field(radiusField(key==='extended'))?.value||0)+delta);}});
      }
      layers.push(marker);controls.push(marker);
    }
  };
  const renderAreaPosition = () => {
    const centre=pointFromFields();if(!centre)return;
    const marker=L.marker([centre.lat,centre.lng],{draggable:true,opacity:.7,title:'Unsaved service area centre'}).addTo(map);
    marker.on('dragend',()=>setAreaCentre(marker.getLatLng()));
    layers.push(marker);siteMarker=marker;
  };
  function render() {
    dropLayers();if(!isSpatial(mode))return;
    if(mode==='radius')renderRadius();
    else if(mode==='place-area')renderAreaPosition();
    else {renderShape();renderBuilding();}
    showControls();
  }
  const setGeometry = next => {
    const verdict=model.assess(next,category);
    if(!verdict.ok){notes(verdict.message,true);return false;}
    const type=field(kindField());
    if(category==='polygon'&&type?.value==='radius'&&!polygonTypeSelection){
      notes('Choose the existing polygon service-area type before applying this shape.',true);return false;
    }
    snapshot();shape=next;
    if(category==='polygon'&&type?.value==='radius')applied(kindField(),polygonTypeSelection);
    applied(activeShape,JSON.stringify(next));building=null;render();
    notes('Geometry draft is valid ('+(category==='polygon'?'coverage':'route')+'). Save the record to commit.');
    return true;
  };
  const chooseShape = name => {
    activeShape=name;category=actualShape(name);shape=currentGeometry(name);
    building=null;activeIsland=0;activeRing=0;selectedVertex=0;
    render();
  };
  const addPoint = source => {
    const p=model.point(source);if(!p){notes('The point must have signed coordinates within WGS84 bounds.',true);return;}
    if(!building) {
      const first=category==='polygon'?'ring':'line';
      building={kind:first,target:'new',points:[]};
    }
    building.points.push(p);
    render();notes(building.points.length+' tentative '+(category==='polygon'?'ring':'line')+' vertices. Finish to validate.');
  };
  const beginPart = target => {
    if(!shape && target!=='new') {notes('Draw the first outer ring or route segment before adding another.',true);return;}
    building={kind:category==='polygon'?'ring':'line',target,points:[]};
    render();notes('Click/tap map points, or use Add point at map centre. Finish when ready.');
  };
  const finish = () => {
    if(!building){notes('Start a ring or line first.',true);return;}
    const points=building.points, target=building.target;
    if(points.length<(category==='polygon'?3:2)){notes('More points are required before this shape can be saved.',true);return;}
    const next=category==='polygon' ? model.appendRing(shape,points.map(model.pair),target==='island',activeIsland)
      : model.appendSegment(shape,points);
    if(!next){notes('The ring or route exceeds the existing geometry contract. Nothing was applied.',true);return;}
    if(setGeometry(next))notes('Shape applied to the unsaved editor draft. Save the record when ready.');
  };
  const moveVertex = (index,latlng) => {
    const next=model.move(shape,category,activeIsland,activeRing,index,latlng);
    if(!next){notes('Invalid vertex; the saved geometry was not changed.',true);render();return;}
    selectedVertex=index;setGeometry(next);
  };
  const nudgeVertex = (index,key,fine) => {
    const pts=pointsFor(),p=pts[index];if(!p)return;
    const delta=fine?.00005:.0005,lat=p[1],lng=p[0];
    const next={lat:lat+(key==='ArrowUp'?delta:key==='ArrowDown'?-delta:0),
      lng:lng+(key==='ArrowRight'?delta:key==='ArrowLeft'?-delta:0)};
    moveVertex(index,next);
  };
  const addVertex = () => {
    if(!shape)return;
    const pts=pointsFor(),len=category==='polygon'?pts.length-1:pts.length;
    if(!len)return;
    const start=pts[Math.min(selectedVertex,len-1)],end=pts[(selectedVertex+1)%len];
    const p=category==='line'&&selectedVertex===len-1 ? mapCenter()
      : {lng:(Number(start[0])+Number(end[0]))/2,lat:(Number(start[1])+Number(end[1]))/2};
    const next=model.insert(shape,category,activeIsland,activeRing,selectedVertex,p);
    if(!next){notes('Cannot add this vertex without violating the geometry limits.',true);return;}
    setGeometry(next);
  };
  const removeVertex = () => {
    if(!shape)return;
    const next=model.remove(shape,category,activeIsland,activeRing,selectedVertex);
    if(!next){notes('Keep at least three polygon or two route vertices.',true);return;}
    selectedVertex=Math.max(0,selectedVertex-1);setGeometry(next);
  };
  const removePart = () => {
    if(!shape || !confirm('Remove the selected '+(category==='polygon'?'ring/island':'route segment')+' from the unsaved draft?'))return;
    const next=model.deletePart(shape,category,activeIsland,activeRing);
    if(!next){notes('The final shape cannot be removed here. Use the separate Remove geometry action.',true);return;}
    activeIsland=0;activeRing=0;selectedVertex=0;setGeometry(next);
  };
  const clearGeometry = () => {
    if(!confirm('Remove this stored geometry from the unsaved form? Save is still required.'))return;
    snapshot();applied(activeShape,'');shape=null;building=null;
    if(category==='polygon')applied(kindField(),'radius');
    render();notes('Geometry removed from the draft only. Save to commit or Cancel draft to restore.');
  };
  const range = (name,value) => {
    const f=field(name),n=Number(value);if(!f||!Number.isFinite(n)||n<0)return false;
    const min=f.min!==''?Number(f.min):0,max=f.max!==''?Number(f.max):Infinity;
    return n>=min&&n<=max;
  };
  const setRadius = (key,source) => {
    const centre=pointFromFields();if(!centre){notes('Place a centre before adjusting its reach.',true);return;}
    const radius=typeof source==='number'?source:model.distanceKm(centre,source);
    const value=Number(radius.toFixed(2)),name=radiusField(key==='extended');
    const normal=key==='normal'?value:Number(field(radiusField(false))?.value||0);
    const custom=field(radiusField(true))?.value;
    const extended=key==='extended'?value:custom===''?null:Number(custom);
    if(!range(name,value)|| (extended!==null && (extended<=0||extended<normal)) ||
      (entity==='legs'&&key==='normal'&&value<=0&&field(kindField())?.value==='radius')) {
      notes('Reach must be inside the allowed maximum; custom extended reach must be positive and at least normal reach.',true);
      render();return;
    }
    snapshot();applied(name,String(value));render();
    notes((key==='normal'?'Normal':'Extended')+' reach draft: '+value.toFixed(2)+' km. Save the record to commit.');
  };
  const setAreaCentre = source => {
    if(!hasAreaFields() || field('to_station_id')?.value!=='' ) {notes('Choose a direct area (not a destination station) first.',true);return;}
    const p=model.point(source);if(!p){notes('Choose a valid signed map point.',true);return;}
    const [a,b]=pointFor();snapshot();applied(a,p.lat.toFixed(7));applied(b,p.lng.toFixed(7));
    render();notes('Service area centre draft: '+p.lat.toFixed(5)+', '+p.lng.toFixed(5)+'. Set its reach and price, then Save.');
  };
  const chooseOrigin = id => {
    if(!hasAreaFields())return;
    const origin=field('from_station_id');if(!origin)return;
    if(![...origin.options].some(option=>Number(option.value)===id)){notes('This station is not an available origin.',true);return;}
    snapshot();applied('from_station_id',String(id));applied('to_station_id','');sourceId=id;
    bridge.focusStation(id);notes('Origin selected. Click/tap an empty map point to place the service area.');
  };
  const selectPart = () => {
    const choose=node('select',null,'form-control grtrans-spatial-select');choose.setAttribute('aria-label','Select geometry ring or segment');
    if(category==='polygon')for(let a=0;a<model.polygons(shape).length;a++)for(let r=0;r<model.polygons(shape)[a].length;r++) {
      const o=node('option','Island '+(a+1)+(r===0?' outer ring':' hole '+r));o.value=a+':'+r;choose.append(o);
    }
    if(category==='line')for(let a=0;a<model.lines(shape).length;a++){
      const o=node('option','Route segment '+(a+1));o.value=a+':0';choose.append(o);
    }
    choose.value=activeIsland+':'+activeRing;
    choose.addEventListener('change',()=>{[activeIsland,activeRing]=choose.value.split(':').map(Number);selectedVertex=0;render();});
    return choose;
  };
  function showControls() {
    state.replaceChildren();opts.replaceChildren();vertexTools.replaceChildren();
    if(!isSpatial(mode))return;
    const add=(label,fn)=>state.append(button(label,fn));
    const kind=mode==='draw'||mode==='edit-coverage'?'polygon':mode==='edit-route'?'line':'';
    if(kind){
      if(!activeShape || category!==kind)chooseShape(kind==='line'?'route_geojson':coverageField());
      if(kind==='polygon'){
        const currentKind=field(kindField())?.value;
        if(currentKind==='radius'){
          const select=node('select',null,'form-control grtrans-spatial-select');select.dataset.grtransPolygonType='1';
          select.setAttribute('aria-label','Choose service area polygon type');
          for(const [value,label]of [['','Choose polygon type'],['postal_polygon','Postal boundary'],['walking_isochrone','Walking service area']]){
            const o=node('option',label);o.value=value;select.append(o);
          }
          select.value=polygonTypeSelection;
          select.addEventListener('change',()=>{polygonTypeSelection=select.value;});
          opts.append(node('label','Save drawn polygon as'),select);
        }
      }
      if(shape){opts.append(selectPart());
        const range=pointsFor(),count=category==='polygon'?range.length-1:range.length;
        const v=node('select',null,'form-control grtrans-spatial-select');v.setAttribute('aria-label','Select vertex');
        for(let i=0;i<count;i++){const opt=node('option','Vertex '+(i+1));opt.value=String(i);v.append(opt);}
        v.value=String(Math.min(selectedVertex,Math.max(0,count-1)));
        v.addEventListener('change',()=>{selectedVertex=Number(v.value);render();});opts.append(v);
        const plus=button('Insert vertex after selected',addVertex);
        const minus=button('Remove selected vertex',removeVertex);vertexTools.append(plus,minus);
        const controls=node('div',null,'grtrans-spatial-nudges');
        for(const [k,label]of [['ArrowUp','North'],['ArrowDown','South'],['ArrowLeft','West'],['ArrowRight','East']]){
          const b=button(label,()=>nudgeVertex(selectedVertex,k,false),'Move selected vertex '+label);controls.append(b);
        }
        vertexTools.append(controls);
      }
      if(kind==='polygon'){
        add(shape?'Add island':'Start outer ring',()=>beginPart(shape?'island':'new'));
        if(shape)add('Add hole',()=>beginPart('hole'));
      } else add(shape?'Add route segment':'Start route',()=>beginPart('segment'));
      if(building){add('Add point at map centre',()=>addPoint(mapCenter()));
        add('Undo last point',()=>{building.points.pop();render();});
        add(kind==='polygon'?'Finish ring':'Finish line',finish);
        add('Cancel current ring/line',()=>{building=null;render();});
      }
      if(shape){add('Remove selected part',removePart);add('Remove geometry',clearGeometry);}
    } else if(mode==='radius'){
      const choice=node('select',null,'form-control grtrans-spatial-select');choice.setAttribute('aria-label','Radius to adjust');
      for(const [value,label] of [['normal','Normal reach'],['extended','Extended reach']]){
        const option=node('option',label);option.value=value;choice.append(option);
      }
      choice.value=radiusTarget;opts.append(choice);
      choice.addEventListener('change',()=>{radiusTarget=choice.value;render();});
      const current=field(radiusField(radiusTarget==='extended'));
      const isInherited=radiusTarget==='extended'&&current?.value==='';
      const input=node('input',null,'form-control grtrans-size-s');input.type='number';input.step='.01';
      input.min=current?.min||'0';input.max=current?.max||'';input.setAttribute('aria-label','Reach in kilometres');
      input.value=isInherited?String(Math.max(Number(bridge.data.farDefaultKm||0),Number(field(radiusField(false))?.value||0))):String(current?.value||0);
      const unit=node('span','km');opts.append(input,unit);
      if(isInherited)opts.append(node('p','Using the global extended-reach default. Dragging is disabled until you choose a custom override.','grtrans-spatial-inherit'));
      add(isInherited?'Customize extended reach':'Apply reach',()=>setRadius(radiusTarget,Number(input.value)));
      add('Use inherited extended reach',()=>{snapshot();applied(radiusField(true),'');render();notes('Inherited extended reach is retained until you save.');});
      add('Move handle to map centre',()=>setRadius(radiusTarget,mapCenter()));
    } else if(mode==='place-area'){
      const origin=field('from_station_id');
      opts.append(node('p',origin?.value?'From station #'+origin.value+'. Tap the map to place the centre.':'Select a sending station pin first.'));
      add('Use map centre for area',()=>setAreaCentre(mapCenter()));
      add('Set area radius',()=>workspace.changeMode('radius'));
      add('Draw area polygon',()=>workspace.changeMode('draw'));
    }
    add('Undo spatial step',undo);
    add('Discard spatial draft',()=>workspace.changeMode('select'));
  }
  const enter = next => {
    mode=next;
    for(const [key,b] of buttons)b.setAttribute('aria-pressed',String(key===next));
    panel.hidden=!isSpatial(next);
    if(!isSpatial(next)){dropLayers();return;}
    if(!fieldCanEdit() && next!=='place-area') {notes('Open a station or transport connection editor first.',true);return;}
    const selection=workspace.selected();
    if(recordId===0 && selection?.id>0 && selection.entity===entity &&
       (next==='radius'||next==='draw'||next==='edit-coverage'||next==='edit-route')) {
      workspace.visit(entity,selection.id,next);return;
    }
    remember();
    if(next==='place-area') {
      if(!hasAreaFields() || recordId>0){
        if(!hasAreaFields()||recordId>0)workspace.visit('legs',0,'place-area');
        return;
      }
      const previous=(()=>{try{const s=sessionStorage.getItem('grtrans-spatial-origin');sessionStorage.removeItem('grtrans-spatial-origin');return Number(s||0);}catch(_){return 0;}})();
      if(previous>0)chooseOrigin(previous);
      applied('to_station_id','');
      notes('Select an origin station, then tap the map to place a direct service area.');
    } else if(next==='radius') {
      if(!shapeAllowed()){notes('Open a station or direct service-area editor to set reach.',true);return;}
      notes('Drag a reach handle, tap a radius point, or use the exact kilometre stepper.');
    } else if(next==='draw'||next==='edit-coverage') {
      if(!shapeAllowed()){notes('Direct-area polygons require an area leg, not a station-to-station leg.',true);return;}
      activeShape=coverageField();category='polygon';shape=currentGeometry(activeShape);
      polygonTypeSelection=field(kindField())?.value==='radius'?'':field(kindField())?.value||'';
      if(!shape)building={kind:'ring',target:'new',points:[]};
      if(!sourceError)notes(shape?'Select a ring and drag its handles; add islands or holes without losing existing ones.':'Tap map points, then Finish ring.');
    } else if(next==='edit-route') {
      if(entity!=='legs'){notes('Route lines belong to transport connections.',true);return;}
      activeShape='route_geojson';category='line';shape=currentGeometry(activeShape);
      if(!shape)building={kind:'line',target:'new',points:[]};
      if(!sourceError)notes(shape?'Drag existing route vertices or add a segment.':'Tap explicit route vertices; no path is generated automatically.');
    }
    render();
  };
  const exit = next => {
    if(!isSpatial(mode))return true;
    if(isSpatial(next)) {
      if(building?.points.length){notes('Finish or cancel the current ring/route before changing tools.',true);return false;}
      dropLayers();building=null;return true; // One form draft across spatial subtools.
    }
    restore();return true;
  };
  const mapClick = latlng => {
    if(!isSpatial(mode))return false;
    if(mode==='place-area'){if(!field('from_station_id')?.value){notes('Select an origin station pin before placing the service area.',true);return true;}
      setAreaCentre(latlng);return true;}
    if(mode==='radius'){
      if(radiusTarget==='extended' && field(radiusField(true))?.value===''){
        notes('Extended reach is inherited. Choose Customize extended reach first to make a custom override.',true);return true;
      }
      setRadius(radiusTarget,latlng);return true;
    }
    if(mode==='draw'||mode==='edit-coverage'||mode==='edit-route'){
      if(building)addPoint(latlng);
      else notes('Choose Add island/hole/segment to begin a new shape, or drag an existing vertex.');
      return true;
    }
    return false;
  };
  const stationPick = station => {
    if(mode==='place-area'){chooseOrigin(Number(station.id_station));return true;}
    return isSpatial(mode); // Do not let a vertex edit unexpectedly select a different saved station.
  };
  const oldHooks=window.GrtransMapWorkspaceHooks||{};
  window.GrtransMapWorkspaceHooks={
    ...oldHooks,
    mapClick:latlng=>mapClick(latlng)||oldHooks.mapClick?.(latlng)===true,
    stationPick:station=>stationPick(station)||oldHooks.stationPick?.(station)===true
  };
  for(const [key,label] of [['draw','Draw area'],['edit-coverage','Edit coverage']]){
    const b=stage.querySelector('[data-grtrans-mode="'+key+'"]');
    if(b){b.disabled=false;b.title=label;b.setAttribute('aria-label',label);
      b.querySelector('.grtrans-map-mode-caption')?.replaceChildren(document.createTextNode(label));}
  }
  // Source station may be selected on the existing list and continued on the new area-leg editor.
  const addArea=button('Add service area from selected station',()=>{
    const id=Number(workspace.selected()?.entity==='stations'?workspace.selected()?.id:0);
    if(id>0){try{sessionStorage.setItem('grtrans-spatial-origin',String(id));}catch(_){}}
    workspace.visit('legs',0,'place-area');
  });
  addArea.classList.add('grtrans-spatial-add-area');
  const context=stage.querySelector('.grtrans-map-context');context?.after(addArea);
  const refreshAddArea=()=>{const selected=workspace.selected();addArea.hidden=!(selected?.entity==='stations' || entity==='legs');};
  stage.addEventListener('click',()=>queueMicrotask(refreshAddArea));
  stage.addEventListener('grtrans:record-selected',refreshAddArea);
  refreshAddArea();
  if(form)form.addEventListener('submit',event=>{
    if(!baseline)return;
    if(building){event.preventDefault();notes('Finish the incomplete ring or route line before saving.',true);return;}
    if(activeShape && field(activeShape)?.value){
      try{
        const current=model.open(field(activeShape).value,category||actualShape(activeShape));
        const result=model.assess(current,category||actualShape(activeShape));
        if(!result.ok){event.preventDefault();notes(result.message+'. The draft remains available for correction.',true);return;}
      }catch(error){event.preventDefault();notes('Invalid spatial draft: '+error.message,true);return;}
    }
    if(shapeAllowed()){
      const normal=Number(field(radiusField(false))?.value||0);
      const override=field(radiusField(true))?.value||'';
      const custom=override===''?null:Number(override);
      const center=validCentre();
      if(!center || !range(radiusField(false),normal) || (custom!==null&&(!range(radiusField(true),custom)||custom<normal||custom<=0)) ||
        (entity==='legs' && field('to_station_id')?.value==='' && field(kindField())?.value==='radius' && normal<=0)) {
        event.preventDefault();notes('The map position or reach is invalid. Correct it; your draft remains in this editor.',true);
      }
    }
  });
  // The server keeps rejected POST values; display their spatial fields without claiming persistence.
  failureDraft=Boolean(editor.querySelector('.grtrans-record-error'));
  if(failureDraft && (entity==='stations'||entity==='legs')) {
    const rejected=node('div',null,'grtrans-spatial-save-error');
    rejected.append(node('p','Last save failed. Entered geometry is retained in the form, not on the saved map. Correct it and retry.'));
    if(persistedSpatial())rejected.append(button('Discard rejected spatial changes',()=>{
      restore();notes('Saved coordinates and geometry restored from the current record list. Other fields are retained.');
    }));
    editor.prepend(rejected);
  }
  // Rendering replaces focused spatial action buttons (for example, Finish ring).
  // Focus then falls back to document.body, outside the Stage-1 workspace's
  // Escape listener. Capture Escape while a spatial tool is active so Cancel
  // always restores the exact pre-draft form values through beforeSwitch.
  document.addEventListener('keydown', event => {
    if(event.key!=='Escape'||!isSpatial(mode))return;
    event.preventDefault();
    event.stopPropagation();
    workspace.changeMode('select');
  },true);
  window.GrtransMapSpatialEditor={beforeSwitch:exit,afterSwitch:enter,discard:restore};
  enter(workspace.getMode());
})();
