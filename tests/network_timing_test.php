<?php
require_once __DIR__ . '/bootstrap.php';
class Configuration {
    private static $data=[];
    public static function get($key) { return self::$data[$key] ?? false; }
    public static function updateValue($key,$value) { self::$data[$key]=$value; return true; }
}
require_once __DIR__ . '/../grtransportshipping/classes/NetworkTiming.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkQuote.php';

$leg1=['kind'=>'leg','id'=>1,'from'=>1,'to'=>2,'price'=>8,'mode'=>'bus',
    'preparation_hours'=>2,'lead_hours'=>3,'transit_min_hours'=>4,'transit_max_hours'=>6];
$leg2=['kind'=>'leg','id'=>2,'from'=>2,'to'=>3,'price'=>12,'mode'=>'ferry',
    'preparation_hours'=>1,'lead_hours'=>2,'transit_min_hours'=>8,'transit_max_hours'=>10];
$window=GrTransportNetworkTiming::window([$leg1,$leg2]);
testAssert($window['min_hours']===20.0 && $window['max_hours']===24.0,
    'All leg prep, lead and transit components must sum into the delivery window');
testAssert(!$window['transfer_time_unknown'],'No false unmodelled transfer notice');
$transfer=['kind'=>'transfer','from'=>2,'to'=>3,'price'=>4,'mode'=>'taxi'];
testAssert(GrTransportNetworkTiming::window([$leg1,$transfer,$leg2])['transfer_time_unknown']===1,
    'Transfer with no configured time must be disclosed as unmodelled');
testAssert(GrTransportNetworkTiming::window([$leg1,['kind'=>'leg','transit_min_hours'=>0,'transit_max_hours'=>0]])===null,
    'Missing transit cannot silently imply zero duration');
testAssert(GrTransportNetworkTiming::window([['kind'=>'transfer']])===null,
    'No leg means no invented delivery time');
$defaults=GrTransportNetworkFields::defaults('legs');
$input=array_replace($defaults,[
    'from_station_id'=>1,'to_station_id'=>2,'price'=>12,'operator_label'=>'Test ferry',
    'transit_min_hours'=>4,'transit_max_hours'=>7,'lead_hours'=>1,'preparation_hours'=>2,
]);
testAssert(GrTransportNetworkFields::normalize('legs',$input)['lead_hours']===1.0,
    'Admin/API share validated leg hour fields');
try {
    GrTransportNetworkFields::normalize('legs',array_replace($input,[
        'transit_min_hours'=>8,'transit_max_hours'=>7,
    ]));
    throw new RuntimeException('Invalid transit window accepted');
} catch (InvalidArgumentException $expected) {}
$stations=[
    ['id_station'=>1,'name'=>'Hub','type'=>'bus','lat'=>38,'lng'=>24,
        'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>1],
    ['id_station'=>2,'name'=>'Port','type'=>'ferry','lat'=>37,'lng'=>25,
        'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>0],
];
$network=['stations'=>$stations,'legs'=>[array_replace($input,['id_leg'=>1,'mode'=>'ferry'])],'transfers'=>[]];
$quote=GrTransportNetworkQuote::forStation($network,2)['quote'];
testAssert($quote['delivery_window_hours']['min_hours']===7.0
    && $quote['delivery_window_hours']['max_hours']===10.0,
    'Selected quote keeps its detailed departure/delivery window');
$price=$quote['price'];
Configuration::updateValue('GRTRANS_SHOW_DELIVERY_TIME',0);
$off=GrTransportNetworkQuote::forStation($network,2)['quote'];
testAssert($off['delivery_window_hours']===null && $off['price']===$price,
    'Disabling time removes ETA everywhere without changing price');
Configuration::updateValue('GRTRANS_SHOW_DELIVERY_TIME',1);
$edited=$network;
$edited['legs'][0]['lead_hours']=10;
$after=GrTransportNetworkQuote::forStation($edited,2)['quote'];
testAssert($after['delivery_window_hours']['min_hours']===16.0
    && $after['delivery_window_hours']['max_hours']===19.0
    && $after['price']===$price,
    'Changing lead affects time only, never tariff');
echo "Leg preparation/lead/transit windows and switch-off behaviour passed\n";
