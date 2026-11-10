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
    && count($result['quote']['network_path']['edges'])===2,
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

// #107: the resolver must apply link precedence IN THE ACTUAL OVERLAP, not
// replace the destination's path with a cheaper bus path that omits the taxi.
$overlapStations=[
 ['id_station'=>1,'name'=>'Dispatch hub','type'=>'bus','lat'=>38,'lng'=>23.7,'is_origin_hub'=>1,
  'inner_radius_km'=>0.1,'outer_radius_km'=>0.3],
 ['id_station'=>2,'name'=>'Train collection','type'=>'bus','lat'=>38.10,'lng'=>23.7,'is_origin_hub'=>0,
  'inner_radius_km'=>1,'outer_radius_km'=>2],
 ['id_station'=>3,'name'=>'Door-to-door taxi','type'=>'other','lat'=>38.1005,'lng'=>23.7,'is_origin_hub'=>0,
  'inner_radius_km'=>1,'outer_radius_km'=>2],
];
$overlapLegs=[
 ['id_leg'=>31,'from_station_id'=>1,'to_station_id'=>2,'mode'=>'bus',
  'operator_label'=>'Train feeder','price'=>3,'bidirectional'=>0],
 ['id_leg'=>32,'from_station_id'=>1,'to_station_id'=>3,'mode'=>'bus',
  'operator_label'=>'Other route','price'=>8,'bidirectional'=>0],
];
$taxi=['id_transfer'=>33,'from_station_id'=>2,'to_station_id'=>3,
    'connection_type'=>'taxi','price'=>14,'override_winner'=>'none'];
$overlapNetwork=['stations'=>$overlapStations,'legs'=>$overlapLegs,'transfers'=>[$taxi]];
$here=[38.10025,23.7];
$baseline=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($baseline['quote']['station_name']==='Train collection' && $baseline['quote']['price']===3.0
    && $baseline['quote']['pickup_only']===1 && !isset($baseline['quote']['resolution']),
    'Override OFF: existing cheapest and station pickup behavior stays unchanged');
$overlapNetwork['transfers'][0]['override_winner']='to';
$taxiOffer=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($taxiOffer['quote']['station_name']==='Door-to-door taxi'
    && $taxiOffer['quote']['price']===17.0
    && $taxiOffer['quote']['pickup_only']===0
    && $taxiOffer['quote']['mode']==='taxi'
    && $taxiOffer['quote']['ring']==='direct'
    && $taxiOffer['quote']['network_path']['edges'][1]['kind']==='transfer'
    && $taxiOffer['quote']['network_path']['edges'][1]['id']===33
    && $taxiOffer['quote']['resolution']['reason']==='link_override',
    'Override ON: force the taxi-after-train path; never direct customer to a walking station');
$overlapNetwork['transfers'][0]['override_winner']='none';
$overlapNetwork['stations'][2]['patch_precedence']='override';
$patchOffer=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($patchOffer['quote']['station_name']==='Door-to-door taxi'
    && $patchOffer['quote']['price']===8.0
    && $patchOffer['quote']['resolution']['reason']==='patch_precedence',
    'Patch overrides cheaper train even without a transfer override');
$overlapNetwork['stations'][2]['patch_precedence']='none';
$overlapNetwork['stations'][1]['patch_precedence']='yield';
$yieldOffer=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($yieldOffer['quote']['station_name']==='Door-to-door taxi'
    && $yieldOffer['quote']['price']===8.0,
    'Yielding cheap train patch loses its overlap to a neutral, pricier patch');
$overlapNetwork['stations'][1]['patch_precedence']='none';
$overlapNetwork['legs'][]=['id_leg'=>34,'from_station_id'=>1,'to_station_id'=>null,'mode'=>'other',
    'operator_label'=>'Premium courier','price'=>80,'bidirectional'=>0,
    'area_name'=>'Premium delivery','area_lat'=>38.10025,'area_lng'=>23.7,'area_radius_km'=>2,
    'patch_precedence'=>'override','override_winner'=>'none'];
