<?php
require_once __DIR__ . '/QuoteEngine.php';
require_once __DIR__ . '/NetworkGraph.php';
require_once __DIR__ . '/NetworkTariff.php';
require_once __DIR__ . '/NetworkTiming.php';

/**
 * Single cheapest-total resolver for checkout, customer quote page and simulator.
 * Flat available Routes are deliberate manual overrides; Suspended/Ambiguous
 * flat rules keep their safety veto. No inferred transfers or geocoding.
 */
final class GrTransportNetworkQuote
{
    public const DEFAULT_EXTENDED_NOTICE =
        'Extended pickup area: reaching the collection station may require a long walk. Consider this before choosing this delivery method.';

    public static function resolve(array $flatRoutes,array $network,string $postcode,string $city,
        ?array $coords=null,?array $originIds=null,string $extendedNotice='',?array $parcel=null,array $branchByOrigin=[]): array
    {
        $manual=GrTransportQuoteEngine::resolve($flatRoutes,$postcode,$city);
        if ($manual['status']==='quoted' || in_array($manual['status'],['suspended','ambiguous'],true)) {
            return $manual;
        }
        if ($coords===null) return $manual;
        $location=self::validLocation($coords);
        if ($location===null) return $manual;
        $stations=$network['stations'] ?? [];
        $legs=GrTransportNetworkTariff::apply($network['legs'] ?? [],$parcel ?? []);
        $transfers=$network['transfers'] ?? [];
        if (!$stations || !isset($stations[0]['id_station'])) return $manual;
        $graph=GrTransportNetworkGraph::routes($stations,$legs,$transfers,$originIds);
        $choices=[];
        foreach (GrTransportNetworkGraph::candidates($stations,$location[0],$location[1]) as $candidate) {
            $id=$candidate['station_id'];
            $path=GrTransportNetworkGraph::path($graph,$id);
            if ($path===null) continue;
            $station=$graph['stations'][$id];
            $end=$path['edges'] ? end($path['edges']) : null;
            $isExtended=$candidate['ring']==='outer';
            $notice=$isExtended ? ($extendedNotice!=='' ? $extendedNotice : self::DEFAULT_EXTENDED_NOTICE) : '';
            $choices[]=self::choice($path,(string)$station['name'],
                $end ? (string)$end['mode'] : (string)$station['type'],
                (string)$graph['stations'][$path['origin_hub_id']]['name'],1,
                $notice,$candidate['ring'],$id,1,$parcel ?? [],$branchByOrigin);
        }
        foreach (GrTransportNetworkGraph::directAreas($legs,$location[0],$location[1]) as $service) {
            $id=(int)$service['from_station_id'];
            $prefix=GrTransportNetworkGraph::path($graph,$id);
            if ($prefix===null) continue;
            $edge=['kind'=>'leg','id'=>(int)$service['id_leg'],'from'=>$id,'to'=>null,
                'mode'=>(string)$service['mode'],'operator'=>(string)$service['operator_label'],
                'price'=>(float)$service['price'],'direct_area'=>true,
                'area_name'=>(string)$service['area_name']];
            foreach (['tariff_min','tariff_max','tariff_estimate','tariff_missing_data','tariff_basis',
                'lead_hours','preparation_hours','transit_min_hours','transit_max_hours'] as $field) {
                if (isset($service[$field])) $edge[$field]=$service[$field];
            }
            $prefix['edges'][]=$edge;
            $prefix['total']=round($prefix['total']+$edge['price'],2);
            $prefix['destination_station_id']=null;
            $choices[]=self::choice($prefix,
                (string)($service['area_name'] ?: 'Direct area delivery'),
                (string)$service['mode'],
                (string)$graph['stations'][$prefix['origin_hub_id']]['name'],0,
                'Direct delivery to your address within the service area.',
                'direct',null,0,$parcel ?? [],$branchByOrigin);
        }
        if (!$choices) return $manual;
        usort($choices,static function ($a,$b) {
            $byPrice=$a['comparison_cost'] <=> $b['comparison_cost'];
            if ($byPrice!==0) return $byPrice;
            $byConvenience=$a['priority'] <=> $b['priority'];
            if ($byConvenience!==0) return $byConvenience;
            return ((int)($a['quote']['destination_station_id'] ?? 0))
                <=> ((int)($b['quote']['destination_station_id'] ?? 0));
        });
        return ['status'=>'quoted','quote'=>$choices[0]['quote'],'route_id'=>0];
    }

