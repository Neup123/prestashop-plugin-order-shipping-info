<?php
require_once __DIR__ . '/QuoteEngine.php';
require_once __DIR__ . '/NetworkGraph.php';
require_once __DIR__ . '/NetworkTariff.php';
require_once __DIR__ . '/NetworkTiming.php';
require_once __DIR__ . '/NetworkResolution.php';

/**
 * Single cheapest-total resolver for checkout, customer quote page and simulator.
 * Flat available Routes are deliberate manual overrides; Suspended/Ambiguous
 * flat rules keep their safety veto. No inferred transfers or geocoding.
 */
final class GrTransportNetworkQuote
{
    public const DEFAULT_EXTENDED_NOTICE =
        'FAR service zone: your destination is beyond the normal coverage boundary. Confirm that you want to proceed with this delivery method.';

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
        // When branches exist, absence of an eligibility filter is NOT
        // permission to use every hub. Only an actual whole-cart check may
        // supply eligible origins. Without branches, old graph behavior holds.
        if ((!empty($network['branches']) || !empty($network['branch_inventory_configured']))
            && $originIds===null) $originIds=[];
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
                $notice,$candidate['ring'],$id,1,$parcel ?? [],$branchByOrigin,'station:'.$id);
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
            $ring=$service['_ring'];
            $notice=$ring==='outer'
                ? ($extendedNotice!=='' ? $extendedNotice : self::DEFAULT_EXTENDED_NOTICE)
                : 'Direct delivery to your address within the service area.';
            $choices[]=self::choice($prefix,
                (string)($service['area_name'] ?: 'Direct area delivery'),
                (string)$service['mode'],
                (string)$graph['stations'][$prefix['origin_hub_id']]['name'],0,
                $notice,$ring,null,0,$parcel ?? [],$branchByOrigin,'area:'.(int)$service['id_leg']);
        }
        if (!$choices) return $manual;
        $selected=GrTransportNetworkResolution::choose($choices,$network);
        $quote=$selected['choice']['quote'];
        if ($selected['reason']==='link_override') {
            // A preferred destination must traverse the configured link, even
            // when an unrelated cheaper path to that same station exists.
            if ($selected['link_target']==='to') {
                $effectiveNetwork=$network;
                $effectiveNetwork['legs']=$legs; // preserve applied parcel tariffs
                $forced=self::forcedLinkQuote($selected,$effectiveNetwork,$graph,$parcel ?? [],$branchByOrigin);
                if ($forced!==null) $quote=$forced;
            }
            $quote['resolution']=[
                'reason'=>'link_override','winner_key'=>$selected['winner_key'],
                'reach_boundary_km'=>$selected['reach_boundary_km'],
                'link_kind'=>$selected['link_kind'],'link_id'=>$selected['link_id']
            ];
        } elseif ($selected['reason']==='patch_precedence') {
            $quote['resolution']=['reason'=>'patch_precedence',
                'winner_key'=>$selected['winner_key'],
                'reach_boundary_km'=>$selected['reach_boundary_km']];
        }
        return ['status'=>'quoted','quote'=>$quote,'route_id'=>0];
    }

    /** Preserve the actual chosen handover as the final route leg. This is
     * essential for taxi-after-train: a taxi override cannot return a walking
     * station offer or a cheaper route that bypasses that taxi transfer.
     */
    private static function forcedLinkQuote(array $selected,array $network,array $graph,
        array $parcel,array $branchByOrigin): ?array
    {
        $kind=$selected['link_kind'];
        $id=(int)$selected['link_id'];
        $list=$kind==='transfer' ? ($network['transfers'] ?? []) : ($network['legs'] ?? []);
        $record=null;
        foreach ($list as $item) {
            $candidateId=(int)($kind==='transfer' ? ($item['id_transfer'] ?? 0) : ($item['id_leg'] ?? 0));
            if ($candidateId===$id) { $record=$item; break; }
        }
        if ($record===null || ($kind==='leg' && empty($record['to_station_id']))) return null;
        $from=(int)$record['from_station_id'];
        $to=(int)$record['to_station_id'];
        $prefix=GrTransportNetworkGraph::path($graph,$from);
        if ($prefix===null || !isset($graph['stations'][$to])) return null;
        $mode=$kind==='transfer' ? (string)$record['connection_type'] : (string)$record['mode'];
        $edge=['kind'=>$kind,'id'=>$id,'from'=>$from,'to'=>$to,'mode'=>$mode,
            'operator'=>$kind==='transfer' ? '' : (string)$record['operator_label'],
            'price'=>(float)$record['price']];
        foreach (['tariff_min','tariff_max','tariff_estimate','tariff_missing_data','tariff_basis',
            'lead_hours','preparation_hours','transit_min_hours','transit_max_hours'] as $field) {
            if (isset($record[$field])) $edge[$field]=$record[$field];
        }
        $prefix['edges'][]=$edge;
        $prefix['total']=round($prefix['total']+$edge['price'],2);
        $prefix['destination_station_id']=$to;
        $chosen=$selected['choice']['quote'];
        $doorToDoor=$kind==='transfer' && in_array(strtolower($mode),['taxi','courier','door-to-door'],true);
        $choice=self::choice($prefix,(string)$graph['stations'][$to]['name'],$mode,
            (string)$graph['stations'][$prefix['origin_hub_id']]['name'],
            $doorToDoor ? 0 : 1,
            $doorToDoor ? 'Door-to-door transfer to your address.' : (string)$chosen['notice'],
            $doorToDoor ? 'direct' : (string)$chosen['ring'],
            $to,$doorToDoor ? 0 : 1,$parcel,$branchByOrigin);
        return $choice['quote'];
    }

    /** Simulate one named station rather than silently switching to another overlapping station. */
    public static function forStation(array $network,int $id,?array $originIds=null,?array $parcel=null,array $branchByOrigin=[]): array
    {
        if ((!empty($network['branches']) || !empty($network['branch_inventory_configured']))
            && $originIds===null) $originIds=[];
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
        int $pickup,string $notice,string $ring,?int $stationId,int $priority,array $parcel=[],array $branchByOrigin=[],string $patchKey=''): array
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
        return ['priority'=>$priority,'comparison_cost'=>round((float)$path['total'],2),
            'patch_key'=>$patchKey,'quote'=>$quote];
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
