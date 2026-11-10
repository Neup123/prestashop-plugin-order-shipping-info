<?php
require_once __DIR__ . '/NetworkSettings.php';
require_once __DIR__ . '/NetworkGeometry.php';
/**
 * Shared network-data contract for API, map editor, importer and quote engine.
 * Prices are EUR. Coordinates are WGS84, radii are kilometres.
 */
final class GrTransportNetworkFields
{
    public const ENTITIES = ['stations', 'legs', 'transfers', 'branches', 'branch_stock'];
    public const TABLES = ['stations'=>'grtrans_station', 'legs'=>'grtrans_leg', 'transfers'=>'grtrans_transfer', 'branches'=>'grtrans_branch', 'branch_stock'=>'grtrans_branch_stock'];
    public const IDS = ['stations'=>'id_station', 'legs'=>'id_leg', 'transfers'=>'id_transfer', 'branches'=>'id_branch', 'branch_stock'=>'id_branch_stock'];

    public static function fields(string $entity): array
    {
        switch ($entity) {
            case 'stations':
                return [
                    'name'=>['text',190], 'type'=>['choice',['bus','ferry','airport','other']],
                    'address_text'=>['text',500], 'lat'=>['latitude'], 'lng'=>['longitude'],
                    'inner_radius_km'=>['range',0,GrTransportNetworkSettings::get('max_coverage_km')], 'outer_radius_km'=>['nullable_range',0,GrTransportNetworkSettings::get('max_coverage_km')],
                    'is_origin_hub'=>['bool'],
                    'coverage_kind'=>['choice',['radius','postal_polygon','walking_isochrone']],
                    'coverage_geojson'=>['geojson_polygon'],
                    'patch_precedence'=>['choice',['none','override','yield']],
                ];
            case 'legs':
                return [
                    'from_station_id'=>['id'], 'to_station_id'=>['nullable_id'],
                    'mode'=>['choice',['bus','ferry','flight','other']],
                    'operator_label'=>['text',190], 'price'=>['range',0,GrTransportNetworkSettings::get('max_freight_eur')],
                    'tariff_type'=>['choice',['flat','weight','volume']], 'rate_per_unit'=>['range',0,GrTransportNetworkSettings::get('max_freight_eur')],
                    'estimate'=>['bool'], 'estimate_low_factor'=>['range',0,10],
                    'estimate_high_factor'=>['range',0,10],
                    'bidirectional'=>['bool'],
                    'lead_hours'=>['range',0,GrTransportNetworkSettings::get('max_leg_hours')],
                    'preparation_hours'=>['range',0,GrTransportNetworkSettings::get('max_leg_hours')],
                    'transit_min_hours'=>['range',0,GrTransportNetworkSettings::get('max_leg_hours')],
                    'transit_max_hours'=>['range',0,GrTransportNetworkSettings::get('max_leg_hours')],
                    'area_name'=>['text',190], 'area_lat'=>['nullable_latitude'],
                    'area_lng'=>['nullable_longitude'], 'area_radius_km'=>['range',0,GrTransportNetworkSettings::get('max_coverage_km')],
                    'area_outer_radius_km'=>['nullable_range',0,GrTransportNetworkSettings::get('max_coverage_km')],
                    'area_coverage_kind'=>['choice',['radius','postal_polygon','walking_isochrone']],
                    'area_coverage_geojson'=>['geojson_polygon'],
                    'route_geojson'=>['geojson_line'],
                    'patch_precedence'=>['choice',['none','override','yield']],
                    'override_winner'=>['choice',['none','from','to']],
                ];
            case 'branches':
                return [
                    'name'=>['text',190], 'origin_station_id'=>['id'],
                ];
            case 'branch_stock':
                return [
                    'id_branch'=>['id'], 'id_product'=>['id'],
                    'id_product_attribute'=>['nonnegative_int'], 'quantity'=>['nonnegative_int'],
                ];
            case 'transfers':
                return [
                    'from_station_id'=>['id'], 'to_station_id'=>['id'],
                    'connection_type'=>['text',64], 'price'=>['range',0,100000],
                    'override_winner'=>['choice',['none','from','to']],
                ];
        }
        throw new InvalidArgumentException('Unknown network entity');
    }

