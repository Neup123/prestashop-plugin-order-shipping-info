<?php
require_once __DIR__ . '/NetworkQuote.php';
require_once __DIR__ . '/NetworkRepository.php';

/** Back-office test bench using the exact checkout resolver, not a second algorithm. */
final class GrTransportNetworkSimulator
{
    public static function simulate(array $flat,array $network,array $params,string $extendedNotice=''): array
    {
        $stationId=(int)($params['station_id'] ?? 0);
        $parcel=[];
        foreach (['weight'=>'weight_kg','volume'=>'volume_m3'] as $field=>$target) {
            if (isset($params[$field]) && $params[$field]!=='' && is_numeric($params[$field])
                && is_finite((float)$params[$field]) && (float)$params[$field]>0) {
                $parcel[$target]=(float)$params[$field];
            }
        }
        if ($stationId>0) {
            return GrTransportNetworkQuote::forStation($network,$stationId,null,$parcel);
        }
        $postcode=trim((string)($params['postcode'] ?? ''));
        $city=trim((string)($params['city'] ?? ''));
        $location=null;
        if (isset($params['lat'],$params['lng']) && $params['lat']!=='' && $params['lng']!=='') {
            $location=GrTransportNetworkQuote::validLocation([$params['lat'],$params['lng']]);
        }
        return GrTransportNetworkQuote::resolve($flat,$network,$postcode,$city,$location,null,$extendedNotice,$parcel);
    }

    public static function render($module): string
    {
        $url=Context::getContext()->link->getAdminLink('AdminModules',true,[],[
            'configure'=>$module->name,'module_name'=>$module->name,
        ]);
        $esc=static function ($value): string {
            return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        };
        $form='<section class="panel" id="grtrans-simulator"><h3>Network quote simulator</h3>'
            . '<p>Calculate the same eligible path as checkout. Select a station directly or supply a postcode/city '
            . 'and confirmed destination coordinates. A postcode alone is not geocoded by this module.</p>'
            . '<form method="get" action="' . $esc($url) . '#grtrans-simulator">'
            . '<input type="hidden" name="configure" value="grtransportshipping">'
            . '<input type="hidden" name="module_name" value="grtransportshipping">'
            . '<input type="hidden" name="grtrans_simulate" value="1">'
            . '<div class="form-group"><label for="grtrans-sim-station">Destination station (optional)</label>'
            . '<select name="station_id" class="form-control" id="grtrans-sim-station">'
            . '<option value="0">Resolve destination from address</option>';
        $stations=GrTransportNetworkRepository::all('stations');
        $selected=(int)(isset($_GET['station_id']) && is_scalar($_GET['station_id']) ? $_GET['station_id'] : 0);
        foreach ($stations as $station) {
            $id=(int)$station['id_station'];
            $form.='<option value="' . $id . '"' . ($selected===$id ? ' selected' : '') . '>'
                . $esc($station['name']) . '</option>';
        }
        $form.='</select></div>';
        foreach (['postcode'=>'Postcode','city'=>'City','lat'=>'Latitude (if known)',
            'lng'=>'Longitude (if known)','weight'=>'Parcel weight (kg)',
            'volume'=>'Parcel volume (m³)'] as $key=>$label) {
            $value=$_GET[$key] ?? '';
            if (!is_scalar($value)) $value='';
            $form.='<div class="form-group"><label for="grtrans-sim-' . $key . '">'
                . $esc($label) . '</label><input class="form-control" id="grtrans-sim-'
                . $key . '" name="' . $key . '" value="' . $esc($value)
                . '" maxlength="128"></div>';
        }
        $form.='<button class="btn btn-primary">Simulate quote</button></form>';
        if (!isset($_GET['grtrans_simulate'])) return $form . '</section>';
        $data=[];
        foreach (['station_id','postcode','city','lat','lng','weight','volume'] as $key) {
            $data[$key]=isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string)$_GET[$key] : '';
        }
        foreach (['weight','volume'] as $value) {
            if ($data[$value]!=='' && (!is_numeric($data[$value]) || (float)$data[$value]<0
                || !is_finite((float)$data[$value]))) {
                return $form . '<div class="alert alert-danger">Invalid parcel measurements.</div></section>';
            }
        }
        try {
            $flat=Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [];
            $network=GrTransportNetworkRepository::snapshot();
            $notice=(string)Configuration::get('GRTRANS_EXTENDED_NOTICE');
            $result=self::simulate($flat,$network,$data,$notice);
            if ($result['status']!=='quoted' || !$result['quote']) {
                return $form . '<div class="alert alert-warning">No quote: '
                    . $esc($result['status']) . '</div></section>';
            }
            $quote=$result['quote'];
            $form.='<div class="alert alert-success"><strong>'
                . (!empty($quote['estimate'])
                    ? 'Estimated EUR ' . $esc(number_format((float)$quote['estimate_min'],2))
                        . '–' . $esc(number_format((float)$quote['estimate_max'],2))
                        . ' — not charged at checkout'
                    : 'Quoted EUR ' . $esc(number_format((float)$quote['price'],2)))
                . '</strong>'
                . '<p>Pickup/delivery: ' . $esc($quote['station_name']) . '</p>'
                . '<p>Destination ring: ' . $esc($quote['ring'] ?? 'manual') . '</p>'
                . '<p>Origin hub: ' . $esc($quote['origin_name'] ?? '') . '</p></div>';
            $path=$quote['network_path']['edges'] ?? [];
            if ($path) {
                $form.='<table class="table"><thead><tr><th>Step</th><th>Mode / service</th><th>From → To</th><th>EUR</th></tr></thead><tbody>';
                foreach ($path as $i=>$edge) {
                    $form.='<tr><td>' . ($i+1) . ' · ' . $esc($edge['kind']) . '</td>'
                        . '<td>' . $esc($edge['mode']) . ' ' . $esc($edge['operator'] ?? '') . '</td>'
                        . '<td>' . $esc($edge['from']) . ' → '
                        . $esc($edge['to'] ?? ($edge['area_name'] ?? 'address')) . '</td>'
                        . '<td>' . $esc(number_format((float)$edge['price'],2)) . '</td></tr>';
                }
                $form.='</tbody></table>';
            } else {
                $form.='<p>Manual flat Route or origin station pickup; no travel legs.</p>';
            }
            $form.='<p>Parcel measurements entered: ' . $esc($data['weight'] ?: 'not specified')
                . ' kg, ' . $esc($data['volume'] ?: 'not specified') . ' m³. '
                . 'Per-weight and per-volume tariffs use these values; missing measurements trigger the configured flat fallback.</p>';
        } catch (Throwable $e) {
            $form.='<div class="alert alert-danger">Simulation failed: '
                . $esc($e->getMessage()) . '</div>';
        }
        return $form . '</section>';
    }
}
