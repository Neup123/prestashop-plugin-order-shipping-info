<?php
require_once __DIR__ . '/bootstrap.php';
class Configuration {
    public static $values=[];
    public static function get($key) { return self::$values[$key] ?? false; }
    public static function updateValue($key,$value) { self::$values[$key]=$value; return true; }
}
require_once __DIR__ . '/../grtransportshipping/classes/NetworkSettings.php';
require_once __DIR__ . '/../grtransportshipping/classes/QuoteCheck.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkFields.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkGraph.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkTariff.php';
require_once __DIR__ . '/../grtransportshipping/classes/CartQuote.php';

$settings=[];
foreach (GrTransportNetworkSettings::DEFINITIONS as $name=>$definition) {
    testAssert(GrTransportNetworkSettings::get($name)===(float)$definition['default'],
        'All network values must be usable before first configuration: '.$name);
    $settings[$name]=$definition['default'];
}
testAssert(GrTransportQuoteCheck::pinRadiusKm()===25.0,'Default public map radius must be 25 km');
testAssert(GrTransportCartQuote::ttlSeconds()===1800,'Existing 30-minute cart hold preserved');

$routes=require __DIR__.'/../grtransportshipping/demo/routes.php';
foreach ($routes as $id=>&$route) $route['id_route']=$id+1;
unset($route);
$stations=GrTransportQuoteCheck::stations();
$naxos=$stations[3];
$north=static function (float $km) use ($naxos): float {
    return $naxos['lat']+rad2deg($km/6371.0088);
};
testAssert(GrTransportQuoteCheck::pin($routes,$north(24.999),$naxos['lng'])['quote']!==null,
    'Original inclusive 25 km default still matches');
testAssert(GrTransportQuoteCheck::pin($routes,$north(25.001),$naxos['lng'])['quote']===null,
    'Default radius excludes slightly-outside point');

$settings['pin_radius_km']=10;
$settings['station_inner_km']=2;
$settings['station_outer_km']=6;
$settings['estimate_low_factor']=0.75;
$settings['estimate_high_factor']=1.25;
$settings['quote_ttl_minutes']=45;
$settings['nearby_gap_km']=1.5;
$settings['weight_to_kg']=0.001;
$settings['dimension_to_cm']=2.54;
GrTransportNetworkSettings::save($settings);
testAssert(GrTransportQuoteCheck::pinRadiusKm()===10.0,'Changed radius read from configuration');
testAssert(GrTransportQuoteCheck::pin($routes,$north(9.999),$naxos['lng'])['quote']!==null,
    'Configured radius accepts just-inside point');
testAssert(GrTransportQuoteCheck::pin($routes,$north(10.001),$naxos['lng'])['quote']===null,
    'Configured radius rejects outside point');
testAssert(GrTransportNetworkSettings::get('nearby_gap_km')===1.5,'Nearby gap persisted');
testAssert(GrTransportNetworkFields::defaults('stations')['inner_radius_km']===2.0
    && GrTransportNetworkFields::defaults('stations')['outer_radius_km']===null,
    'New station stores no FAR override and inherits global');
testAssert(GrTransportNetworkSettings::effectiveOuterKm(null,2)===6.0,
    'Runtime default governs new station FAR boundary');
testAssert(GrTransportNetworkFields::defaults('legs')['estimate_low_factor']===0.75
    && GrTransportNetworkFields::defaults('legs')['estimate_high_factor']===1.25,
    'New estimate factor defaults are configurable');
testAssert(GrTransportCartQuote::ttlSeconds()===2700,'Configured quote hold changes expiration');
$cart=new class { public function getProducts(){return [[
    'weight'=>1500,'width'=>10,'height'=>10,'depth'=>10,'cart_quantity'=>1,
]];} };
$parcel=GrTransportNetworkTariff::cartMeasurements($cart);
testAssert($parcel['weight_kg']===1.5 && abs($parcel['volume_m3']-0.0163871)<0.0000001,
    'Shop weight/dimension unit multipliers must affect measured tariffs');
$old=Configuration::$values['GRTRANS_PIN_RADIUS_KM'];
testAssert(GrTransportNetworkSettings::ensureDefaults(),'Idempotent configuration initialization');
testAssert(Configuration::$values['GRTRANS_PIN_RADIUS_KM']===$old,
    'Upgrades must never overwrite an operator-configured radius');

$reject=function ($patch) use ($settings) {
    try { GrTransportNetworkSettings::save(array_replace($settings,$patch)); }
    catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Unsafe cross-field/default configuration unexpectedly accepted');
};
$reject(['pin_radius_km'=>0]);
$reject(['station_inner_km'=>7,'station_outer_km'=>6]);
$reject(['estimate_low_factor'=>1.5,'estimate_high_factor'=>1.4]);
$reject(['quote_ttl_minutes'=>1.5]);
$reject(['max_coverage_km'=>4]);

// #112: inherited live defaults and explicit overrides on both patch kinds.
$station=['id_station'=>77,'lat'=>38,'lng'=>23.7,'inner_radius_km'=>2,'outer_radius_km'=>null];
$area=['id_leg'=>99,'from_station_id'=>77,'to_station_id'=>null,
    'area_lat'=>38,'area_lng'=>23.7,'area_radius_km'=>2,'area_outer_radius_km'=>null];
$north=static fn(float $km) => 38+rad2deg($km/6371.0088);
testAssert(GrTransportNetworkGraph::candidates([$station],$north(4),23.7)[0]['ring']==='outer',
    'Station inherits configured global 6 km FAR boundary');
testAssert(GrTransportNetworkGraph::directAreas([$area],$north(4),23.7)[0]['_ring']==='outer',
    'Area inherits same global FAR boundary');
$settings['station_outer_km']=3;
GrTransportNetworkSettings::save($settings);
testAssert(GrTransportNetworkGraph::candidates([$station],$north(4),23.7)===[]
    && GrTransportNetworkGraph::directAreas([$area],$north(4),23.7)===[],
    'Global FAR change updates inherited station and area reach at lookup time');
$station['outer_radius_km']=8;
$area['area_outer_radius_km']=8;
testAssert(GrTransportNetworkGraph::candidates([$station],$north(7),23.7)[0]['ring']==='outer'
    && GrTransportNetworkGraph::directAreas([$area],$north(7),23.7)[0]['_ring']==='outer',
    'Per-place explicit overrides beat global setting');
testAssert(GrTransportNetworkSettings::effectiveOuterKm(null,4)===4.0,
    'Inherited FAR extent cannot remove normal reach');

echo "Operational defaults and runtime FAR radius tests passed\\n";
