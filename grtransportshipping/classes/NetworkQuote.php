<?php
require_once __DIR__ . '/QuoteEngine.php';
require_once __DIR__ . '/NetworkGraph.php';

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
        ?array $coords=null,?array $originIds=null,string $extendedNotice=''): array
    {
        $manual=GrTransportQuoteEngine::resolve($flatRoutes,$postcode,$city);
        if ($manual['status']==='quoted' || in_array($manual['status'],['suspended','ambiguous'],true)) {
            return $manual;
        }
        if ($coords===null) return $manual;
        $location=self::validLocation($coords);
        if ($location===null) return $manual;
        $stations=$network['stations'] ?? [];
        $legs=$network['legs'] ?? [];
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
                $notice,$candidate['ring'],$id,1);
        }
        foreach (GrTransportNetworkGraph::directAreas($legs,$location[0],$location[1]) as $service) {
            $id=(int)$service['from_station_id'];
            $prefix=GrTransportNetworkGraph::path($graph,$id);
            if ($prefix===null) continue;
            $edge=['kind'=>'leg','id'=>(int)$service['id_leg'],'from'=>$id,'to'=>null,
                'mode'=>(string)$service['mode'],'operator'=>(string)$service['operator_label'],
                'price'=>(float)$service['price'],'direct_area'=>true,
                'area_name'=>(string)$service['area_name']];
            $prefix['edges'][]=$edge;
            $prefix['total']=round($prefix['total']+$edge['price'],2);
            $prefix['destination_station_id']=null;
            $choices[]=self::choice($prefix,
                (string)($service['area_name'] ?: 'Direct area delivery'),
                (string)$service['mode'],
                (string)$graph['stations'][$prefix['origin_hub_id']]['name'],0,
                'Direct delivery to your address within the service area.',
                'direct',null,0);
        }
        if (!$choices) return $manual;
        usort($choices,static function ($a,$b) {
            $byPrice=$a['quote']['price'] <=> $b['quote']['price'];
            if ($byPrice!==0) return $byPrice;
            $byConvenience=$a['priority'] <=> $b['priority'];
            if ($byConvenience!==0) return $byConvenience;
            return ((int)($a['quote']['destination_station_id'] ?? 0))
                <=> ((int)($b['quote']['destination_station_id'] ?? 0));
        });
        return ['status'=>'quoted','quote'=>$choices[0]['quote'],'route_id'=>0];
    }

    /** Simulate one named station rather than silently switching to another overlapping station. */
    public static function forStation(array $network,int $id,?array $originIds=null): array
    {
        $graph=GrTransportNetworkGraph::routes($network['stations'] ?? [],
            $network['legs'] ?? [],$network['transfers'] ?? [],$originIds);
        $path=GrTransportNetworkGraph::path($graph,$id);
        if ($path===null) return ['status'=>'unmatched','quote'=>null,'route_id'=>0];
        $station=$graph['stations'][$id];
        $end=$path['edges'] ? end($path['edges']) : null;
        $choice=self::choice($path,(string)$station['name'],
            $end ? (string)$end['mode'] : (string)$station['type'],
            (string)$graph['stations'][$path['origin_hub_id']]['name'],1,'','inner',$id,1);
        return ['status'=>'quoted','quote'=>$choice['quote'],'route_id'=>0];
    }

    private static function choice(array $path,string $stationName,string $mode,string $originName,
        int $pickup,string $notice,string $ring,?int $stationId,int $priority): array
    {
        $quote=[
            'mode'=>$mode,'origin_name'=>$originName,'station_name'=>$stationName,
            'price'=>round((float)$path['total'],2),'pickup_only'=>$pickup,
            'notice'=>$notice,'demo'=>0,'ring'=>$ring,
            'extended'=>$ring==='outer' ? 1 : 0,
            'destination_station_id'=>$stationId,
            'origin_hub_id'=>(int)$path['origin_hub_id'],
            'network_path'=>$path,
            'source'=>'network',
        ];
        return ['priority'=>$priority,'quote'=>$quote];
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