    public static function defaults(string $entity): array
    {
        switch ($entity) {
            case 'stations': return [
                'name'=>'', 'type'=>'bus', 'address_text'=>'', 'lat'=>null, 'lng'=>null,
                'inner_radius_km'=>GrTransportNetworkSettings::get('station_inner_km'),
                'outer_radius_km'=>null, 'is_origin_hub'=>0,
                'coverage_kind'=>'radius','coverage_geojson'=>'',
                'patch_precedence'=>'none',
            ];
            case 'legs': return [
                'from_station_id'=>0, 'to_station_id'=>null, 'mode'=>'bus',
                'operator_label'=>'', 'price'=>0, 'tariff_type'=>'flat','rate_per_unit'=>0,
                'estimate'=>0,'estimate_low_factor'=>GrTransportNetworkSettings::get('estimate_low_factor'),
                'estimate_high_factor'=>GrTransportNetworkSettings::get('estimate_high_factor'),
                'bidirectional'=>0,
                'lead_hours'=>0, 'preparation_hours'=>0,
                'transit_min_hours'=>0, 'transit_max_hours'=>0,
                'area_name'=>'', 'area_lat'=>null, 'area_lng'=>null, 'area_radius_km'=>0,
                'area_outer_radius_km'=>null,
                'area_coverage_kind'=>'radius','area_coverage_geojson'=>'','route_geojson'=>'',
                'patch_precedence'=>'none','override_winner'=>'none',
            ];
            case 'branches': return ['name'=>'', 'origin_station_id'=>0];
            case 'branch_stock': return [
                'id_branch'=>0,'id_product'=>0,'id_product_attribute'=>0,'quantity'=>0,
            ];
            case 'transfers': return [
                'from_station_id'=>0, 'to_station_id'=>0, 'connection_type'=>'walk', 'price'=>0,
                'override_winner'=>'none',
            ];
        }
        throw new InvalidArgumentException('Unknown network entity');
    }

