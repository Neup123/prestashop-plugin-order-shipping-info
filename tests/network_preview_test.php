<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkPreview.php';

/** Pure server parity for authenticated, read-only map preview (#139/#140). */
function previewFails(callable $fn,string $messagePart): void
{
    try { $fn(); }
    catch (InvalidArgumentException $e) {
        testAssert(str_contains($e->getMessage(),$messagePart),
            'Expected explicit refusal: '.$messagePart.', got: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Expected preview refusal: '.$messagePart);
}
$start=['id_station'=>1,'name'=>'Main hub','type'=>'bus','lat'=>38.0,'lng'=>23.7,
    'inner_radius_km'=>1,'outer_radius_km'=>8,'is_origin_hub'=>1,'active'=>1];
$port=['id_station'=>2,'name'=>'Port','type'=>'ferry','lat'=>38.1,'lng'=>23.9,
    'inner_radius_km'=>1,'outer_radius_km'=>8,'is_origin_hub'=>0,'active'=>1];
$alt=['id_station'=>3,'name'=>'Other terminal','type'=>'bus','lat'=>38.1005,'lng'=>23.9005,
    'inner_radius_km'=>1,'outer_radius_km'=>8,'is_origin_hub'=>0,'active'=>1];
$direct=['id_leg'=>1,'from_station_id'=>1,'to_station_id'=>2,
    'mode'=>'bus','operator_label'=>'Bus to port','price'=>20,'bidirectional'=>0,'active'=>1];
$alternative=['id_leg'=>2,'from_station_id'=>1,'to_station_id'=>3,
    'mode'=>'bus','operator_label'=>'Alternative bus','price'=>10,'bidirectional'=>0,'active'=>1];
$network=['stations'=>[$start,$port,$alt],'legs'=>[$direct,$alternative],
    'transfers'=>[],'branches'=>[],'branch_stock'=>[]];
$points=['start_lat'=>38,'start_lng'=>23.7,'end_lat'=>38.1,'end_lng'=>23.9];
$preview=GrTransportNetworkPreview::route($network,$points);
testAssert($preview['source']==='server' && $preview['origin_station_id']===1
    && $preview['destination_station_id']===3 && $preview['total']===10.0,
    'Shared PHP graph selects cheaper of overlapping reachable destination patches');
testAssert($preview['steps'][0]['id']===2 && $preview['steps'][0]['from']===1,
    'Browser receives the authoritative directed edge ID and source');
$network['stations'][1]['patch_precedence']='override';
$override=GrTransportNetworkPreview::route($network,$points);
testAssert($override['destination_station_id']===2
    && $override['destination_resolution']==='patch_precedence',
    'Destination coverage precedence uses the shared PHP resolver');
unset($network['stations'][1]['patch_precedence']);
$network['legs'][0]['bidirectional']=1;
$reverse=GrTransportNetworkPreview::route($network,[
    'start_lat'=>38.1,'start_lng'=>23.9,'end_lat'=>38,'end_lng'=>23.7
]);
testAssert($reverse['origin_station_id']===2 && $reverse['destination_station_id']===1
    && $reverse['steps'][0]['from']===2 && $reverse['steps'][0]['to']===1,
    'Bidirectional service is handled by the shared PHP graph');
$network['legs'][1]['active']=0;
$network['legs'][0]=array_replace($direct,[
    'tariff_type'=>'weight','rate_per_unit'=>2,
    'estimate'=>1,'estimate_low_factor'=>0.8,'estimate_high_factor'=>1.2,
    'price'=>5,
]);
$weight=GrTransportNetworkPreview::route($network,$points+['weight'=>3]);
testAssert($weight['estimate']===true && abs($weight['total']-13.2)<0.001
    && $weight['estimate_min']===8.8 && $weight['estimate_max']===13.2,
    'Weight-based estimated preview uses conservative upper tariff from PHP');
$fallback=GrTransportNetworkPreview::route($network,$points);
testAssert($fallback['missing_measurement']===true
    && $fallback['estimate']===true && $fallback['total']===6.0,
    'No parcel dimensions implies the same conservative positive-base fallback as checkout');
$network['legs'][0]=array_replace($direct,[
    'tariff_type'=>'volume','rate_per_unit'=>100,'price'=>4,
]);
$volume=GrTransportNetworkPreview::route($network,$points+['volume'=>0.05]);
testAssert(abs($volume['total']-9.0)<0.001,
    'Volume tariff uses PHP cubic-meter calculation');
$network['legs'][0]['active']=0;
previewFails(static fn()=>GrTransportNetworkPreview::route($network,$points),
    'No directed server-resolved connection');
$network['legs'][0]['active']=1;

$polygon=json_encode(['type'=>'MultiPolygon','coordinates'=>[
    [
      [[23.6,37.9],[23.8,37.9],[23.8,38.1],[23.6,38.1],[23.6,37.9]],
      [[23.68,37.98],[23.72,37.98],[23.72,38.02],[23.68,38.02],[23.68,37.98]],
    ],
    [
      [[23.85,38.17],[23.90,38.17],[23.90,38.21],[23.85,38.21],[23.85,38.17]],
    ]
]],JSON_THROW_ON_ERROR);
$network['stations'][0]['coverage_kind']='postal_polygon';
$network['stations'][0]['coverage_geojson']=$polygon;
previewFails(static fn()=>GrTransportNetworkPreview::route($network,$points),
    'outside authoritative station coverage');
$inPolygon=GrTransportNetworkPreview::route($network,array_replace($points,[
    'start_lat'=>38.06,'start_lng'=>23.74,
]));
testAssert($inPolygon['origin_station_id']===1,'Polygon inside supports valid preview');
$island=GrTransportNetworkPreview::route($network,array_replace($points,[
    'start_lat'=>38.19,'start_lng'=>23.87,
]));
testAssert($island['origin_station_id']===1,
    'Separate GeoJSON island grants coverage beyond the stale circle radius');
$onBoundary=GrTransportNetworkPreview::route($network,array_replace($points,[
    'start_lat'=>37.9,'start_lng'=>23.65,
]));
testAssert($onBoundary['origin_station_id']===1,
    'Polygon exterior boundary agrees with checkout boundary membership');
$serverCandidates=GrTransportNetworkGraph::candidates($network['stations'],38,23.7);
testAssert($serverCandidates===[],
    'Checkout candidate resolver also excludes the exact polygon hole');
$network['stations'][0]=array_replace($start,[
    'outer_radius_km'=>null,'coverage_kind'=>'radius','coverage_geojson'=>null,
]);
$inheritPoint=array_replace($points,[
    'start_lat'=>38+3/111.195,'start_lng'=>23.7,
]);
$inherit=GrTransportNetworkPreview::route($network,$inheritPoint);
testAssert($inherit['origin_station_id']===1 && $inherit['origin_ring']==='outer',
    'Missing FAR override inherits effective global boundary, not a JS NaN radius');
$network['branches']=[['id_branch'=>7,'id_shop'=>1,'origin_station_id'=>1,'active'=>1]];
previewFails(static fn()=>GrTransportNetworkPreview::route($network,$points),
    'Branch-restricted routes need an existing cart');
previewFails(static fn()=>GrTransportNetworkPreview::route($network,
    $points+['cart_id'=>'invalid']),'Select a valid existing cart');
previewFails(static fn()=>GrTransportNetworkPreview::route($network,
    array_replace($points,['start_lat'=>91])),'out of range');
echo "Server preview polygon/hole/island/boundary/FAR, graph, tariff, estimate, precedence and branch safety passed\n";
