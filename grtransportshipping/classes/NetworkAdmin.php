<?php
require_once __DIR__ . '/NetworkApi.php';
require_once __DIR__ . '/NetworkGraph.php';
require_once __DIR__ . '/NetworkFreshness.php';

/** Operator interface over the exact NetworkRepository/NetworkApi contract. */
final class GrTransportNetworkAdmin
{
    public static function handle($module): string
    {
        if (!isset($_POST['grtrans_network_action'])) return '';
        $employeeId=(int)(Context::getContext()->employee->id ?? 0);
        $container=\PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        $manager=$container ? $container->get(\PrestaShopBundle\Security\Admin\UserTokenManager::class) : null;
        if ($employeeId<=0 || empty($_POST['token']) || !is_string($_POST['token'])
            || !$manager || !$manager->isTokenValid()) {
            return '<div class="alert alert-danger">Network action rejected: invalid administrator token.</div>';
        }
        try {
            $action=(string)$_POST['grtrans_network_action'];
            if ($action==='save_operational_settings') {
                GrTransportNetworkSettings::save($_POST);
                return '<div class="alert alert-success">Network operating defaults saved.</div>';
            }
            if ($action==='save_extended_notice') {
                $notice=$_POST['extended_notice'] ?? '';
                if (!is_string($notice) || mb_strlen($notice, 'UTF-8')>1200) {
                    throw new InvalidArgumentException('Extended pickup notice must contain at most 1200 characters');
                }
                if (!Configuration::updateValue('GRTRANS_EXTENDED_NOTICE',trim($notice))) {
                    throw new RuntimeException('Cannot save extended pickup notice');
                }
                return '<div class="alert alert-success">Extended pickup notice saved.</div>';
            }
            if ($action==='rotate_read' || $action==='rotate_write') {
                $scope=$action==='rotate_read' ? 'read' : 'write';
                $token=GrTransportNetworkApi::rotate($scope);
                return '<div class="alert alert-warning" role="status">New ' . self::esc($scope)
                    . ' API token (copy now; it is not stored in plaintext): <code>'
                    . self::esc($token) . '</code></div>';
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
                ]);
            } else throw new InvalidArgumentException('Unknown network action');
            $message=($action==='link' || ($action==='save' && $id===0))
                ? 'Network record created.' : 'Network record updated.';
            return '<div class="alert alert-success" role="status">' . $message . '</div>';
        } catch (Throwable $error) {
            return '<div class="alert alert-danger" role="alert">Network action failed: '
                . self::esc($error->getMessage()) . '</div>';
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
        $edit=isset($_GET['network_edit']) && is_scalar($_GET['network_edit'])
            ? (int)$_GET['network_edit'] : 0;
        $selected=$edit>0 ? GrTransportNetworkRepository::find($entity,$edit) : null;
        $values=array_replace(GrTransportNetworkFields::defaults($entity),
            $selected ? array_intersect_key($selected,GrTransportNetworkFields::fields($entity)) : []);
        $route=GrTransportNetworkGraph::routes($data['stations'],$data['legs'],$data['transfers']);
        $cost=[];
        foreach ($route['cost'] as $id=>$amount) {
            if (is_finite($amount)) $cost[(string)$id]=round($amount,2);
        }
        $mapData=array_intersect_key($data,array_flip(['stations','legs','transfers']));
        $mapConfig=json_encode($mapData+['cost'=>$cost,'nearbyGapKm'=>GrTransportNetworkSettings::get('nearby_gap_km'),
            'leafletImagePath'=>_MODULE_DIR_ . $module->name . '/views/vendor/leaflet/images/'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $base=self::esc((_MODULE_DIR_ . $module->name . '/')) . 'views/';
        $out='<section class="panel" id="grtrans-network">'
            . '<h3>Transport network · coverage map</h3>'
            . '<p>Stations have a strong inner ring (normal service) and a lighter outer ring (long walk). '
            . 'Click a station, choose Link, and click its destination. Long-distance links require a type and price.</p>'
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
            $out.='<th>Actions</th></tr></thead><tbody>';
            foreach ($data[$collection] as $record) {
                $id=(int)$record[GrTransportNetworkFields::IDS[$collection]];
                $out.='<tr><td>' . $id . '</td>';
                foreach ($names as $name) $out.='<td>' . self::esc($record[$name] ?? '—') . '</td>';
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
        $out.='<div id="grtrans-network-editor" class="panel"><h4>'
            . ($selected ? 'Edit ' : 'Add ') . self::esc(ucfirst(rtrim($entity,'s'))) . '</h4>'
            . '<form method="post" action="' . $url . '#grtrans-network-editor">'
            . '<input type="hidden" name="token" value="' . $csrf . '">'
            . '<input type="hidden" name="grtrans_network_action" value="save">'
            . '<input type="hidden" name="network_entity" value="' . $entity . '">'
            . '<input type="hidden" name="network_id" value="' . ($selected ? $edit : 0) . '">';
        foreach (GrTransportNetworkFields::fields($entity) as $key=>$definition) {
            $type=$definition[0];
            $out.='<div class="form-group"><label for="grtrans-network-field-' . $key . '">'
                . self::esc(ucfirst(str_replace('_',' ',$key))) . '</label>';
            $value=$values[$key] ?? '';
            if ($type==='choice' || $type==='bool'
                || ($type==='id' && $key!=='id_product') || $type==='nullable_id') {
                $choices=$type==='choice' ? array_combine($definition[1],$definition[1])
                    : ($type==='bool' ? [0=>'No',1=>'Yes'] : []);
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
                $out.='<select class="form-control" id="grtrans-network-field-' . $key . '" name="' . $key . '">';
                foreach ($choices as $choice=>$label) {
                    $out.='<option value="' . self::esc($choice) . '"'
                        . ((string)$choice===(string)$value ? ' selected' : '') . '>'
                        . self::esc($label) . '</option>';
                }
                $out.='</select>';
            } else {
                $out.='<input class="form-control" id="grtrans-network-field-' . $key . '" name="' . $key . '"'
                    . ' value="' . self::esc($value) . '"'
                    . ($type==='text' ? ' maxlength="' . (int)$definition[1] . '"' : '')
                    . ($type!=='text' ? ' type="number" step="any" min="0"' : '') . '>';
            }
            $out.='</div>';
        }
        $out.='<button class="btn btn-primary" name="grtrans_network_submit" value="1">'
            . ($selected ? 'Save changes' : 'Create record') . '</button></form></div>';
        $out.='<h4>Extended pickup notice</h4><p>This warning is displayed at checkout when a destination is served only by a station outer ring.</p>'
            . '<form method="post" action="' . $url . '#grtrans-network">'
            . '<input type="hidden" name="token" value="' . $csrf . '">'
            . '<input type="hidden" name="grtrans_network_action" value="save_extended_notice">'
            . '<textarea class="form-control" name="extended_notice" maxlength="1200" rows="3">'
            . self::esc(Configuration::get('GRTRANS_EXTENDED_NOTICE')) . '</textarea>'
            . '<button class="btn btn-primary">Save extended pickup notice</button></form>';
        $out.='<h4>Programmatic API credentials</h4><p>Issue two independently revocable tokens. '
            . 'Write tokens grant network administration through the API; read tokens cannot mutate data. '
            . 'The plaintext token is shown once after rotation; store it securely.</p>';
        foreach (['read','write'] as $scope) {
            $out.='<form method="post" action="' . $url . '#grtrans-network" class="grtrans-network-inline">'
                . '<input type="hidden" name="token" value="' . $csrf . '">'
                . '<button name="grtrans_network_action" value="rotate_' . $scope
                . '" class="btn btn-warning">Generate / revoke ' . $scope . ' token</button></form> ';
        }
        $out.=GrTransportNetworkSettings::render($url,$csrf);
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