    public static function normalize(string $entity, array $input): array
    {
        $fields = self::fields($entity);
        $extra = array_diff(array_keys($input), array_keys($fields));
        if ($extra) {
            throw new InvalidArgumentException('Unexpected network fields: ' . implode(', ', $extra));
        }
        // Existing API, CSV and test payloads predate the optional map geometry
        // fields. They must keep the exact legacy radius semantics.
        if ($entity==='stations') {
            $input += ['coverage_kind'=>'radius','coverage_geojson'=>''];
        } elseif ($entity==='legs') {
            $input += ['area_coverage_kind'=>'radius','area_coverage_geojson'=>'',
                'route_geojson'=>''];
        }
        $output = [];
        foreach ($fields as $key=>$spec) {
            if (!array_key_exists($key,$input)) {
                if (in_array($key,['outer_radius_km','area_outer_radius_km'],true)) {
                    // Old API/CSV payloads may omit these optional overrides.
                    $input[$key]=null;
                } elseif (in_array($key,['patch_precedence','override_winner'],true)) {
                    $input[$key]='none';
                } else {
                    throw new InvalidArgumentException('Missing network field: ' . $key);
                }
            }
            $value=$input[$key];
            $type=$spec[0];
            if ($type==='geojson_polygon' || $type==='geojson_line') {
                $output[$key]=GrTransportNetworkGeometry::normalize($value,
                    $type==='geojson_polygon' ? 'polygon' : 'line');
            } elseif ($type==='text') {
                if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('Invalid ' . $key);
                $text=trim((string)$value);
                $length=function_exists('mb_strlen') ? mb_strlen($text,'UTF-8') : strlen($text);
                if ($length>$spec[1]) throw new InvalidArgumentException($key . ' is too long');
                $output[$key]=$text;
            } elseif ($type==='choice') {
                if (!is_string($value) || !in_array($value,$spec[1],true)) throw new InvalidArgumentException('Invalid ' . $key);
                $output[$key]=$value;
            } elseif ($type==='bool') {
                if (!in_array($value,[0,1,'0','1',false,true],true)) throw new InvalidArgumentException('Invalid ' . $key);
                $output[$key]=(int)$value;
            } elseif ($type==='nonnegative_int') {
                if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',(string)$value)
                    || (float)$value>2147483647) {
                    throw new InvalidArgumentException('Invalid nonnegative integer: '.$key);
                }
                $output[$key]=(int)$value;
            } elseif ($type==='id' || $type==='nullable_id') {
                if ($type==='nullable_id' && ($value===null || $value==='' || $value==='0' || $value===0)) {
                    $output[$key]=null;
                } else {
                    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D',(string)$value) || (float)$value>2147483647) {
                        throw new InvalidArgumentException('Invalid station ID: ' . $key);
                    }
                    $output[$key]=(int)$value;
                }
            } else {
                $nullable=str_starts_with($type,'nullable_');
                if ($nullable && ($value===null || $value==='')) {
                    $output[$key]=null;
                    continue;
                }
                if (!is_scalar($value) || $value==='' || !is_numeric(str_replace(',','.',(string)$value))) {
                    throw new InvalidArgumentException('Invalid number: ' . $key);
                }
                $number=(float)str_replace(',','.',(string)$value);
                if (!is_finite($number)) throw new InvalidArgumentException('Nonfinite number: ' . $key);
                if (str_contains($type,'latitude')) {
                    if ($number< -90 || $number>90) throw new InvalidArgumentException('Latitude out of bounds');
                    $output[$key]=round($number,7);
                } elseif (str_contains($type,'longitude')) {
                    if ($number< -180 || $number>180) throw new InvalidArgumentException('Longitude out of bounds');
                    $output[$key]=round($number,7);
                } else {
                    if ($number<$spec[1] || $number>$spec[2]) throw new InvalidArgumentException('Outside allowed range: ' . $key);
                    $output[$key]=round($number,2);
                }
            }
        }
        if ($entity==='stations') {
            if ($output['name']==='' || $output['lat']===null || $output['lng']===null) {
                throw new InvalidArgumentException('Station name and map coordinates are required');
            }
            if ($output['coverage_kind']!=='radius' && $output['coverage_geojson']==='') {
                throw new InvalidArgumentException('Postal/isochrone coverage requires a real GeoJSON polygon');
            }
            if ($output['outer_radius_km']!==null && ($output['outer_radius_km']<$output['inner_radius_km'] || $output['outer_radius_km']<=0)) {
                throw new InvalidArgumentException('Outer station radius override must be positive and at least the inner radius');
            }
        } elseif ($entity==='legs') {
            if ($output['area_coverage_kind']!=='radius' && $output['area_coverage_geojson']==='') {
                throw new InvalidArgumentException('Direct-area postal/isochrone coverage requires a real GeoJSON polygon');
            }
            if ($output['transit_min_hours']>0 && $output['transit_max_hours']<$output['transit_min_hours']
                || ($output['transit_min_hours']==0 && $output['transit_max_hours']>0)
                || ($output['transit_min_hours']>0 && $output['transit_max_hours']==0)) {
                throw new InvalidArgumentException('Transit requires positive min/max hours, with max at least min');
            }
            if ($output['estimate'] && $output['estimate_high_factor']<=$output['estimate_low_factor']) {
                throw new InvalidArgumentException('Estimate high factor must exceed low factor');
            }
            if ($output['to_station_id']!==null) {
                // Older station legs may store zero coordinates instead of SQL NULL.
                // Clear them before validation and persistence, but only for station
                // legs: a direct-area service may legitimately be centred at (0, 0).
                foreach (['area_lat','area_lng'] as $coordinate) {
                    if ($output[$coordinate]===0.0) $output[$coordinate]=null;
                }
                if ($output['to_station_id']===$output['from_station_id']) throw new InvalidArgumentException('A leg must connect distinct stations');
                if ($output['patch_precedence']!=='none') throw new InvalidArgumentException('Patch precedence requires a direct-area leg');
                if ($output['area_lat']!==null || $output['area_lng']!==null || $output['area_radius_km']>0 || $output['area_outer_radius_km']!==null ||
                    $output['area_coverage_geojson']!=='' || $output['area_coverage_kind']!=='radius') {
                    throw new InvalidArgumentException('A station leg cannot also be a direct area service');
                }
            } elseif ($output['area_lat']===null || $output['area_lng']===null ||
                ($output['area_radius_km']<=0 && $output['area_coverage_kind']==='radius')) {
                throw new InvalidArgumentException('Direct service requires area centre and coverage radius');
            } else {
                if ($output['area_outer_radius_km']!==null &&
                    ($output['area_outer_radius_km']<$output['area_radius_km'] || $output['area_outer_radius_km']<=0)) {
                    throw new InvalidArgumentException('Outer area radius override must be positive and at least the normal area radius');
                }
            }
        } elseif ($entity==='branches') {
            if ($output['name']==='') throw new InvalidArgumentException('Branch name is required');
        } elseif ($entity==='transfers' && $output['from_station_id']===$output['to_station_id']) {
            throw new InvalidArgumentException('A transfer must connect distinct stations');
        }
        return $output;
    }

    public static function distanceKm(float $lat1,float $lon1,float $lat2,float $lon2): float
    {
        $a=sin(deg2rad($lat2-$lat1)/2)**2
            + cos(deg2rad($lat1))*cos(deg2rad($lat2))
            *sin(deg2rad($lon2-$lon1)/2)**2;
        return 6371.0088*2*asin(sqrt(min(1,max(0,$a))));
    }
}
