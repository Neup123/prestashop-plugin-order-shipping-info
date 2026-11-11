<?php
require_once __DIR__ . '/NetworkApi.php';
require_once __DIR__ . '/NetworkGraph.php';
require_once __DIR__ . '/NetworkFreshness.php';
require_once __DIR__ . '/NetworkResolution.php';

/** Operator interface over the exact NetworkRepository/NetworkApi contract. */
final class GrTransportNetworkAdmin
{
    /** Failed network edits are preserved only for this Configure request. */
    private static ?array $failedDraft=null;
    private static string $failedEntity='';
    private static int $failedId=0;
    private static string $failedMessage='';

    public static function handle($module): string
    {
        if (!isset($_POST['grtrans_network_action'])) return '';
        $employeeId=(int)(Context::getContext()->employee->id ?? 0);
        $container=\PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        $manager=$container ? $container->get(\PrestaShopBundle\Security\Admin\UserTokenManager::class) : null;
        if ($employeeId<=0 || empty($_POST['token']) || !is_string($_POST['token'])
            || !$manager || !$manager->isTokenValid()) {
return '<div class="alert alert-danger" data-grtrans-autosave-result="error">'
                . self::esc($module->l('Invalid administrator session. Reload Configure and try again.')) . '</div>';
        }
        try {
            $action=(string)$_POST['grtrans_network_action'];
            if ($action==='save_operational_settings') {
                GrTransportNetworkSettings::save($_POST);
                return '<div class="alert alert-success" data-grtrans-autosave-result="ok">'
                    .self::esc($module->l('Transport settings saved.')).'</div>';
            }
            if ($action==='save_extended_notice') {
                $notice=$_POST['extended_notice'] ?? '';
                if (!is_string($notice) || mb_strlen($notice, 'UTF-8')>1200) {
                    throw new InvalidArgumentException('Extended pickup notice must contain at most 1200 characters');
                }
                if (!Configuration::updateValue('GRTRANS_EXTENDED_NOTICE',trim($notice))) {
                    throw new RuntimeException('Cannot save extended pickup notice');
                }
                return '<div class="alert alert-success" data-grtrans-autosave-result="ok">'
                    .self::esc($module->l('Extended pickup notice saved.')).'</div>';
            }
            if ($action==='rotate_read' || $action==='rotate_write') {
                $scope=$action==='rotate_read' ? 'read' : 'write';
                $token=GrTransportNetworkApi::rotate($scope);
                $copy=self::esc($module->l('Copy token'));
                return '<div class="alert alert-warning" role="status">'
                    .'<strong>'.self::esc($module->l('New API token — copy and store it now.')).'</strong>'
                    .'<div class="grtrans-secret"><code id="grtrans-issued-token">'.self::esc($token).'</code>'
                    .'<button type="button" class="btn btn-default btn-sm" title="'.$copy.'" aria-label="'.$copy.'"'
                    .' data-grtrans-copy="grtrans-issued-token"'
                    .' data-copy-ok="'.self::esc($module->l('Copied')).'"'
                    .' data-copy-fail="'.self::esc($module->l('Select and copy the token manually')).'">'
                    .'<span class="material-icons" aria-hidden="true">content_copy</span></button></div>'
                    .'<p>'.self::esc($module->l('Previous clients using this scope must use the new token. The value is not stored in plaintext.')).'</p></div>';
            }
            $entity=(string)($_POST['network_entity'] ?? '');
            GrTransportNetworkFields::fields($entity);
            $id=filter_var($_POST['network_id'] ?? 0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]) ?: 0;
            if ($action==='delete') {
                GrTransportNetworkRepository::delete($entity,(int)$id);
            } elseif ($action==='save') {
                $data=[];
                foreach (GrTransportNetworkFields::fields($entity) as $key=>$definition) {
                    $data[$key]=$_POST[$key] ?? null;
                    if ($definition[0]==='nullable_id' && $data[$key]==='') $data[$key]=null;
                    if (str_starts_with($definition[0],'nullable_') && $data[$key]==='') $data[$key]=null;
                }
                GrTransportNetworkRepository::save($entity,$data,(int)$id);
            } elseif ($action==='verify') {
                GrTransportNetworkRepository::confirm($entity,(int)$id);
            } elseif ($action==='link') {
                GrTransportNetworkRepository::save('transfers',[
                    'from_station_id'=>$_POST['from_station_id'] ?? null,
                    'to_station_id'=>$_POST['to_station_id'] ?? null,
                    'connection_type'=>$_POST['connection_type'] ?? 'walk',
                    'price'=>$_POST['price'] ?? 0,
                    'override_winner'=>$_POST['override_winner'] ?? 'none',
                ]);
            } else throw new InvalidArgumentException('Unknown network action');
            $kinds=['stations'=>'Station','legs'=>'Transport connection',
                'transfers'=>'Station transfer','branches'=>'Dispatch location',
                'branch_stock'=>'Product availability'];
            $kind=$kinds[$entity] ?? 'Record';
            $message=($action==='link' || ($action==='save' && $id===0))
                ? 'Network record created.' : 'Network record updated.';
            if ($action==='verify') {
                $current=GrTransportNetworkRepository::find($entity,(int)$id);
                $detail=$kind.' #'.$id.' checked'
                    . (!empty($current['verified_at']) ? ' at '.$current['verified_at'].' UTC.' : '.');
            } elseif ($action==='delete') {
                $detail=$kind.' #'.$id.' deleted.';
            } elseif ($action==='save') {
                $detail=$kind.($id>0 ? ' #'.$id.' saved.' : ' created.');
            } else {
                $detail='Station connection saved.';
            }
            return '<div class="alert alert-success" role="status"><strong>'
                .self::esc($module->l($message)).'</strong> '.self::esc($module->l($detail)).'</div>';
        } catch (Throwable $error) {
            if (isset($action) && $action==='save' && isset($entity) &&
                in_array($entity,GrTransportNetworkFields::ENTITIES,true)) {
                self::$failedEntity=$entity;
                self::$failedDraft=$_POST;
                self::$failedId=(int)($_POST['network_id'] ?? 0);
                self::$failedMessage=$error->getMessage();
            }
            return '<div class="alert alert-danger" role="alert" data-grtrans-autosave-result="error">'
                .self::esc($module->l('The change was not saved. Check the fields and try again.'))
                .'<details><summary>'.self::esc($module->l('Technical details')).'</summary><code>'
                .self::esc($error->getMessage()).'</code></details></div>';
        }
    }

    public static function render($module): string
    {
        $data=GrTransportNetworkRepository::snapshot();
        $data['legs']=GrTransportNetworkFreshness::mapRecords($data['legs']);
        $data['transfers']=GrTransportNetworkFreshness::mapRecords($data['transfers']);
        $path=Context::getContext()->link->getAdminLink('AdminModules',true,[],[
            'configure'=>$module->name,'module_name'=>$module->name,
        ]);
        $url=self::esc($path);
        $csrf=self::esc(Tools::getAdminTokenLite('AdminModules'));
        $entity=isset($_GET['network_entity']) && is_string($_GET['network_entity'])
            && in_array($_GET['network_entity'],GrTransportNetworkFields::ENTITIES,true)
            ? $_GET['network_entity'] : 'stations';
        // A POST without a network_entity query parameter must stay on the
        // dataset the merchant just edited, rather than defaulting to Stations.
        if (isset($_POST['network_entity']) && is_string($_POST['network_entity'])
            && in_array($_POST['network_entity'],GrTransportNetworkFields::ENTITIES,true)) {
            $entity=$_POST['network_entity'];
        }
        $edit=isset($_GET['network_edit']) && is_scalar($_GET['network_edit'])
            ? (int)$_GET['network_edit'] : 0;
        if (isset($_POST['grtrans_network_action'])
            && in_array($_POST['grtrans_network_action'],['save','verify'],true)
            && isset($_POST['network_id']) && is_scalar($_POST['network_id'])) {
            $edit=max(0,(int)$_POST['network_id']);
        }
        $selected=$edit>0 ? GrTransportNetworkRepository::find($entity,$edit) : null;
        $missingTarget=$edit>0 && !$selected;
        $values=array_replace(GrTransportNetworkFields::defaults($entity),
            $selected ? array_intersect_key($selected,GrTransportNetworkFields::fields($entity)) : []);
        if (self::$failedDraft!==null && self::$failedEntity===$entity && !$missingTarget) {
            $values=array_replace($values,array_intersect_key(self::$failedDraft,
                GrTransportNetworkFields::fields($entity)));
        }
        $route=GrTransportNetworkGraph::routes($data['stations'],$data['legs'],$data['transfers']);
        $cost=[];
        foreach ($route['cost'] as $id=>$amount) {
            if (is_finite($amount)) $cost[(string)$id]=round($amount,2);
        }
        // Branch and stock context is shop-scoped in snapshot(); expose only
        // these existing records to the map-side selection UI.
        $mapData=array_intersect_key($data,array_flip(['stations','legs','transfers','branches','branch_stock']));
        $mapData['resolution']=GrTransportNetworkResolution::mapPlan($mapData,$route['cost']);
        $mapConfig=json_encode($mapData+['cost'=>$cost,'nearbyGapKm'=>GrTransportNetworkSettings::get('nearby_gap_km'),
            'farDefaultKm'=>GrTransportNetworkSettings::get('station_outer_km'),
            'leafletImagePath'=>_MODULE_DIR_ . $module->name . '/views/vendor/leaflet/images/'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $base=self::esc((_MODULE_DIR_ . $module->name . '/')) . 'views/';
        $out='<section class="panel" id="grtrans-network">'
            . '<h3>Transport network · coverage map</h3>'
            . '<p>Data-defined coverage: inner normal reach is strong; FAR outer reach is lighter. '
            . 'Priced transitions show a short zigzag only at a resolved handover boundary; '
            . 'equal-cost linked land patches merge without an internal seam. '
            . 'Distant flights have symbolic arcs; ferries have dashed straight lines; '
            . 'station-bound land follows its recorded/derived road (price gradient if costs differ). '
            . 'Direct areas have normal and lighter FAR tiers; linked islands have perimeter stitches. '
            . 'Click a station, choose Link, and click its destination.</p>'
            . '<link rel="stylesheet" href="' . $base . 'vendor/leaflet/leaflet.css">'
            . '<link rel="stylesheet" href="' . $base . 'css/network-admin.css">'
            . '<div id="grtrans-network-map" aria-label="Transport network coverage map" role="region" data-config="'
            . self::esc($mapConfig) . '"></div>'
            . '<div id="grtrans-zone-legend" role="list" aria-label="Coverage zone colours"></div>'
            . '<div id="grtrans-network-map-tools"><button type="button" id="grtrans-map-link" class="btn btn-default" disabled>Link selected station</button>'
            . ' <button type="button" id="grtrans-map-preview" class="btn btn-default">Preview route between two pins</button>'
            . ' <button type="button" id="grtrans-map-preview-clear" class="btn btn-default">Clear preview</button>'
            . ' <span id="grtrans-map-status" role="status">Select a station or click the map to set coordinates in the editor.</span></div>'
            . '<p id="grtrans-preview-status" role="status" aria-live="polite">Preview uses stored one-way network links; road geometry requires OSRM, ferry segments connect recorded ports.</p>'
            . '<form method="post" action="' . $url . '#grtrans-network" id="grtrans-map-link-form" hidden>'
            . '<input type="hidden" name="token" value="' . $csrf . '">'
            . '<input type="hidden" name="grtrans_network_action" value="link">'
            . '<input type="hidden" name="network_entity" value="transfers">'
            . '<input type="hidden" name="from_station_id" id="grtrans-link-from">'
            . '<input type="hidden" name="to_station_id" id="grtrans-link-to">'
            . '<div id="grtrans-link-popup" role="dialog" aria-label="Define transfer">'
            . '<label for="grtrans-link-type">Connection type</label>'
            . '<input id="grtrans-link-type" name="connection_type" maxlength="64" value="taxi" required>'
            . '<label for="grtrans-link-price">Transfer price EUR (0 = free)</label>'
            . '<input id="grtrans-link-price" name="price" type="number" step="0.01" min="0" max="100000" value="0" required>'
            . '<details class="grtrans-network-advanced"><summary>Advanced · override handover</summary>'
            . '<label for="grtrans-link-override">Winner inside the linked patches’ overlap</label>'
            . '<select class="form-control" id="grtrans-link-override" name="override_winner">'
            . '<option value="none">No override (cheapest that reaches)</option>'
            . '<option value="from">From method overrides to method</option>'
            . '<option value="to">To method overrides from method</option></select>'
            . '</details>'
            . '<button class="btn btn-primary" type="submit">Save transfer link</button>'
            . '<button class="btn btn-default" type="button" id="grtrans-link-cancel">Cancel</button>'
            . '</div></form>';
        $out.='<div class="grtrans-network-toolbar"><strong>Manage:</strong> ';
        foreach (GrTransportNetworkFields::ENTITIES as $choice) {
            $out.='<a class="btn btn-default" href="' . $url . '&amp;network_entity='
                . $choice . '#grtrans-network-editor">' . ucwords(str_replace('_',' ',$choice)) . '</a> ';
        }
        $out.='</div>';
        foreach (GrTransportNetworkFields::ENTITIES as $collection) {
            $out.='<h4>' . ucwords(str_replace('_',' ',$collection)) . '</h4>'
                . '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>ID</th>';
            $names=array_keys(GrTransportNetworkFields::fields($collection));
            foreach ($names as $name) $out.='<th>' . self::esc(str_replace('_',' ',$name)) . '</th>';
            if ($collection==='legs' || $collection==='transfers') $out.='<th>Last verified (UTC)</th>';
            if ($collection==='stations' || $collection==='legs') $out.='<th>FAR default / override</th>';
            $out.='<th>Actions</th></tr></thead><tbody>';
            foreach ($data[$collection] as $record) {
                $id=(int)$record[GrTransportNetworkFields::IDS[$collection]];
                $out.='<tr><td>' . $id . '</td>';
                foreach ($names as $name) $out.='<td>' . self::esc($record[$name] ?? '—') . '</td>';
                if ($collection==='stations' || $collection==='legs') {
                    if ($collection==='legs' && $record['to_station_id']!==null) {
                        $out.='<td>Station-to-station leg (no area coverage)</td>';
                    } else {
                    $outerKey=$collection==='stations' ? 'outer_radius_km' : 'area_outer_radius_km';
                    $innerKey=$collection==='stations' ? 'inner_radius_km' : 'area_radius_km';
                    $override=$record[$outerKey] ?? null;
                    $inherited=$override===null || $override==='';
                    $effective=GrTransportNetworkSettings::effectiveOuterKm($override,(float)$record[$innerKey]);
                    $out.='<td>'.self::esc($inherited ? 'Inherited global' : 'Explicit override')
                        .' · '.self::esc(number_format($effective,2,'.','')).' km</td>';
                    }
                }
                if ($collection==='legs' || $collection==='transfers') {
                    $out.='<td>' . self::esc($record['verified_at'] ?: 'Not yet verified') . '</td>';
                }
                $out.='<td><a class="btn btn-default btn-sm" href="' . $url . '&amp;network_entity='
                    . $collection . '&amp;network_edit=' . $id . '#grtrans-network-editor">Edit</a>'
                    . '<form method="post" action="' . $url . '#grtrans-network" class="grtrans-network-inline">'
                    . '<input type="hidden" name="token" value="' . $csrf . '">'
                    . '<input type="hidden" name="grtrans_network_action" value="delete">'
                    . '<input type="hidden" name="network_entity" value="' . $collection . '">'
                    . '<input type="hidden" name="network_id" value="' . $id . '">'
                    . '<button class="btn btn-danger btn-sm" onclick="return confirm(\'Delete network record?\')">Delete</button>'
                    . '</form>'
                    . (($collection==='legs' || $collection==='transfers')
                        ? '<form method="post" action="' . $url . '#grtrans-network" class="grtrans-network-inline">'
                            . '<input type="hidden" name="token" value="' . $csrf . '">'
                            . '<input type="hidden" name="network_entity" value="' . $collection . '">'
                            . '<input type="hidden" name="network_id" value="' . $id . '">'
                            . '<button class="btn btn-default btn-sm" type="submit" name="grtrans_network_action" value="verify">Confirm current</button></form>'
                        : '') . '</td></tr>';
            }
            $out.='</tbody></table></div>';
        }
        $out.='<div id="grtrans-network-editor" class="panel">';
        if ($missingTarget) {
            $back=$url.'&amp;network_entity='.self::esc($entity).'#grtrans-network-editor';
            $out.='<div class="alert alert-warning grtrans-record-error" role="alert">'
                .self::esc($module->l('The selected record no longer exists. No new record has been created.'))
                .' <a href="'.$back.'">'.self::esc($module->l('Return to this list')).'</a></div>';
        } else {
        $out.='<h4>' . ($selected ? 'Edit ' : 'Add ')
            . self::esc(ucfirst(rtrim($entity,'s'))) . '</h4>';
        if (self::$failedEntity===$entity && self::$failedMessage!=='') {
            $out.='<div class="alert alert-danger grtrans-record-error" role="alert">'
                .self::esc($module->l('Your submitted values are retained below. Correct the fields and save again.'))
                .'<details><summary>'.self::esc($module->l('Technical details')).'</summary><code>'
                .self::esc(self::$failedMessage).'</code></details></div>';
        }
        $out.='<form method="post" action="' . $url . '#grtrans-network-editor">'
            . '<input type="hidden" name="token" value="' . $csrf . '">'
            . '<input type="hidden" name="grtrans_network_action" value="save">'
            . '<input type="hidden" name="network_entity" value="' . $entity . '">'
            . '<input type="hidden" name="network_id" value="' . ($selected ? $edit : 0) . '">';
        $advancedOpen=false;
        foreach (GrTransportNetworkFields::fields($entity) as $key=>$definition) {
            if (in_array($key,['patch_precedence','override_winner'],true) && !$advancedOpen) {
                $out.='<details class="grtrans-network-advanced"><summary>Advanced · coverage and link overrides</summary>'
                    . '<p>Only applies where both eligible patches reach the address; without overrides the cheaper option wins.</p>';
                $advancedOpen=true;
            } elseif (!in_array($key,['patch_precedence','override_winner'],true) && $advancedOpen) {
                $out.='</details>';
                $advancedOpen=false;
            }
            $type=$definition[0];
            $farField=in_array($key,['outer_radius_km','area_outer_radius_km'],true);
            $label=$farField ? 'FAR outer boundary override (km)' : ucfirst(str_replace('_',' ',$key));
            $out.='<div class="form-group"><label for="grtrans-network-field-' . $key . '">'
                . self::esc($label) . '</label>';
            $value=$values[$key] ?? '';
            if ($type==='choice' || $type==='bool'
                || ($type==='id' && $key!=='id_product') || $type==='nullable_id') {
                $choices=$type==='choice' ? array_combine($definition[1],$definition[1])
                    : ($type==='bool' ? [0=>'No',1=>'Yes'] : []);
                if ($key==='patch_precedence') $choices=[
                    'none'=>'No override (cheapest wins)',
                    'override'=>'This patch overrides other overlapping patches',
                    'yield'=>'This patch yields to other overlapping patches',
                ];
                if ($key==='override_winner') $choices=[
                    'none'=>'No override (cheapest wins)',
                    'from'=>'From method overrides to method',
                    'to'=>'To method overrides from method',
                ];
                if ($type==='nullable_id') $choices['']='Direct area (no pickup station)';
                if ($key==='id_branch') {
                    foreach ($data['branches'] as $branch) {
                        $choices[(int)$branch['id_branch']]=$branch['name'];
                    }
                } elseif ($type==='id' || $type==='nullable_id') {
                    foreach ($data['stations'] as $station) {
                        $choices[(int)$station['id_station']]=$station['name'];
                    }
                }
                if ($type==='id') $choices=[''=>'Choose a station or dispatch location']+$choices;
                $out.='<select class="form-control" id="grtrans-network-field-' . $key . '" name="' . $key . '"'
                    . ($type==='id' ? ' required' : '') . '>';
                foreach ($choices as $choice=>$label) {
                    $out.='<option value="' . self::esc($choice) . '"'
                        . ((string)$choice===(string)$value ? ' selected' : '') . '>'
                        . self::esc($label) . '</option>';
                }
                $out.='</select>';
            } elseif ($type==='geojson_polygon' || $type==='geojson_line') {
                $out.='<textarea class="form-control" id="grtrans-network-field-' . $key
                    . '" name="' . $key . '" maxlength="60000" rows="4"'
                    . ' placeholder="GeoJSON geometry [lng,lat], validated; leave blank for radius">'
                    . self::esc($value) . '</textarea>';
            } else {
                $numberAttributes='';
                if ($type!=='text') {
                    $minimum=str_contains($type,'latitude') ? '-90'
                        : (str_contains($type,'longitude') ? '-180' : '0');
                    $maximum=str_contains($type,'latitude') ? 90
                        : (str_contains($type,'longitude') ? 180
                            : ($type==='nonnegative_int' ? 2147483647 : ($definition[2] ?? null)));
                    $numberAttributes=' type="number" step="'.($type==='nonnegative_int' ? '1' : 'any')
                        .'" min="'.$minimum.'"'.($maximum!==null ? ' max="'.self::esc($maximum).'"' : '');
                }
                $out.='<input class="form-control" id="grtrans-network-field-' . $key . '" name="' . $key . '"'
                    . ' value="' . self::esc($value) . '"'
                    . ($type==='text' ? ' maxlength="' . (int)$definition[1] . '"' : '')
                    . $numberAttributes
                    . ($farField ? ' placeholder="Inherit global FAR boundary"' : '') . '>';
            }
            if ($farField) {
                $inner=$key==='outer_radius_km' ? (float)$values['inner_radius_km'] : (float)$values['area_radius_km'];
                $inherited=$value===null || $value==='';
                $effective=GrTransportNetworkSettings::effectiveOuterKm($value,$inner);
                $out.='<p class="help-block">'
                    .self::esc($inherited ? 'Inheriting global FAR default' : 'Explicit FAR override')
                    .' · effective '.self::esc(number_format($effective,2,'.','')).' km. '
                    .'Clear the field and save to inherit the current global default ('
                    .self::esc(GrTransportNetworkSettings::get('station_outer_km')).' km). '
                    .'An explicit override must be at least the normal inner radius.</p>'
                    .'<button type="button" class="btn btn-default btn-sm grtrans-clear-far"'
                    .' data-target="grtrans-network-field-'.$key.'">Clear override · inherit global</button>';
            }
            $out.='</div>';
        }
        if ($advancedOpen) $out.='</details>';
        $out.='<button class="btn btn-primary" name="grtrans_network_submit" value="1">'
            . ($selected ? 'Save changes' : 'Create record') . '</button></form>';
        if ($entity==='branch_stock') {
            require_once __DIR__ . '/NetworkCatalogOptions.php';
            $catalog=GrTransportNetworkCatalogOptions::read((int)($values['id_product'] ?? 0));
            $out.='<script type="application/json" id="grtrans-catalog-options">'
                .json_encode($catalog,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)
                .'</script>';
        }
        }
        $out.='</div>';
        $tr=static fn(string $label): string => self::esc($module->l($label));
        $notice=(string)Configuration::get('GRTRANS_EXTENDED_NOTICE');
        $out.='<section class="grtrans-notice-panel"><fieldset class="grtrans-control-group">'
            .'<legend>'.$tr('Warning for extended-service destinations').'</legend>'
            .'<p class="grtrans-context-note">'.$tr('This notice appears when a customer chooses an extended service area.').'</p>'
            .'<form method="post" class="grtrans-autosave" data-grtrans-autosave'
            .' data-state-saving="'.$tr('Saving…').'" data-state-saved="'.$tr('Saved').'"'
            .' data-state-unsaved="'.$tr('Unsaved changes').'"'
            .' data-state-failed="'.$tr('Could not save. Check the notice and try again.').'"'
            .' action="'.$url.'#grtrans-network">'
            .'<input type="hidden" name="token" value="'.$csrf.'">'
            .'<input type="hidden" name="grtrans_network_action" value="save_extended_notice">'
            .'<label for="grtrans-extended-notice">'.$tr('Customer warning').'</label>'
            .'<textarea class="form-control grtrans-size-lt" id="grtrans-extended-notice"'
            .' name="extended_notice" maxlength="1200" rows="3">'.self::esc($notice).'</textarea>'
            .'<div class="grtrans-form-footer"><output class="grtrans-save-state" data-grtrans-save-status aria-live="polite">'
            .$tr('Saved').'</output><span class="grtrans-context-note">'
            .$tr($notice==='' ? 'Using the built-in message.' : 'Using your custom message.').'</span>'
            .'<button type="submit" class="btn btn-default grtrans-fallback-submit">'.$tr('Save notice').'</button>'
            .'</div></form></fieldset></section>';
        $out.='<section class="grtrans-api-panel"><fieldset class="grtrans-control-group">'
            .'<legend>'.$tr('Developer API access').'</legend>'
            .'<p class="grtrans-context-note">'.$tr('Read access cannot change data; write access can. Replacing a token disables the previous one.').'</p>';
        foreach (['read','write'] as $scope) {
            $configured=(string)Configuration::get($scope==='read' ? 'GRTRANS_NETWORK_READ_HASH' : 'GRTRANS_NETWORK_WRITE_HASH')!=='';
            $scopeLabel=$tr($scope==='read' ? 'Read access' : 'Write access');
            $confirm=self::esc(sprintf($module->l('Replace the %s API token? Existing clients will need the new token.'),$scope));
            $out.='<div class="grtrans-api-row"><span class="grtrans-api-scope">'.$scopeLabel.'</span>'
                .'<span class="grtrans-context-note">'.$tr($configured ? 'Configured' : 'Not configured').'</span>'
                .'<form method="post" action="'.$url.'#grtrans-network"'
                .' onsubmit="return confirm(&quot;'.$confirm.'&quot;)">'
                .'<input type="hidden" name="token" value="'.$csrf.'">'
                .'<button type="submit" name="grtrans_network_action" value="rotate_'.$scope.'"'
                .' class="btn btn-default btn-sm" title="'.$tr('Replace token').'" aria-label="'.$tr('Replace token').'">'
                .'<span class="material-icons" aria-hidden="true">autorenew</span></button></form></div>';
        }
        $out.='</fieldset></section>';
        $out.=GrTransportNetworkSettings::render($url,$csrf,$module);
        $out.='<script src="' . $base . 'vendor/leaflet/leaflet.js"></script>'
            . '<script src="' . $base . 'js/coverage-geometry.js"></script>'
            . '<script src="' . $base . 'js/network-admin.js"></script></section>';
        return $out;
    }

    private static function esc($value): string
    {
        return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
