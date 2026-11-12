<?php
require_once __DIR__ . '/NetworkQuote.php';
require_once __DIR__ . '/NetworkRepository.php';
require_once __DIR__ . '/BranchAvailability.php';

/** Read-only test bench using the checkout resolver, never a second pricing algorithm. */
final class GrTransportNetworkSimulator
{
    public static function simulate(array $flat,array $network,array $params,string $extendedNotice=''): array
    {
        $stationId=(int)($params['station_id'] ?? 0);
        $cart=$params['cart'] ?? null;
        $origins=null;
        $byOrigin=[];
        if (!empty($network['branches'])) {
            $eligible=GrTransportBranchAvailability::eligible(
                $cart,$network['branches'],$network['branch_stock'] ?? []
            );
            $origins=$eligible['origin_ids'];
            $byOrigin=$eligible['by_origin'];
        }
        $parcel=is_object($cart) ? GrTransportNetworkTariff::cartMeasurements($cart) : [];
        foreach (is_object($cart) ? [] : ['weight'=>'weight_kg','volume'=>'volume_m3'] as $field=>$target) {
            if (isset($params[$field]) && $params[$field]!=='' && is_numeric($params[$field])
                && is_finite((float)$params[$field]) && (float)$params[$field]>0) {
                $parcel[$target]=(float)$params[$field];
            }
        }
        if ($stationId>0) {
            return GrTransportNetworkQuote::forStation($network,$stationId,$origins,$parcel,$byOrigin);
        }
        $postcode=trim((string)($params['postcode'] ?? ''));
        $city=trim((string)($params['city'] ?? ''));
        $location=null;
        if (isset($params['lat'],$params['lng']) && $params['lat']!=='' && $params['lng']!=='') {
            $location=GrTransportNetworkQuote::validLocation([$params['lat'],$params['lng']]);
        }
        return GrTransportNetworkQuote::resolve($flat,$network,$postcode,$city,$location,$origins,$extendedNotice,$parcel,$byOrigin);
    }

