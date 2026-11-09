<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkTariff.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkQuote.php';
require_once __DIR__ . '/../grtransportshipping/classes/EstimateSettlement.php';

function tariffAssertFailure(callable $action,string $message): void {
    try { $action(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException($message);
}
$leg=['id_leg'=>1,'from_station_id'=>1,'to_station_id'=>2,
 'price'=>4,'tariff_type'=>'weight','rate_per_unit'=>2,
 'mode'=>'bus','operator_label'=>'KTEL','bidirectional'=>0,'estimate'=>0];
$weight=GrTransportNetworkTariff::leg($leg,['weight_kg'=>3,'volume_m3'=>0.02]);
testAssert($weight['price']===10.0 && $weight['tariff_min']===10.0,
    'Weight pricing includes base and per-kg component');
$volume=GrTransportNetworkTariff::leg(array_replace($leg,[
 'tariff_type'=>'volume','rate_per_unit'=>100
]),['volume_m3'=>0.02]);
testAssert($volume['price']===6.0,'Volumetric rate');
$flat=GrTransportNetworkTariff::leg(array_replace($leg,[
 'tariff_type'=>'flat','price'=>0
]),[]);
testAssert($flat['price']===0.0,'Legitimate zero-EUR flat service');
$missing=GrTransportNetworkTariff::leg($leg,[]);
testAssert($missing['price']===4.0 && $missing['tariff_missing_data']===1,
    'Missing weight falls back to configured flat component and is flagged');
testAssert(GrTransportNetworkTariff::leg(array_replace($leg,['price'=>0]),[])===null,
    'Missing weight with no fallback must not be offered free');
$estimated=GrTransportNetworkTariff::leg(array_replace($leg,[
 'estimate'=>1,'estimate_low_factor'=>0.8,'estimate_high_factor'=>1.2,
]),['weight_kg'=>3]);
testAssert($estimated['price']===12.0 && $estimated['tariff_min']===8.0
    && $estimated['tariff_max']===12.0 && $estimated['tariff_estimate']===1,
    'Estimate legs have transparent min-max cost and upper selection bound');
testAssert(GrTransportNetworkTariff::leg(array_replace($leg,[
 'estimate'=>1,'estimate_low_factor'=>1,'estimate_high_factor'=>1,
]),['weight_kg'=>3])===null,'Collapsed estimate must be rejected');

$cart=new class {
    public function getProducts() {
        return [[
            'weight'=>1.5,'width'=>20,'height'=>30,'depth'=>40,'cart_quantity'=>2,
        ]];
    }
};
$parcel=GrTransportNetworkTariff::cartMeasurements($cart);
testAssert($parcel['weight_kg']===3.0 && $parcel['volume_m3']===0.048,
    'Real cart line weight and cubic-centimetre volume accumulated by quantity');
$incomplete=new class {
    public function getProducts() {
        return [
            ['weight'=>2,'width'=>20,'height'=>0,'depth'=>40,'cart_quantity'=>1],
            ['weight'=>null,'width'=>10,'height'=>10,'depth'=>10,'cart_quantity'=>1],
        ];
    }
};
$parcel=GrTransportNetworkTariff::cartMeasurements($incomplete);
testAssert($parcel['weight_kg']===null && $parcel['volume_m3']===null
    && $parcel['missing_weight'] && $parcel['missing_volume'],
    'One missing product attribute makes total measurement unknown, not zero');

$stations=[
 ['id_station'=>1,'name'=>'Athens','type'=>'bus','lat'=>38,'lng'=>23.7,'is_origin_hub'=>1,'inner_radius_km'=>1,'outer_radius_km'=>5],
 ['id_station'=>2,'name'=>'Port','type'=>'ferry','lat'=>37.9,'lng'=>23.6,'is_origin_hub'=>0,'inner_radius_km'=>1,'outer_radius_km'=>5],
];
$network=['stations'=>$stations,'legs'=>[$leg],'transfers'=>[]];
$q=GrTransportNetworkQuote::resolve([],$network,'','',[37.9,23.6],null,'',['weight_kg'=>3]);
testAssert($q['quote']['price']===10.0,'Measured weight tariff flows through network quote');
$network['legs'][0]['estimate']=1;
$network['legs'][0]['estimate_low_factor']=0.8;
$network['legs'][0]['estimate_high_factor']=1.2;
$q=GrTransportNetworkQuote::resolve([],$network,'','',[37.9,23.6],null,'',['weight_kg'=>3]);
testAssert($q['quote']['estimate']===1 && $q['quote']['price']===0.0
    && $q['quote']['estimate_min']===8.0 && $q['quote']['estimate_max']===12.0,
    'Estimate path quote freezes a range but never creates payable shipping at checkout');
testAssert(GrTransportOrderCharge::expectedShipping($q['quote']+['handling_fee'=>9])===0.0,
    'Estimate checkout charge remains zero even with a handling fee');
testAssert(GrTransportOrderCharge::classify(0.0,$q['quote'],false)==='normal',
    'Zero charged estimate is classified valid by existing order invariant');
testAssert(GrTransportOrderCharge::classify(10.0,$q['quote'],false)===null,
    'Unexpected estimated charge at checkout is rejected');

$original=['network_path'=>$q['quote']['network_path'],'tariff_inputs'=>['weight_kg'=>3]];
$stored=['route_snapshot'=>json_encode($original,JSON_UNESCAPED_UNICODE)];
testAssert(GrTransportOrderSnapshot::originalQuote($stored)===$original,
    'Frozen dispatch JSON round-trips without network re-query');
$receipt=GrTransportEstimateSettlement::validate(11.0,'PAY-2026-001',7,8,12);
testAssert($receipt['amount']===11.0,'Verified separate receipt accepted within range');
tariffAssertFailure(static function () {
    GrTransportEstimateSettlement::validate(13,'PAY-2026-002',7,8,12);
},'Above-range external settlement not blocked');
tariffAssertFailure(static function () {
    GrTransportEstimateSettlement::validate(10,'',7,8,12);
},'Missing payment receipt must be rejected');
tariffAssertFailure(static function () {
    GrTransportEstimateSettlement::validate(10,'PAY-1',0,8,12);
},'Anonymous settlement must be rejected');
echo "Measured tariffs, estimate zero-charge, frozen path and settlement guards passed\n";
