<?php
require_once __DIR__ . '/NetworkFields.php';

/**
 * Deterministic cheapest station graph. This pure PHP class is shared by
 * the map price overlay, admin simulator and checkout; no inferred transfers.
 */
final class GrTransportNetworkGraph
{
    public static function routes(array $stations,array $legs,array $transfers,?array $originIds=null): array
    {
        $known=[];
        $cost=[];
        $parent=[];
        $hub=[];
        foreach ($stations as $station) {
            $id=(int)$station['id_station'];
            $known[$id]=$station;
            $cost[$id]=INF;
        }
        $origins=$originIds;
        if ($origins===null) {
            $origins=[];
            foreach ($stations as $station) {
                if (!empty($station['is_origin_hub'])) $origins[]=(int)$station['id_station'];
            }
        }
        foreach ($origins as $id) {
            $id=(int)$id;
            if (!isset($known[$id])) continue;
            $cost[$id]=0.0;
            $hub[$id]=$id;
        }
        $adj=[];
        foreach ($legs as $leg) {
            $from=(int)$leg['from_station_id'];
            $to=(int)($leg['to_station_id'] ?? 0);
            // Direct-area legs terminate at the destination address, not a node.
            if (!$to || !isset($known[$from],$known[$to])) continue;
            $edge=['kind'=>'leg','id'=>(int)$leg['id_leg'],'from'=>$from,'to'=>$to,
                'mode'=>(string)$leg['mode'],'operator'=>(string)$leg['operator_label'],
                'price'=>(float)$leg['price']];
            foreach (['tariff_min','tariff_max','tariff_estimate','tariff_missing_data','tariff_basis',
                'lead_hours','preparation_hours','transit_min_hours','transit_max_hours'] as $field) {
                if (isset($leg[$field])) $edge[$field]=$leg[$field];
            }
            if ($edge['price']<0) continue;
            $adj[$from][]=$edge;
            if (!empty($leg['bidirectional'])) {
                $reverse=$edge;
                $reverse['from']=$to; $reverse['to']=$from;
                $adj[$to][]=$reverse;
            }
        }
        foreach ($transfers as $transfer) {
            $from=(int)$transfer['from_station_id'];
            $to=(int)$transfer['to_station_id'];
            if (!isset($known[$from],$known[$to])) continue;
            $price=(float)$transfer['price'];
            if ($price<0) continue;
            $adj[$from][]=['kind'=>'transfer','id'=>(int)$transfer['id_transfer'],
                'from'=>$from,'to'=>$to,'mode'=>(string)$transfer['connection_type'],
                'operator'=>'','price'=>$price];
        }
        $visited=[];
        while (true) {
            $node=null;
            $minimum=INF;
            foreach ($cost as $id=>$value) {
                if (!isset($visited[$id]) && $value<$minimum) {
                    $node=$id; $minimum=$value;
                }
            }
            if ($node===null) break;
            $visited[$node]=true;
            foreach ($adj[$node] ?? [] as $edge) {
                $dest=$edge['to'];
                $candidate=$minimum+$edge['price'];
                if ($candidate+0.000001<$cost[$dest]) {
                    $cost[$dest]=$candidate;
                    $parent[$dest]=$edge;
                    $hub[$dest]=$hub[$node];
                }
            }
        }
        return ['cost'=>$cost,'parent'=>$parent,'hub'=>$hub,'stations'=>$known];
    }

    public static function path(array $result,int $stationId): ?array
    {
        if (!isset($result['cost'][$stationId]) || !is_finite($result['cost'][$stationId])) return null;
        $edges=[];
        $cursor=$stationId;
        $seen=[];
        while (isset($result['parent'][$cursor])) {
            if (isset($seen[$cursor])) throw new LogicException('Cycle in cheapest-route predecessor chain');
            $seen[$cursor]=true;
            $edge=$result['parent'][$cursor];
            array_unshift($edges,$edge);
            $cursor=$edge['from'];
        }
        return ['origin_hub_id'=>$result['hub'][$stationId],
            'destination_station_id'=>$stationId,
            'total'=>round($result['cost'][$stationId],2),'edges'=>$edges];
    }

    /** Station geometry controls coverage, not the distance to the nearest station. */
    public static function candidates(array $stations,float $lat,float $lng): array
    {
        $candidates=[];
        foreach ($stations as $station) {
            $km=GrTransportNetworkFields::distanceKm($lat,$lng,(float)$station['lat'],(float)$station['lng']);
            $shape=($station['coverage_kind'] ?? 'radius')!=='radius' &&
                !empty($station['coverage_geojson']);
            $covered=$shape
                ? GrTransportNetworkGeometry::contains($station['coverage_geojson'],$lat,$lng)
                : $km<=(float)$station['outer_radius_km'];
            if (!$covered) continue;
            $ring=$km<=(float)$station['inner_radius_km'] ? 'inner' : 'outer';
            $candidates[]=['station_id'=>(int)$station['id_station'],'ring'=>$ring,'distance_km'=>$km];
        }
        return $candidates;
    }

    public static function directAreas(array $legs,float $lat,float $lng): array
    {
        $matches=[];
        foreach ($legs as $leg) {
            if (!empty($leg['to_station_id']) || $leg['area_lat']===null || $leg['area_lng']===null) continue;
            $shape=($leg['area_coverage_kind'] ?? 'radius')!=='radius' &&
                !empty($leg['area_coverage_geojson']);
            $covered=$shape
                ? GrTransportNetworkGeometry::contains($leg['area_coverage_geojson'],$lat,$lng)
                : GrTransportNetworkFields::distanceKm($lat,$lng,
                    (float)$leg['area_lat'],(float)$leg['area_lng'])<=(float)$leg['area_radius_km'];
            if ($covered) $matches[]=$leg;
        }
        return $matches;
    }
}
