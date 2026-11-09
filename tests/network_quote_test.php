<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkQuote.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkSimulator.php';

$stations=[
 ['id_station'=>1,'name'=>'Athens hub','type'=>'bus','lat'=>38,'lng'=>23.7,'is_origin_hub'=>1,'inner_radius_km'=>2,'outer_radius_km'=>6],
 ['id_station'=>2,'name'=>'Piraeus port','type'=>'ferry','lat'=>37.95,'lng'=>23.64,'is_origin_hub'=>0,'inner_radius_km'=>2,'outer_radius_km'=>6],
 ['id_station'=>3,'name'=>'Alternate port','type'=>'ferry','lat'=>37.9506,'lng'=>23.6406,'is_origin_hub'=>0,'inner_radius_km'=>2,'outer_radius_km'=>6],
 ['id_station'=>4,'name'=>'Remote island','type'=>'ferry','lat'=>36.5,'lng'=>25.5,'is_origin_hub'=>0,'inner_radius_km'=>1,'outer_radius_km'=>5],
];
$legs=[
 ['id_leg'=>1,'from_station_id'=>1,'to_station_id'=>2,'mode'=>'bus','operator_label'=>'Bus 1','price'=>20,'bidirectional'=>0],
 ['id_leg'=>2,'from_station_id'=>1,'to_station_id'=>3,'mode'=>'bus','operator_label'=>'Bus 2','price'=>9,'bidirectional'=>0],
 ['id_leg'=>3,'from_station_id'=>2,'to_station_id'=>4,'mode'=>'ferry','operator_label'=>'Ferry','price'=>30,'bidirectional'=>0],
 ['id_leg'=>4,'from_station_id'=>1,'to_station_id'=>null,'mode'=>'other','operator_label'=>'Courier','price'=>0,'bidirectional'=>0,
  'area_name'=>'Athens direct','area_lat'=>38,'area_lng'=>23.7,'area_radius_km'=>2],
];
$network=['stations'=>$stations,'legs'=>$legs,'transfers'=>[]];
$pt=[37.9503,23.6403];
$result=GrTransportNetworkQuote::resolve([],$network,'18500','Piraeus',$pt);
testAssert($result['status']==='quoted' && $result['quote']['station_name']==='Alternate port'
    && $result['quote']['price']===9.0,'Evaluate every overlapping station and choose cheaper valid path');
testAssert($result['quote']['ring']==='inner' && $result['quote']['extended']===0,
    'Inner coverage is standard');
$far=[37.9506+rad2deg(3/6371.0088),23.6406];
$result=GrTransportNetworkQuote::resolve([],$network,'','',$far,null,'Consider the walk!');
testAssert($result['status']==='quoted' && $result['quote']['extended']===1
    && $result['quote']['notice']==='Consider the walk!','Outer-ring cheapest destination warns');
$result=GrTransportNetworkQuote::resolve([],$network,'','',[38,23.7]);
testAssert($result['status']==='quoted' && $result['quote']['price']===0.0
    && $result['quote']['pickup_only']===0 && count($result['quote']['network_path']['edges'])===1,
    'Terminal direct courier is free and shows door delivery');
testAssert(GrTransportNetworkQuote::resolve([],$network,'','',[36.5,25.5])['quote']===null,
    'No undefined transfers to island');
$network['transfers'][]=['id_transfer'=>21,'from_station_id'=>3,'to_station_id'=>4,
    'connection_type'=>'taxi','price'=>3];
$result=GrTransportNetworkQuote::resolve([],$network,'','',[36.5,25.5]);
testAssert($result['quote']['price']===12.0
    && count($result['quote']['network_path']['edges'])===3,
    'Explicit transfer enables island path and cost includes taxi hop');
$manual=[['id_route'=>71,'route_status'=>'available','postal_prefix'=>'185','city'=>'',
    'mode'=>'bus','origin_name'=>'Manual','station_name'=>'Manual terminal',
    'price'=>100,'pickup_only'=>1,'notice'=>'', 'demo'=>0]];
$result=GrTransportNetworkQuote::resolve($manual,$network,'18500','Piraeus',$pt);
testAssert($result['quote']['price']===100 && $result['quote']['station_name']==='Manual terminal',
    'Explicit flat-route override is retained');
$manual[0]['route_status']='suspended';
testAssert(GrTransportNetworkQuote::resolve($manual,$network,'18500','Piraeus',$pt)['status']==='suspended',
    'Suspended flat override prevents unsafe network bypass');
testAssert(GrTransportNetworkQuote::resolve([],$network,'18500','Piraeus')['status']==='unmatched',
    'Unlocated address is never treated as a guessed map point');
$forStation=GrTransportNetworkQuote::forStation($network,4);
$sim=GrTransportNetworkSimulator::simulate([],$network,['station_id'=>4]);
testAssert($forStation===$sim && $sim['quote']['price']===12.0,
    'Simulator and checkout graph share the same engine');
$sim=GrTransportNetworkSimulator::simulate([],$network,[
 'postcode'=>'18500','city'=>'Piraeus','lat'=>$pt[0],'lng'=>$pt[1]]);
$checkout=GrTransportNetworkQuote::resolve([],$network,'18500','Piraeus',$pt);
testAssert($sim===$checkout,'Address simulator and checkout quote are bitwise identical');
echo "Network cheapest path, overrides, coverage, direct-area and simulator tests passed\n";
