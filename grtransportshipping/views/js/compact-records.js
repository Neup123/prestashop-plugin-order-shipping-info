/* Configure record presentation. All writes retain the existing signed server forms. */
(() => {
  'use strict';
  const ENTITIES = ['stations', 'legs', 'transfers', 'branches', 'branch_stock'];
  const TITLES = {stations:'Stations', legs:'Transport connections', transfers:'Station transfers',
    branches:'Dispatch locations', branch_stock:'Products available to dispatch'};
  const KEEP = {stations:[1,2,3,4,9,13,14], legs:[1,2,3,4,5,6,27,29],
    transfers:[1,2,3,4,5,7,8], branches:[1,2,3,4], branch_stock:[1,2,3,4,5,6]};
  const FIELDS = {
    id_station:'Record number', id_leg:'Record number', id_transfer:'Record number',
    id_branch:'Dispatch location number', id_branch_stock:'Stock entry number',
    name:'Name', type:'Station type', address_text:'Street address', lat:'Latitude', lng:'Longitude',
    inner_radius_km:'Normal service reach', outer_radius_km:'Extended service reach',
    is_origin_hub:'Can dispatch parcels', coverage_kind:'Service area shape',
    coverage_geojson:'Service area geometry', patch_precedence:'Overlap priority',
    from_station_id:'Starts at', to_station_id:'Goes to', mode:'Transport method',
    operator_label:'Carrier or service name', price:'Base parcel price', tariff_type:'Price calculation',
    rate_per_unit:'Rate per unit', estimate:'Price is an estimate',
    estimate_low_factor:'Lowest estimate factor', estimate_high_factor:'Highest estimate factor',
    bidirectional:'Runs in both directions', lead_hours:'Time before dispatch',
    preparation_hours:'Preparation time', transit_min_hours:'Shortest transit time',
    transit_max_hours:'Longest transit time', area_name:'Service area name',
    area_lat:'Area latitude', area_lng:'Area longitude', area_radius_km:'Normal area reach',
    area_outer_radius_km:'Extended area reach', area_coverage_kind:'Service area shape',
    area_coverage_geojson:'Area geometry', route_geojson:'Recorded route line',
    override_winner:'Preferred service in overlap', connection_type:'How the parcel moves',
    origin_station_id:'Dispatch from station', id_product:'Product',
    id_product_attribute:'Product variant', quantity:'Available units',
    'Last verified (UTC)':'Last checked', 'FAR default / override':'Extended reach',
    Actions:'Actions', ID:'Record number'
  };
  const GROUPS = {
    stations:[['Station',['name','type','address_text','is_origin_hub']],
      ['Location and service reach',['lat','lng','inner_radius_km','outer_radius_km','coverage_kind','coverage_geojson']],
      ['Advanced',['patch_precedence']]],
    legs:[['Connection',['from_station_id','to_station_id','mode','operator_label','bidirectional']],
      ['Price',['tariff_type','price','rate_per_unit','estimate','estimate_low_factor','estimate_high_factor']],
      ['Timing',['lead_hours','preparation_hours','transit_min_hours','transit_max_hours']],
      ['Direct service area',['area_name','area_lat','area_lng','area_radius_km','area_outer_radius_km','area_coverage_kind','area_coverage_geojson','route_geojson']],
      ['Advanced',['patch_precedence','override_winner']]],
    transfers:[['Stations and price',['from_station_id','to_station_id','connection_type','price']],
      ['Advanced',['override_winner']]],
    branches:[['Dispatch location',['name','origin_station_id']]],
    branch_stock:[['Availability',['id_branch','id_product','id_product_attribute','quantity']]]
  };
  const SIZE = {name:'l',address_text:'l',operator_label:'l',area_name:'l',
    coverage_geojson:'lt',area_coverage_geojson:'lt',route_geojson:'lt',
    lat:'s',lng:'s',area_lat:'s',area_lng:'s',inner_radius_km:'s',outer_radius_km:'s',
    area_radius_km:'s',area_outer_radius_km:'s',price:'s',rate_per_unit:'s',
    estimate_low_factor:'s',estimate_high_factor:'s',lead_hours:'s',preparation_hours:'s',
    transit_min_hours:'s',transit_max_hours:'s',quantity:'s',id_product_attribute:'s',
    id_product:'s',connection_type:'m',type:'m',mode:'m',tariff_type:'m',coverage_kind:'m',
    area_coverage_kind:'m',from_station_id:'m',to_station_id:'m',origin_station_id:'m',id_branch:'m'};
  const UNIT = {inner_radius_km:'km',outer_radius_km:'km',area_radius_km:'km',area_outer_radius_km:'km',
    price:'EUR',rate_per_unit:'EUR / unit',lead_hours:'hours',preparation_hours:'hours',
    transit_min_hours:'hours',transit_max_hours:'hours'};
  const DETAILS = {stations:'Select a station to inspect its details.',
    legs:'Select a connection to inspect its price and direction.',
    transfers:'Select a transfer to inspect its connection.',
    branches:'Select a dispatch location to inspect it.',
    branch_stock:'Select a product availability record.'};
  const text = element => element?.textContent?.trim() || '';
  const el = (tag,cls,content) => {
    const node=document.createElement(tag);
    if(cls) node.className=cls;
    if(content!==undefined) node.textContent=content;
    return node;
  };
  const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase();
  const compare = (a,b) => a.localeCompare(b,undefined,{numeric:true,sensitivity:'base'});
  const filterRecords = (records,query,filters={}) => records.filter(record => {
    if(query && !normalize(record.search).includes(normalize(query))) return false;
    return Object.entries(filters).every(([key,value]) => !value || String(record[key])===String(value));
  });
  const sortRecords = (records,kind) => [...records].sort((a,b) => {
    if(kind==='original') return 0;
    if(kind==='price') return (Number(a.price)||0)-(Number(b.price)||0) || compare(a.label,b.label);
    if(kind==='recent') return (Number(b.id)||0)-(Number(a.id)||0);
    return compare(a.label,b.label) || (Number(a.id)||0)-(Number(b.id)||0);
  });
  if(typeof module!=='undefined' && module.exports) module.exports={filterRecords,sortRecords,normalize,KEEP,FIELDS,GROUPS,SIZE};
  if(typeof document==='undefined') return;
  const root=document.getElementById('grtrans-configure');
  if(!root) return;

  function toolbar(records, options, onUpdate) {
    const bar=el('div','grtrans-record-filters');
    const search=el('input','form-control grtrans-size-m');
    search.type='search'; search.placeholder='Find a record'; search.setAttribute('aria-label','Find a record');
    bar.append(search);
    const controls={};
    for(const [key,caption,choices] of options) {
      const wrapper=el('label','grtrans-record-filter');
      wrapper.append(el('span','grtrans-filter-caption',caption));
      const select=el('select','form-control grtrans-size-m');
      select.append(new Option('All', ''));
      choices.forEach(choice=>select.append(new Option(choice,choice)));
      wrapper.append(select); bar.append(wrapper); controls[key]=select;
      select.addEventListener('change',onUpdate);
    }
    const sort=el('select','form-control grtrans-size-m');
    sort.setAttribute('aria-label','Sort records');
    for(const [value,label] of [['original','Original order'],['name','Name'],['price','Price, lowest first'],['recent','Newest record first']])
      sort.append(new Option(label,value));
    bar.append(sort);
    const count=el('output','grtrans-record-count');count.setAttribute('aria-live','polite');bar.append(count);
    search.addEventListener('input',onUpdate);sort.addEventListener('change',onUpdate);
    const selectedFilters=()=>Object.fromEntries(Object.entries(controls).map(([k,v])=>[k,v.value]));
    return {bar,count,query:()=>search.value,filters:selectedFilters,sort:()=>sort.value};
  }
  function addDetails(row,headings,hiddenColumns,actions,title) {
    const details=el('details','grtrans-record-details');
    details.append(el('summary','', 'Details'));
    const dl=el('dl','grtrans-record-facts');
    hiddenColumns.forEach(index=>{
      const value=text(row.cells[index-1]);
      if(!value || value==='—') return;
      const key=headings[index-1] || '';
      const dt=el('dt','',FIELDS[key] || key);
      const dd=el('dd','',value);
      if(value.length>160) dd.classList.add('grtrans-long-value');
      dl.append(dt,dd);
    });
    if(!dl.children.length) dl.append(el('dd','','No additional details.'));
    details.append(dl);actions.append(details);
    return details;
  }
  function activateSelection(rows,record,button) {
    rows.forEach(item=>{
      const active=item===record.row;
      item.classList.toggle('grtrans-selected-record',active);
      item.querySelector('[data-grtrans-select-record]')?.setAttribute('aria-pressed',String(active));
    });
    button.setAttribute('aria-pressed','true');
    root.dispatchEvent(new CustomEvent('grtrans:record-selected',{bubbles:true,
      detail:{entity:record.entity,id:record.id}}));
  }
  function selectButton(record,rows) {
    const button=el('button','grtrans-select-record','Select');
    button.type='button';button.dataset.grtransSelectRecord=String(record.id);
    button.setAttribute('aria-label','Select '+record.entity.replace('_',' ')+' #'+record.id);
    button.setAttribute('aria-pressed','false');
    button.addEventListener('click',()=>activateSelection(rows,record,button));
    return button;
  }
  function enhanceRoutes() {
    const table=root.querySelector('#grtrans-routes .grtrans-route-grid table');
    if(!table) return;
    const rows=[...table.querySelectorAll('tbody tr')];
    const heads=[...table.querySelectorAll('thead th')].map(text);
    const records=rows.map(row=>{
      const cells=row.cells;
      const id=Number(text(cells[0]));
      const record={row,id,entity:'routes',label:text(cells[3])||text(cells[4]),
        mode:text(cells[1]),availability:text(cells[8]),verification:text(cells[9]),
        price:Number(text(cells[6]).replace(',','.')),search:[...cells].map(text).join(' ')};
      row.dataset.grtransRecordId=String(id);
      const actions=cells[cells.length-1].querySelector('.grtrans-actions');
      if(actions) {
        actions.prepend(selectButton(record,rows));
        addDetails(row,heads,[1,6,8],actions,record.label);
      }
      return record;
    });
    table.classList.add('grtrans-compact-routes');
    const filters=toolbar(records,[
      ['mode','Transport method',[...new Set(records.map(r=>r.mode).filter(Boolean))].sort(compare)],
      ['availability','Availability',[...new Set(records.map(r=>r.availability).filter(Boolean))]],
      ['verification','Verification',[...new Set(records.map(r=>r.verification).filter(Boolean))]]],update);
    table.closest('.grtrans-route-grid').before(filters.bar);
    const noMatches=el('p','grtrans-record-empty','No priced routes match these filters.');
    noMatches.hidden=true;table.closest('.grtrans-route-grid').after(noMatches);
    function update() {
      const current=filterRecords(records,filters.query(),filters.filters());
      const sorted=sortRecords(current,filters.sort());
      sorted.forEach(item=>table.tBodies[0].append(item.row));
      records.forEach(item=>{item.row.hidden=!current.includes(item);});
      filters.count.textContent=current.length+' of '+records.length+' routes';
      noMatches.hidden=Boolean(current.length)||records.length===0;
    }
    update();
  }
  function enhanceNetwork() {
    const network=root.querySelector('#grtrans-network');
    const nav=network?.querySelector('.grtrans-network-toolbar');
    if(!nav) return;
    nav.setAttribute('role','navigation');nav.setAttribute('aria-label','Network record categories');
    const activeValue=network.querySelector('#grtrans-network-editor input[name="network_entity"]')?.value;
    const active=ENTITIES.includes(activeValue)?activeValue:'stations';
    const items=[];
    let next=nav.nextElementSibling;
    for(const entity of ENTITIES) {
      if(!next || next.tagName!=='H4') break;
      const title=next;
      const wrapper=title.nextElementSibling;
      if(!wrapper?.querySelector('table')) break;
      next=wrapper.nextElementSibling;
      const section=el('section','grtrans-network-list');section.dataset.grtransEntity=entity;
      title.before(section);section.append(title,wrapper);items.push({entity,section,table:wrapper.querySelector('table')});
      section.hidden=entity!==active;
      title.textContent=TITLES[entity];
    }
    for(const link of nav.querySelectorAll('a')) {
      const entity=new URL(link.href,location.href).searchParams.get('network_entity');
      link.setAttribute('aria-current',String(entity===active));
      if(entity===active) link.classList.add('grtrans-active-tab');
      if(TITLES[entity]) link.textContent=TITLES[entity];
    }
    for(const item of items) enhanceNetworkList(item);
  }
  function enhanceNetworkList({entity,section,table}) {
    const headings=[...table.querySelectorAll('thead th')].map(text);
    const rows=[...table.querySelectorAll('tbody tr')];
    const keep=KEEP[entity]||[1,2,3,headings.length];
    table.classList.add('grtrans-compact-network');table.dataset.grtransEntity=entity;
    table.querySelectorAll('thead th').forEach((th,i)=>{
      const old=text(th);th.textContent=FIELDS[old] || old.replace(/_/g,' ');
      if(keep.includes(i+1)) th.classList.add('grtrans-keep');
    });
    const records=rows.map(row=>{
      [...row.cells].forEach((cell,i)=>{if(keep.includes(i+1)) cell.classList.add('grtrans-keep');});
      const id=Number(text(row.cells[0]));
      const record={row,id,entity,label:'',price:0,mode:'',verification:'',search:[...row.cells].map(text).join(' ')};
      if(entity==='stations') record.label=text(row.cells[1]);
      if(entity==='legs') {record.label=text(row.cells[4])||('Connection #'+id);record.mode=text(row.cells[3]);record.price=Number(text(row.cells[5]));record.verification=text(row.cells[26]);}
      if(entity==='transfers') {record.label=text(row.cells[3])+' transfer #'+id;record.price=Number(text(row.cells[4]));record.verification=text(row.cells[6]);}
      if(entity==='branches') record.label=text(row.cells[1]);
      if(entity==='branch_stock') record.label='Product #'+text(row.cells[2]);
      row.dataset.grtransRecordId=String(id);
      const actions=row.cells[row.cells.length-1];
      if(actions) {
        actions.classList.add('grtrans-network-actions');
        actions.prepend(selectButton(record,rows));
        const anchor=actions.querySelector('a');
        if(anchor) {
          anchor.classList.add('grtrans-icon-edit');anchor.title='Edit '+record.label;
          anchor.setAttribute('aria-label','Edit '+record.label);
          anchor.innerHTML='<span class="material-icons" aria-hidden="true">edit</span>';
        }
        const deleteButton=actions.querySelector('button[onclick*="Delete network record"]');
        if(deleteButton) {
          deleteButton.removeAttribute('onclick');
          deleteButton.setAttribute('aria-label','Delete '+record.label);
          const dependency=entity==='stations'?' Connecting services and dispatch locations must be removed first.'
            :entity==='branches'?' Product availability must be removed first.':'';
          deleteButton.addEventListener('click',event=>{
            if(!confirm('Delete '+record.label+' (#'+id+')? This cannot be undone.'+dependency)) event.preventDefault();
          });
        }
        const verify=actions.querySelector('button[value="verify"]');
        if(verify) {verify.textContent='Mark checked';verify.title='Mark '+record.label+' as checked';verify.setAttribute('aria-label','Mark '+record.label+' as checked');}
        const forms=[...actions.querySelectorAll(':scope > form')];
        if(forms.length) {
          const overflow=el('details','grtrans-row-more grtrans-network-more');
          const summary=el('summary','','⋯');
          summary.setAttribute('aria-label','More actions for '+record.label);
          summary.title='More actions for '+record.label;
          overflow.append(summary);forms.forEach(form=>overflow.append(form));actions.append(overflow);
        }
        addDetails(row,headings,headings.map((_,i)=>i+1).filter(n=>!keep.includes(n)&&n!==headings.length),actions,record.label);
      }
      return record;
    });
    const selector=el('div','grtrans-record-list-head');
    const add=el('a','btn btn-default','Add '+(TITLES[entity]||entity).toLowerCase().replace(/s$/,''));
    const url=new URL(location.href);url.searchParams.set('network_entity',entity);url.searchParams.delete('network_edit');
    add.href=url.pathname+url.search+'#grtrans-network-editor';selector.append(add);
    const optionSets=[];
    if(entity==='legs') optionSets.push(['mode','Transport method',[...new Set(records.map(r=>r.mode).filter(Boolean))]]);
    if(entity==='legs'||entity==='transfers') optionSets.push(['verification','Last checked',[...new Set(records.map(r=>r.verification).filter(Boolean))]]);
    const filters=toolbar(records,optionSets,update);
    selector.append(filters.bar);section.querySelector('h4')?.after(selector);
    const noRecords=el('p','grtrans-record-empty',records.length?'No records match these filters.':'No '+TITLES[entity].toLowerCase()+' yet. Use Add to create one.');
    noRecords.hidden=Boolean(records.length);table.closest('.table-responsive').after(noRecords);
    function update() {
      const current=filterRecords(records,filters.query(),filters.filters());
      sortRecords(current,filters.sort()).forEach(item=>table.tBodies[0].append(item.row));
      records.forEach(item=>{item.row.hidden=!current.includes(item);});
      filters.count.textContent=current.length+' of '+records.length+' records';
      noRecords.hidden=Boolean(current.length);
    }
    update();
  }
  function enhanceEditor() {
    const editor=root.querySelector('#grtrans-network-editor');
    const form=editor?.querySelector('form');if(!form) return;
    const entity=form.querySelector('input[name="network_entity"]')?.value;
    const groups=GROUPS[entity];if(!groups) return;
    const fields=new Map([...form.querySelectorAll('.form-group')].map(group=>{
      const field=group.querySelector('[name]');return [field?.name,group];
    }).filter(([key])=>key));
    const layout=el('div','grtrans-editor-layout');
    for(const [title,names] of groups) {
      const block=title==='Advanced'?el('details','grtrans-editor-advanced'):el('fieldset','grtrans-editor-group');
      block.append(el(title==='Advanced'?'summary':'legend','',title));
      const contents=el('div','grtrans-editor-fields');block.append(contents);
      for(const key of names) {
        const group=fields.get(key);if(!group) continue;
        contents.append(group);group.dataset.grtransField=key;
        const label=group.querySelector('label');if(label && FIELDS[key])label.textContent=key==='name'
          ? (entity==='stations'?'Station name':'Dispatch location name') : FIELDS[key];
        const control=group.querySelector('input,select,textarea');
        if(control) {
          if(SIZE[key]) control.classList.add('grtrans-size-'+SIZE[key]);
          if(UNIT[key] && control.tagName==='INPUT') {
            const unit=el('span','grtrans-unit',UNIT[key]);
            const wrap=el('div','grtrans-value-control');control.before(wrap);wrap.append(control,unit);
          }
          if(['lat','area_lat'].includes(key)){control.min='-90';control.max='90';}
          if(['lng','area_lng'].includes(key)){control.min='-180';control.max='180';}
          if(key==='quantity'||key==='id_product_attribute'){control.step='1';control.min='0';}
          if(key==='id_product'){control.step='1';control.min='1';}
          if(['from_station_id','to_station_id','origin_station_id','id_branch'].includes(key) && control.tagName==='SELECT') {
            if(key!=='to_station_id') {
              const chosen=[...control.options].some(option=>option.hasAttribute('selected'));
              if(!chosen && Number(control.value)>0) control.value='';
              control.required=true;
            }
            const search=el('input','form-control grtrans-size-m grtrans-select-search');
            search.type='search';search.placeholder='Filter choices';
            search.setAttribute('aria-label','Filter '+(FIELDS[key]||key));
            control.before(search);
            const options=[...control.options];
            search.addEventListener('input',()=>{
              const q=normalize(search.value);
              for(const option of options) option.hidden=Boolean(q)&&!normalize(option.text).includes(q);
            });
          }
        }
      }
      if(contents.childElementCount) layout.append(block);
    }
    const original=form.querySelector('.grtrans-network-advanced');
    if(original)original.remove();
    const commit=form.querySelector('button[name="grtrans_network_submit"]');
    if(commit)commit.before(layout);else form.append(layout);
    if(entity==='legs') {
      const destination=form.querySelector('[name="to_station_id"]');
      const tariff=form.querySelector('[name="tariff_type"]');
      const estimate=form.querySelector('[name="estimate"]');
      const conditional=()=>{
        const direct=destination?.value==='';
        for(const key of ['area_name','area_lat','area_lng','area_radius_km','area_outer_radius_km','area_coverage_kind','area_coverage_geojson']) {
          const group=fields.get(key);if(!group)continue;
          const value=group.querySelector('[name]')?.value||'';
          const neutral=value===''||value==='0'||value==='radius';
          group.hidden=!direct&&neutral;
        }
        const rate=fields.get('rate_per_unit');if(rate)rate.hidden=tariff?.value==='flat' && Number(rate.querySelector('input')?.value||0)===0;
        for(const key of ['estimate_low_factor','estimate_high_factor']) {
          const group=fields.get(key);if(group)group.hidden=estimate?.value==='0';
        }
      };
      [destination,tariff,estimate].forEach(control=>control?.addEventListener('change',conditional));conditional();
    }
    if(entity==='stations') {
      const coverage=form.querySelector('[name="coverage_kind"]');
      const polygon=fields.get('coverage_geojson');
      const update=()=>{if(polygon)polygon.hidden=coverage?.value==='radius' && !polygon.querySelector('textarea')?.value;};
      coverage?.addEventListener('change',update);update();
    }
    if(entity==='branch_stock') enhanceProductChoices(form,fields);
  }
  function enhanceProductChoices(form,fields) {
    const source=root.querySelector('#grtrans-catalog-options');if(!source)return;
    let catalog;try{catalog=JSON.parse(source.textContent);}catch(_){return;}
    const numeric=form.querySelector('[name="id_product"]');
    const variant=form.querySelector('[name="id_product_attribute"]');
    if(!numeric||!variant)return;
    const picker=el('input','form-control grtrans-size-l');picker.type='search';
    picker.placeholder='Search product name or number';picker.setAttribute('aria-label','Search catalog products');
    const list=el('datalist');list.id='grtrans-product-catalog';picker.setAttribute('list',list.id);
    const products=new Map();
    for(const entry of catalog.products||[]) {
      const value=entry.name+' (#'+entry.id+')';products.set(value,String(entry.id));
      list.append(new Option(value));
      if(String(entry.id)===numeric.value)picker.value=value;
    }
    numeric.before(picker,list);
    const advanced=el('details','grtrans-id-fallback');advanced.append(el('summary','','Enter a product number manually'));
    numeric.before(advanced);advanced.append(numeric);
    const variantChoice=el('select','form-control grtrans-size-m');variant.before(variantChoice);
    const variantFallback=el('details','grtrans-id-fallback');variantFallback.append(el('summary','','Enter a variant number manually'));
    variant.before(variantFallback);variantFallback.append(variant);
    const syncVariants=()=>{
      const chosen=variant.value;
      variantChoice.replaceChildren(new Option('Default product (no variant)','0'));
      for(const item of catalog.variants||[]) if(String(item.product_id)===numeric.value)
        variantChoice.append(new Option(item.name||('Variant #'+item.id),String(item.id)));
      variantChoice.value=[...variantChoice.options].some(option=>option.value===chosen)?chosen:'0';
    };
    picker.addEventListener('change',()=>{
      const id=products.get(picker.value);
      if(!id){picker.setCustomValidity('Choose a listed product or enter its number in Advanced.');picker.reportValidity();return;}
      picker.setCustomValidity('');
      if(numeric.value!==id){numeric.value=id;variant.value='0';}syncVariants();
    });
    picker.addEventListener('input',()=>picker.setCustomValidity(''));
    variantChoice.addEventListener('change',()=>{variant.value=variantChoice.value;});
    syncVariants();
  }
  enhanceRoutes();enhanceNetwork();enhanceEditor();
})();