$directOverride=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($directOverride['quote']['station_name']==='Premium delivery'
    && $directOverride['quote']['price']===80.0 && $directOverride['quote']['pickup_only']===0,
    'Direct-area patch precedence wins regardless of its higher price');
$overlapNetwork['legs'][2]['patch_precedence']='yield';
$directYield=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($directYield['quote']['station_name']==='Train collection'
    && $directYield['quote']['price']===3.0,
    'Yielding direct-area patch loses to the reachable train station');
$overlapNetwork['legs'][2]['patch_precedence']='none';
$overlapNetwork['legs'][2]['from_station_id']=2;
$overlapNetwork['legs'][2]['override_winner']='to';
$directLink=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',$here);
testAssert($directLink['quote']['station_name']==='Premium delivery'
    && $directLink['quote']['price']===83.0,
    'A direct-area link can override its originating station inside the overlap');
$graph=GrTransportNetworkGraph::routes($overlapNetwork['stations'],$overlapNetwork['legs'],$overlapNetwork['transfers']);
$plan=GrTransportNetworkResolution::mapPlan($overlapNetwork,$graph['cost']);
testAssert($plan['links']['leg:34']['winner_key']==='area:34'
    && $plan['links']['leg:34']['reach_boundary_km']===5.0
    && in_array('area:34',$plan['paint_order'],true),
    'Map consumes server-resolved winner and reach boundary, not a separate cheapest rule');
$outside=GrTransportNetworkQuote::resolve([],$overlapNetwork,'','',[38.16,23.7]);
testAssert($outside['quote']===null,'Override cannot extend service beyond configured coverage');


// #112: a direct area's FAR ring shares the consent/warning mechanism.
$isolatedArea=['stations'=>[['id_station'=>80,'name'=>'Hub','type'=>'other',
    'lat'=>38,'lng'=>23.7,'inner_radius_km'=>0.5,'outer_radius_km'=>0.5,'is_origin_hub'=>1]],
    'legs'=>[['id_leg'=>81,'from_station_id'=>80,'to_station_id'=>null,'mode'=>'other',
    'operator_label'=>'Couriers','price'=>7,'bidirectional'=>0,'area_name'=>'Area delivery',
    'area_lat'=>39,'area_lng'=>23.7,'area_radius_km'=>2,'area_outer_radius_km'=>null]],
    'transfers'=>[]];
$areaNorth=static fn(float $km): float => 39+rad2deg($km/6371.0088);
$areaFar=GrTransportNetworkQuote::resolve([],$isolatedArea,'','',[$areaNorth(3),23.7],null,
    'Custom FAR consent notice');
testAssert($areaFar['status']==='quoted' && $areaFar['quote']['ring']==='outer'
    && $areaFar['quote']['extended']===1 && $areaFar['quote']['pickup_only']===0
    && $areaFar['quote']['notice']==='Custom FAR consent notice',
    'Direct-area FAR quote uses the existing extended consent marker and notice');
$areaNormal=GrTransportNetworkQuote::resolve([],$isolatedArea,'','',[$areaNorth(1),23.7]);
testAssert($areaNormal['quote']['ring']==='inner' && $areaNormal['quote']['extended']===0,
    'Normal direct area does not require FAR consent');
$areaOutside=GrTransportNetworkQuote::resolve([],$isolatedArea,'','',[$areaNorth(6),23.7]);
testAssert($areaOutside['quote']===null,'Inherited area rejects beyond global FAR extent');
$isolatedArea['legs'][0]['area_outer_radius_km']=8;
$areaOverride=GrTransportNetworkQuote::resolve([],$isolatedArea,'','',[$areaNorth(7),23.7]);
testAssert($areaOverride['quote']['extended']===1
    && $areaOverride['quote']['ring']==='outer',
    'Area FAR override beats global setting during real quote resolution');

echo "Network cheapest path, FAR areas and simulator tests passed\\n";
