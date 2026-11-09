<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkFields.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkGraph.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkApi.php';

function fails(callable $fn,string $message): void
{
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$station=[
    'name'=>'Πειραιάς', 'type'=>'ferry','address_text'=>'Piraeus',
    'lat'=>37.9475,'lng'=>23.637,'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>1,
];
$valid=GrTransportNetworkFields::normalize('stations',$station);
testAssert($valid['name']==='Πειραιάς' && $valid['outer_radius_km']===5.0,'UTF-8 station preserved');
fails(static function () use ($station) {
    GrTransportNetworkFields::normalize('stations',array_replace($station,['inner_radius_km'=>8]));
},'Inner ring cannot exceed outer');
fails(static function () use ($station) {
    GrTransportNetworkFields::normalize('stations',array_replace($station,['lat'=>91]));
},'Invalid coordinate accepted');
fails(static function () use ($station) {
    GrTransportNetworkFields::normalize('stations',$station+['admin_token'=>'forged']);
},'Unknown fields accepted');
$direct=GrTransportNetworkFields::defaults('legs');
$direct=array_replace($direct,[
    'from_station_id'=>1,'to_station_id'=>null,'mode'=>'other','operator_label'=>'Athens courier',
    'price'=>0,'bidirectional'=>0,'area_name'=>'Athens','area_lat'=>37.98,
    'area_lng'=>23.72,'area_radius_km'=>6,
]);
testAssert(GrTransportNetworkFields::normalize('legs',$direct)['price']===0.0,
    'Free direct-area courier must be valid');
fails(static function () use ($direct) {
    GrTransportNetworkFields::normalize('legs',array_replace($direct,['to_station_id'=>4]));
},'Station-to-station leg must not also have area geometry');
$transfer=['from_station_id'=>2,'to_station_id'=>3,'connection_type'=>'taxi','price'=>0];
testAssert(GrTransportNetworkFields::normalize('transfers',$transfer)['price']===0.0,
    'Zero-cost transfer allowed');
fails(static function () use ($transfer) {
    GrTransportNetworkFields::normalize('transfers',array_replace($transfer,['price'=>-1]));
},'Negative transfer accepted');

$stations=[
    ['id_station'=>1,'name'=>'Athens hub','lat'=>38,'lng'=>23.7,'inner_radius_km'=>1,'outer_radius_km'=>3,'is_origin_hub'=>1],
    ['id_station'=>2,'name'=>'Bus terminal','lat'=>37.99,'lng'=>23.69,'inner_radius_km'=>1,'outer_radius_km'=>3,'is_origin_hub'=>0],
    ['id_station'=>3,'name'=>'Ferry port','lat'=>37.95,'lng'=>23.64,'inner_radius_km'=>1,'outer_radius_km'=>3,'is_origin_hub'=>0],
    ['id_station'=>4,'name'=>'Naxos','lat'=>37.10,'lng'=>25.37,'inner_radius_km'=>1,'outer_radius_km'=>8,'is_origin_hub'=>0],
    ['id_station'=>5,'name'=>'Alternative hub','lat'=>37.9,'lng'=>23.9,'inner_radius_km'=>1,'outer_radius_km'=>3,'is_origin_hub'=>1],
];
$legs=[
    ['id_leg'=>10,'from_station_id'=>1,'to_station_id'=>2,'mode'=>'bus','operator_label'=>'Bus A','price'=>8,'bidirectional'=>0],
    ['id_leg'=>11,'from_station_id'=>3,'to_station_id'=>4,'mode'=>'ferry','operator_label'=>'Ferry B','price'=>12,'bidirectional'=>0],
    ['id_leg'=>12,'from_station_id'=>5,'to_station_id'=>4,'mode'=>'ferry','operator_label'=>'Expensive','price'=>40,'bidirectional'=>0],
    ['id_leg'=>13]+$direct,
];
$graph=GrTransportNetworkGraph::routes($stations,$legs,[],[]);
testAssert(GrTransportNetworkGraph::path($graph,4)===null,'No eligible origin, no route');
$graph=GrTransportNetworkGraph::routes($stations,$legs,[],[1]);
testAssert(GrTransportNetworkGraph::path($graph,4)===null,
    'Undefined bus-to-port transfer must not be invented');
$links=[['id_transfer'=>90,'from_station_id'=>2,'to_station_id'=>3,'connection_type'=>'taxi','price'=>6]];
$graph=GrTransportNetworkGraph::routes($stations,$legs,$links,[1]);
$path=GrTransportNetworkGraph::path($graph,4);
testAssert($path['total']===26.0 && count($path['edges'])===3
    && $path['edges'][1]['kind']==='transfer','Two-leg journey with a priced taxi hop');
$graph=GrTransportNetworkGraph::routes($stations,$legs,$links);
testAssert($graph['cost'][4]===26.0,'Cheapest hub wins, not first hub');
$legs[]=['id_leg'=>14,'from_station_id'=>5,'to_station_id'=>4,
    'mode'=>'flight','operator_label'=>'Cheap flight','price'=>19,'bidirectional'=>0];
$graph=GrTransportNetworkGraph::routes($stations,$legs,$links);
testAssert($graph['cost'][4]===19.0 && $graph['hub'][4]===5,
    'Added cheaper alternative changes network price automatically');
$near=GrTransportNetworkGraph::candidates($stations,37.10,25.37);
testAssert(count($near)===1 && $near[0]['ring']==='inner','Inner-ring match');
$lat=37.10+rad2deg(4/6371.0088);
$outer=GrTransportNetworkGraph::candidates($stations,$lat,25.37);
testAssert(count($outer)===1 && $outer[0]['ring']==='outer','Extended-service ring');
testAssert(GrTransportNetworkGraph::directAreas([$direct],37.98,23.72)[0]['price']===0,
    'Free direct area covers its address');
testAssert(GrTransportNetworkGraph::directAreas([$direct],37.1,25.37)===[],
    'Athens courier cannot become an island feeder or cover islands');

class Configuration {
    private static $values=[];
    public static function updateValue($key,$value) { self::$values[$key]=$value; return true; }
    public static function get($key) { return self::$values[$key] ?? ''; }
}
$read=GrTransportNetworkApi::rotate('read');
$write=GrTransportNetworkApi::rotate('write');
GrTransportNetworkApi::authenticate('read',$read);
GrTransportNetworkApi::authenticate('read',$write);
GrTransportNetworkApi::authenticate('write',$write);
try {
    GrTransportNetworkApi::authenticate('write',$read);
    throw new RuntimeException('Read token unexpectedly grants writes');
} catch (DomainException $e) { testAssert($e->getCode()===403,'Expected forbidden'); }
GrTransportNetworkApi::rotate('write');
try {
    GrTransportNetworkApi::authenticate('write',$write);
    throw new RuntimeException('Revoked write token remains valid');
} catch (DomainException $e) { testAssert($e->getCode()===403,'Expected revoked token'); }
$csv=GrTransportNetworkApi::parseCsv('transfers',
    "from_station_id,to_station_id,connection_type,price\n1,2,taxi,5.50\n");
testAssert(count($csv['transfers'])===1 && $csv['transfers'][0]['price']==='5.50',
    'CSV parsed through shared validated bulk data path');

/* Visual panel A must match the ACTUAL resolver: persisted polygon footprints
 * are quote geometry, not a decorative override of circular eligibility. */
$postalPolygon=json_encode(['type'=>'Polygon','coordinates'=>[[
    [23.60,37.90],[23.74,37.90],[23.74,38.04],[23.60,38.04],[23.60,37.90],
]]],JSON_THROW_ON_ERROR);
$postalStation=array_replace($station,[
    'coverage_kind'=>'postal_polygon','coverage_geojson'=>$postalPolygon,
]);
$parsed=GrTransportNetworkFields::normalize('stations',$postalStation);
testAssert($parsed['coverage_kind']==='postal_polygon' && $parsed['coverage_geojson']!=='',
    'Validated postal polygon persists with the station');
$postalStationRecord=array_replace($parsed,[
    'id_station'=>30,'lat'=>37.90,'lng'=>23.60,
    'inner_radius_km'=>1,'outer_radius_km'=>2,
]);
testAssert(count(GrTransportNetworkGraph::candidates([$postalStationRecord],38,23.70))===1,
    'Inside postal polygon but outside radius: eligible with the true polygon');
testAssert(GrTransportNetworkGraph::candidates([$postalStationRecord],37.88,23.60)===[],
    'Inside old circle but OUTSIDE postal polygon: must not quote');
$holePolygon=json_encode(['type'=>'Polygon','coordinates'=>[
    [[23.6,37.9],[23.8,37.9],[23.8,38.1],[23.6,38.1],[23.6,37.9]],
    [[23.68,37.98],[23.72,37.98],[23.72,38.02],[23.68,38.02],[23.68,37.98]],
]],JSON_THROW_ON_ERROR);
testAssert(!GrTransportNetworkGeometry::contains($holePolygon,38,23.7),
    'Polygon holes do not confer false delivery coverage');
testAssert(GrTransportNetworkGeometry::contains($holePolygon,38.06,23.7),
    'Outer polygon preserves allowed coverage outside its holes');
$area=array_replace($direct,[
    'area_coverage_kind'=>'walking_isochrone','area_coverage_geojson'=>$postalPolygon,
    'area_lat'=>37.9,'area_lng'=>23.6,'area_radius_km'=>2,
]);
$validatedArea=GrTransportNetworkFields::normalize('legs',$area);
testAssert(count(GrTransportNetworkGraph::directAreas([$validatedArea],38,23.7))===1,
    'Stored isochrone is actually used to determine direct-area service');
testAssert(GrTransportNetworkGraph::directAreas([$validatedArea],37.88,23.6)===[],
    'Direct-area circular reach must not override stored isochrone');
$routeShape=json_encode(['type'=>'LineString','coordinates'=>[
    [23.7,38.0],[23.74,38.02],[23.75,38.05],
]],JSON_THROW_ON_ERROR);
$routeRecord=array_replace(GrTransportNetworkFields::defaults('legs'),[
    'from_station_id'=>1,'to_station_id'=>2,'route_geojson'=>$routeShape,
]);
testAssert(GrTransportNetworkFields::normalize('legs',$routeRecord)['route_geojson']!=='',
    'Explicitly measured land route is accepted for later map rendering');
fails(static function () use ($postalStation) {
    GrTransportNetworkFields::normalize('stations',array_replace($postalStation,[
        'coverage_geojson'=>'{"type":"Polygon","coordinates":[]}',
    ]));
},'Empty polygon was incorrectly accepted');
fails(static function () use ($postalStation) {
    GrTransportNetworkFields::normalize('stations',array_replace($postalStation,[
        'coverage_geojson'=>'',
    ]));
},'Named postal coverage was accepted with no polygon');
testAssert(GrTransportNetworkFields::normalize('stations',$station)['coverage_kind']==='radius',
    'Legacy station import defaults to radius coverage without migration input');

echo "Station, graph, safe transfers, direct area and API permission tests passed\n";
