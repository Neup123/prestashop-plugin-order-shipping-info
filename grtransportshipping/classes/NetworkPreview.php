<?php
require_once __DIR__ . '/NetworkRepository.php';
require_once __DIR__ . '/NetworkGraph.php';
require_once __DIR__ . '/NetworkTariff.php';
require_once __DIR__ . '/NetworkResolution.php';
require_once __DIR__ . '/BranchAvailability.php';

/**
 * Read-only point-to-point ADMIN preview. Uses the same authoritative
 * polygon coverage, enabled-record filter, tariff and graph/precedence
 * machinery as checkout; no second JS graph or radius-only guessing.
 *
 * Difference from a customer quote: the first map pin is the route origin
 * (not the customer's dispatch-branch optimizer), and this previews ONLY
 * station-to-station paths. It cannot price a flat Route, an unmodelled
 * feeder to a station, or an actual future transport timetable.
 */
final class GrTransportNetworkPreview
{
    private static function coordinate($input,string $name,float $bound): float
    {
        if (!is_scalar($input) || !is_numeric((string)$input)) {
            throw new InvalidArgumentException('Invalid preview coordinate: '.$name);
        }
        $number=(float)$input;
        if (!is_finite($number) || abs($number)>$bound) {
            throw new InvalidArgumentException('Preview coordinate out of range: '.$name);
        }
        return $number;
    }

    public static function route(array $network,array $input): array
    {
        $start=[
            self::coordinate($input['start_lat'] ?? null,'start_lat',90),
            self::coordinate($input['start_lng'] ?? null,'start_lng',180),
        ];
        $end=[
            self::coordinate($input['end_lat'] ?? null,'end_lat',90),
            self::coordinate($input['end_lng'] ?? null,'end_lng',180),
        ];
        $active=GrTransportNetworkRepository::activeSnapshot($network);
        $parcel=[];
        $cart=null;
        $cartId=$input['cart_id'] ?? 0;
        if ($cartId!=='' && $cartId!==null && $cartId!==0 && $cartId!=='0') {
            if (!is_scalar($cartId) || !ctype_digit((string)$cartId) || (int)$cartId<=0
                || !class_exists('Cart')) {
                throw new InvalidArgumentException('Select a valid existing cart for a stock-aware preview');
            }
            $cart=new Cart((int)$cartId);
            if (!Validate::isLoadedObject($cart)
                || (int)$cart->id_shop!==GrTransportNetworkRepository::shopId()) {
                throw new InvalidArgumentException('Cart does not belong to this shop');
            }
            $parcel=GrTransportNetworkTariff::cartMeasurements($cart);
        } else {
            foreach (['weight'=>'weight_kg','volume'=>'volume_m3'] as $name=>$field) {
                $value=$input[$name] ?? '';
                if ($value==='') continue;
                if (!is_scalar($value) || !is_numeric((string)$value)
                    || !is_finite((float)$value) || (float)$value<=0) {
                    throw new InvalidArgumentException('Invalid parcel measurement: '.$name);
                }
                $parcel[$field]=(float)$value;
            }
        }
        $eligibleOrigins=null;
        if (!empty($active['branch_inventory_configured'])) {
            if (!$cart) {
                throw new InvalidArgumentException(
                    'Branch-restricted routes need an existing cart. Select a Cart ID in the simulator.'
                );
            }
            $eligibility=GrTransportBranchAvailability::eligible(
                $cart,$active['branches'] ?? [],$active['branch_stock'] ?? []
            );
            $eligibleOrigins=array_map('intval',$eligibility['origin_ids'] ?? []);
            if (!$eligibleOrigins) {
                throw new InvalidArgumentException('No dispatch branch can fulfil this cart');
            }
        }

        // Shared PHP candidate resolver checks source GeoJSON (including
        // holes/islands), effective inherited FAR reach and active records.
        $sources=GrTransportNetworkGraph::candidates($active['stations'],$start[0],$start[1]);
        $destinations=GrTransportNetworkGraph::candidates($active['stations'],$end[0],$end[1]);
        if (!$sources || !$destinations) {
            throw new InvalidArgumentException(
                'A preview point lies outside authoritative station coverage'
            );
        }
        usort($sources,static fn($a,$b) =>
            [$a['distance_km'],$a['station_id']] <=> [$b['distance_km'],$b['station_id']]);
        $priced=GrTransportNetworkTariff::apply($active['legs'] ?? [],$parcel);
        foreach ($sources as $source) {
            $origin=(int)$source['station_id'];
            // Never let a map preview bypass the real cart branch entitlement.
            if ($eligibleOrigins!==null && !in_array($origin,$eligibleOrigins,true)) continue;
            $graph=GrTransportNetworkGraph::routes(
                $active['stations'],$priced,$active['transfers'] ?? [],[$origin]
            );
            $choices=[];
            foreach ($destinations as $destination) {
                $target=(int)$destination['station_id'];
                $path=GrTransportNetworkGraph::path($graph,$target);
                if ($path===null) continue;
                $choices[]=[
                    'patch_key'=>'station:'.$target,
                    'comparison_cost'=>$path['total'],
                    'priority'=>1,
                    'quote'=>['destination_station_id'=>$target],
                    'path'=>$path,
                ];
            }
            if (!$choices) continue;
            // Overlapping destination covers use the same explicit link and
            // patch precedence resolver that chooses checkout reach patches.
            $chosen=GrTransportNetworkResolution::choose($choices,$active);
            $path=$chosen['choice']['path'];
            $totals=GrTransportNetworkTariff::totals($path['edges']);
            return [
                'status'=>'ok','source'=>'server','scope'=>'station-to-station',
                'origin_station_id'=>$origin,
                'destination_station_id'=>(int)$chosen['choice']['quote']['destination_station_id'],
                'origin_ring'=>$source['ring'],
                'destination_resolution'=>$chosen['reason'],
                'total'=>$path['total'],
                'estimate'=>$totals['estimate'],
                'estimate_min'=>$totals['min'],
                'estimate_max'=>$totals['max'],
                'missing_measurement'=>$totals['missing_data'],
                'steps'=>array_map(static fn($edge)=>[
                    'kind'=>$edge['kind'],'id'=>$edge['id'],
                    'from'=>$edge['from'],'to'=>$edge['to'],
                    'mode'=>$edge['mode'],'price'=>$edge['price'],
                ],$path['edges']),
            ];
        }
        throw new InvalidArgumentException(
            'No directed server-resolved connection between the covered preview points'
        );
    }
}