    public static function render($module): string
    {
        $url=Context::getContext()->link->getAdminLink('AdminModules',true,[],[
            'configure'=>$module->name,'module_name'=>$module->name,
        ]);
        $esc=static fn($value): string => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $tr=static fn(string $value): string => $esc($module->l($value));
        $post=static function (string $name): string {
            $value=$_POST[$name] ?? '';
            return is_scalar($value) ? (string)$value : '';
        };
        $stations=GrTransportNetworkRepository::all('stations');
        // UI suggestions only. Existing quote and cart validation paths remain authoritative.
        $citySuggestions=Db::getInstance()->executeS('SELECT DISTINCT city FROM `'. _DB_PREFIX_
            .'grtrans_route` WHERE city <> \'\' ORDER BY city ASC LIMIT 80') ?: [];
        $cartSuggestions=Db::getInstance()->executeS('SELECT id_cart, date_upd FROM `'. _DB_PREFIX_
            .'cart` WHERE id_shop = '.(int)GrTransportNetworkRepository::shopId()
            .' ORDER BY date_upd DESC LIMIT 30') ?: [];
        $selected=(int)$post('station_id');
        $form='<section class="panel grtrans-simulator" id="grtrans-simulator">'
            .'<div class="grtrans-section-heading"><h3>'.$tr('Test a transport price').'</h3></div>'
            .'<form method="post" data-grtrans-simulator action="'.$esc($url).'#grtrans-simulator">'
            .'<input type="hidden" name="token" value="'.$esc(Tools::getAdminTokenLite('AdminModules')).'">'
            .'<input type="hidden" name="grtrans_simulate" value="1">'
            .'<fieldset class="grtrans-control-group grtrans-simulator-destination"><legend>'.$tr('Destination').'</legend>'
            .'<div class="grtrans-segmented" role="group" aria-label="'.$tr('Destination method').'">'
            .'<button type="button" data-sim-method="address" aria-pressed="'.($selected===0?'true':'false').'">'
            .'<span class="material-icons" aria-hidden="true">location_on</span>'.$tr('Address').'</button>'
            .'<button type="button" data-sim-method="station" aria-pressed="'.($selected>0?'true':'false').'">'
            .'<span class="material-icons" aria-hidden="true">place</span>'.$tr('Station').'</button></div>'
            .'<div data-sim-fields="station"'.($selected===0?' hidden':'').'><label for="grtrans-sim-station">'.$tr('Collection station').'</label>'
            .'<select name="station_id" class="form-control grtrans-size-l" id="grtrans-sim-station">'
            .'<option value="0">'.$tr('Choose a station').'</option>';
        foreach ($stations as $station) {
            $id=(int)$station['id_station'];
            $form.='<option value="'.$id.'"'.($selected===$id?' selected':'').'>'.$esc($station['name']).'</option>';
        }
        $form.='</select></div><div data-sim-fields="address"'.($selected>0?' hidden':'').'>'
            .'<div class="grtrans-input-row">';
        foreach (['postcode'=>['Destination postcode','grtrans-size-s'],
            'city'=>['Destination city','grtrans-size-m']] as $key=>$info) {
            [$label,$size]=$info;
            $form.='<div class="grtrans-form-field"><label for="grtrans-sim-'.$key.'">'.$tr($label).'</label>'
                .'<input class="form-control '.$size.'" id="grtrans-sim-'.$key.'" name="'.$key.'"'
                .' value="'.$esc($post($key)).'" maxlength="128"'
                .($key==='postcode'?' inputmode="numeric"':'')
                .($key==='city'?' list="grtrans-city-suggestions"':'').'></div>';
        }
        $form.='</div><datalist id="grtrans-city-suggestions">';
        foreach ($citySuggestions as $row) $form.='<option value="'.$esc($row['city']).'">';
        $form.='</datalist><details class="grtrans-advanced"><summary>'.$tr('Advanced destination coordinates').'</summary>'
            .'<p class="grtrans-context-note">'.$tr('A postcode alone does not supply map coordinates. Enter confirmed coordinates only.').'</p>'
            .'<div class="grtrans-input-row">';
        foreach (['lat'=>['Latitude','-90','90'],'lng'=>['Longitude','-180','180']] as $key=>$info) {
            [$label,$min,$max]=$info;
            $form.='<div class="grtrans-form-field"><label for="grtrans-sim-'.$key.'">'.$tr($label).'</label>'
                .'<input type="number" step="any" min="'.$min.'" max="'.$max.'" class="form-control grtrans-size-s"'
                .' id="grtrans-sim-'.$key.'" name="'.$key.'" value="'.$esc($post($key)).'"></div>';
        }
        $form.='</div></details></div></fieldset>'
            .'<fieldset class="grtrans-control-group"><legend>'.$tr('Parcel').'</legend><div class="grtrans-input-row">';
        foreach (['weight'=>['Parcel weight','kg'],'volume'=>['Parcel volume','m³']] as $key=>$info) {
            [$label,$unit]=$info;
            $form.='<div class="grtrans-form-field"><label for="grtrans-sim-'.$key.'">'.$tr($label).'</label>'
                .'<div class="grtrans-value-control"><input type="number" min="0" step="any"'
                .' class="form-control grtrans-size-s" id="grtrans-sim-'.$key.'" name="'.$key.'"'
                .' value="'.$esc($post($key)).'"><span class="grtrans-unit">'.$esc($unit).'</span></div></div>';
        }
        $form.='</div><details class="grtrans-advanced"><summary>'.$tr('Use an existing cart').'</summary>'
            .'<label for="grtrans-sim-cart_id">'.$tr('Cart number').'</label>'
            .'<input type="number" min="1" step="1" class="form-control grtrans-size-m"'
            .' id="grtrans-sim-cart_id" name="cart_id" list="grtrans-cart-suggestions" value="'.$esc($post('cart_id')).'">'
            .'<datalist id="grtrans-cart-suggestions">';
        foreach ($cartSuggestions as $row) {
            $form.='<option value="'.(int)$row['id_cart'].'" label="'
                .$tr('Cart').' #'.(int)$row['id_cart'].' · '.$esc($row['date_upd']).'">';
        }
        $form.='</datalist></details></fieldset>'
            .'<div class="grtrans-form-footer"><button type="submit" class="btn btn-primary">'.$tr('Calculate test price').'</button></div>'
            .'</form>';
        if (!isset($_POST['grtrans_simulate'])) return $form.'</section>';
        $data=[];
        foreach (['station_id','postcode','city','lat','lng','weight','volume','cart_id'] as $key) $data[$key]=$post($key);
        foreach (['weight','volume'] as $key) {
            if ($data[$key]!=='' && (!is_numeric($data[$key]) || (float)$data[$key]<0
                || !is_finite((float)$data[$key]))) {
                return $form.'<div class="alert alert-danger" role="alert">'
                    .$tr('Enter a valid non-negative parcel weight and volume.').'</div></section>';
            }
        }
        try {
            $flat=Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'grtrans_route`') ?: [];
            $network=GrTransportNetworkRepository::snapshot();
            $notice=(string)Configuration::get('GRTRANS_EXTENDED_NOTICE');
            if ($data['cart_id']!=='') {
                if (!ctype_digit($data['cart_id']) || (int)$data['cart_id']<=0) {
                    throw new InvalidArgumentException('Enter a valid cart ID');
                }
                $cart=new Cart((int)$data['cart_id']);
                if (!Validate::isLoadedObject($cart)
                    || (int)($cart->id_shop ?? 0)!==GrTransportNetworkRepository::shopId()) {
                    throw new InvalidArgumentException('Cart does not belong to this shop');
                }
                $data['cart']=$cart;
            }
            $result=self::simulate($flat,$network,$data,$notice);
            if ($result['status']!=='quoted' || !$result['quote']) {
                $reason=$tr('No price available for these inputs. Check the destination, available services and parcel details.');
                return $form.'<div class="alert alert-warning" role="status">'.$reason
                    .'<details><summary>'.$tr('Technical status').'</summary><code>'.$esc($result['status']).'</code></details>'
                    .'</div></section>';
            }
            $quote=$result['quote'];
            $form.='<section class="grtrans-simulator-result" data-grtrans-result aria-live="polite">'
                .'<h4>'.$tr('Test result').'</h4>'
                .'<p class="grtrans-quote-total">'
                .(!empty($quote['estimate'])
                    ? $tr('Estimated price').' · EUR '.$esc(number_format((float)$quote['estimate_min'],2))
                        .'–'.$esc(number_format((float)$quote['estimate_max'],2))
                        .'<span class="grtrans-quote-note">'.$tr('Not charged at checkout').'</span>'
                    : $tr('Quoted price').' · EUR '.$esc(number_format((float)$quote['price'],2)))
                .'</p><dl class="grtrans-result-facts">'
                .'<dt>'.$tr('Collection or delivery point').'</dt><dd>'.$esc($quote['station_name']).'</dd>'
                .'<dt>'.$tr('Service area').'</dt><dd>'.$esc($quote['ring'] ?? 'manual').'</dd>'
                .'<dt>'.$tr('Sending station').'</dt><dd>'.$esc($quote['origin_name'] ?? '').'</dd>'
                .(!empty($quote['branch_id'])?'<dt>'.$tr('Dispatch location').'</dt><dd>'
                    .$esc($quote['branch_name']).' (#'.(int)$quote['branch_id'].')</dd>':'')
                .'</dl>';
            $path=$quote['network_path']['edges'] ?? [];
            if ($path) {
                $form.='<div class="table-responsive"><table class="table table-condensed">'
                    .'<caption>'.$tr('Transport steps').'</caption><thead><tr><th>'.$tr('Step').'</th>'
                    .'<th>'.$tr('Transport method').'</th><th>'.$tr('From and to').'</th><th>EUR</th></tr></thead><tbody>';
                foreach ($path as $i=>$edge) {
                    $form.='<tr><td>'.($i+1).' · '.$esc($edge['kind']).'</td>'
                        .'<td>'.$esc($edge['mode']).' '.$esc($edge['operator'] ?? '').'</td>'
                        .'<td>'.$esc($edge['from']).' → '.$esc($edge['to'] ?? ($edge['area_name'] ?? 'address')).'</td>'
                        .'<td>'.$esc(number_format((float)$edge['price'],2)).'</td></tr>';
                }
                $form.='</tbody></table></div>';
            } else {
                $form.='<p>'.$tr('No intermediate transport steps.').'</p>';
            }
            if (!empty($quote['delivery_window_hours'])) {
                $window=$quote['delivery_window_hours'];
                $form.='<p>'.$tr('Estimated delivery time').': '.$esc($window['min_hours']).'–'.$esc($window['max_hours'])
                    .' '.$tr('hours')
                    .(!empty($window['transfer_time_unknown'])?' · '.$tr('Transfer travel time is not included.'):'').'</p>';
            }
            $form.='<p class="grtrans-context-note">'.$tr('Parcel details').': '
                .$esc(isset($data['cart']) ? $module->l('From selected cart') : ($data['weight'] ?: $module->l('Not specified')))
                .' kg, '.$esc($data['volume'] ?: $module->l('Not specified')).' m³. '
                .$tr('Missing measurements use the configured flat-price fallback.').'</p>'
                .'</section>';
        } catch (Throwable $e) {
            $form.='<div class="alert alert-danger" role="alert">'.$tr('The test could not be completed. Check the inputs and try again.')
                .'<details><summary>'.$tr('Technical details').'</summary><code>'.$esc($e->getMessage()).'</code></details></div>';
        }
        return $form.'</section>';
    }
}