    /** Simulate one named station rather than silently switching to another overlapping station. */
    public static function forStation(array $network,int $id,?array $originIds=null,?array $parcel=null,array $branchByOrigin=[]): array
    {
        $graph=GrTransportNetworkGraph::routes($network['stations'] ?? [],
            GrTransportNetworkTariff::apply($network['legs'] ?? [],$parcel ?? []),
            $network['transfers'] ?? [],$originIds);
        $path=GrTransportNetworkGraph::path($graph,$id);
        if ($path===null) return ['status'=>'unmatched','quote'=>null,'route_id'=>0];
        $station=$graph['stations'][$id];
        $end=$path['edges'] ? end($path['edges']) : null;
        $choice=self::choice($path,(string)$station['name'],
            $end ? (string)$end['mode'] : (string)$station['type'],
            (string)$graph['stations'][$path['origin_hub_id']]['name'],1,'','inner',$id,1,$parcel ?? [],$branchByOrigin);
        return ['status'=>'quoted','quote'=>$choice['quote'],'route_id'=>0];
    }

    private static function choice(array $path,string $stationName,string $mode,string $originName,
        int $pickup,string $notice,string $ring,?int $stationId,int $priority,array $parcel=[],array $branchByOrigin=[]): array
    {
        $tariff=GrTransportNetworkTariff::totals($path['edges']);
        $window=GrTransportNetworkTiming::window($path['edges']);
        $quote=[
            'mode'=>$mode,'origin_name'=>$originName,'station_name'=>$stationName,
            'price'=>$tariff['estimate'] ? 0.0 : round((float)$path['total'],2),
            'estimate'=>$tariff['estimate'] ? 1 : 0,
            'estimate_min'=>$tariff['estimate'] ? $tariff['min'] : null,
            'estimate_max'=>$tariff['estimate'] ? $tariff['max'] : null,
            'tariff_missing_data'=>$tariff['missing_data'] ? 1 : 0,
            'tariff_inputs'=>$parcel,
            'pickup_only'=>$pickup,
            'notice'=>$notice,'demo'=>0,'ring'=>$ring,
            'extended'=>$ring==='outer' ? 1 : 0,
            'destination_station_id'=>$stationId,
            'origin_hub_id'=>(int)$path['origin_hub_id'],
            'network_path'=>$path,
            'source'=>'network',
            'delivery_window_hours'=>$window,
        ];
        $branch=$branchByOrigin[(int)$path['origin_hub_id']] ?? null;
        if (is_array($branch)) {
            $quote['branch_id']=(int)$branch['id_branch'];
            $quote['branch_name']=(string)$branch['name'];
        }
        return ['priority'=>$priority,'comparison_cost'=>round((float)$path['total'],2),'quote'=>$quote];
    }

    public static function validLocation(array $location): ?array
    {
        if (count($location)<2 || !is_scalar($location[0]) || !is_scalar($location[1])
            || !is_numeric($location[0]) || !is_numeric($location[1])) return null;
        $lat=(float)$location[0]; $lng=(float)$location[1];
        if (!is_finite($lat) || !is_finite($lng) || abs($lat)>90 || abs($lng)>180) return null;
        return [$lat,$lng];
    }

    /** Standard PrestaShop Address does not guarantee geographic coordinates. */
    public static function addressLocation($address): ?array
    {
        if (!isset($address->latitude,$address->longitude)) return null;
        return self::validLocation([$address->latitude,$address->longitude]);
    }
}
