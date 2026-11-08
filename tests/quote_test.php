<?php
require_once __DIR__ . '/bootstrap.php';
require __DIR__ . '/../grtransportshipping/classes/QuoteEngine.php';
$routes = [
 ['id_route'=>1, 'route_status'=>'available','postal_prefix'=>'84','city'=>'', 'price'=>22],
 ['id_route'=>2, 'route_status'=>'available','postal_prefix'=>'843','city'=>'', 'price'=>30],
 ['id_route'=>3, 'route_status'=>'suspended','postal_prefix'=>'844','city'=>'', 'price'=>1],
 ['id_route'=>4, 'route_status'=>'available','postal_prefix'=>'26','city'=>'Patra', 'price'=>12.5],
];

testAssert(GrTransportQuoteEngine::select($routes,'843 00','Naxos')['price'] === 30, 'longer prefix priority');
testAssert(GrTransportQuoteEngine::select($routes,'841 00','Syros')['price'] === 22, 'general prefix');
testAssert(GrTransportQuoteEngine::select($routes,'260 00','Patra')['price'] === 12.5, 'city and prefix');
testAssert(GrTransportQuoteEngine::select($routes,'260 00','Other') === null, 'unmatched must fail closed');
testAssert(GrTransportQuoteEngine::select($routes,'99999','Other') === null, 'unknown destination');
$conflicting = [
 ['id_route'=>10,'route_status'=>'available','postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Port A','price'=>20,'pickup_only'=>1],
 ['id_route'=>11,'route_status'=>'available','postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Port B','price'=>20,'pickup_only'=>1],
];
testAssert(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos') === null, 'ambiguous stations must fail closed');
$conflicting[1]['station_name'] = 'Port A';
$conflicting[1]['price'] = 21;
testAssert(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos') === null, 'ambiguous prices must fail closed');
$conflicting[1]['price'] = 20;
testAssert(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos')['price'] === 20, 'identical rules remain usable');
$suspended = [
 ['id_route'=>20,'route_status'=>'available','postal_prefix'=>'84','city'=>'','mode'=>'ferry','station_name'=>'Regional port','price'=>20,'pickup_only'=>1],
 ['id_route'=>21,'route_status'=>'suspended','postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Naxos Port','price'=>29,'pickup_only'=>1],
];
testAssert(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos') === null, 'suspended Naxos may not fall back to regional rate');
testAssert(GrTransportQuoteEngine::select($suspended, '84100', 'Syros')['price'] === 20, 'unaffected region remains available');
$suspended[1]['route_status'] = 'available';
testAssert(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos')['price'] === 29, 'route resumes on reactivation');
$suspended[0]['postal_prefix'] = '843';
testAssert(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos') === null, 'equally specific mismatched routes remain unavailable');
$suspended[0]['postal_prefix'] = '84';
$suspended[1]['route_status'] = 'suspended';
$draft = $suspended;
$draft[1]['route_status'] = 'draft';
testAssert(GrTransportQuoteEngine::select($draft, '84300', 'Naxos')['price'] === 20,
    'Draft does not suspend broader regional service');
testAssert(GrTransportQuoteEngine::resolve($suspended, '84300', 'Naxos')['status'] === 'suspended',
    'Suspended must be a distinct visible reason');
testAssert(GrTransportQuoteEngine::resolve($conflicting, '99999', 'Other')['status'] === 'unmatched',
    'Unmatched destinations are distinguishable');
$conflicting[1]['notice'] = 'Different warning';
testAssert(GrTransportQuoteEngine::resolve($conflicting, '84300', 'Naxos')['status'] === 'ambiguous',
    'Two same-priority routes with different notices must be ambiguous');
unset($conflicting[1]['notice']);
$conflicting[1]['demo'] = 1;
testAssert(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos') === null,
    'Different verification statuses cannot silently choose earlier row');
testAssert(count(GrTransportQuoteEngine::shadowedSuspensions($suspended)) === 1,
    'Admin warning must detect suspended region override');
testAssert(GrTransportQuoteEngine::shadowedSuspensions($draft) === [],
    'Draft must never produce suspension warning');

// #45: Greek city-only routes (no postcode fallback) need accent-insensitive
// comparison, yet genuinely distinct cities must remain unavailable.
$greekCity = [
    'id_route'=>100, 'route_status'=>'available', 'postal_prefix'=>'',
    'city'=>'Νάξος', 'mode'=>'ferry', 'station_name'=>'Naxos Port',
    'price'=>31, 'pickup_only'=>1, 'demo'=>1, 'notice'=>'City-only rate',
];
foreach (['Νάξος', 'Ναξος', 'ΝΑΞΟΣ', 'Νάξοσ', "Να\u{0301}ξος"] as $customerCity) {
    $result = GrTransportQuoteEngine::resolve([$greekCity], '00000', $customerCity);
    testAssert($result['status'] === 'quoted' && (float)$result['quote']['price'] === 31,
        'City-only Naxos failed for: ' . $customerCity);
}
$chaidari = array_replace($greekCity, [
    'id_route'=>101, 'city'=>'Χαϊδάρι', 'station_name'=>'Chaidari station',
]);
foreach (['Χαϊδάρι', 'Χαιδαρι', 'ΧΑΪΔΑΡΙ', 'ΧΑΪΔΆΡΙ'] as $customerCity) {
    testAssert(GrTransportQuoteEngine::resolve([$chaidari], '00000', $customerCity)['status'] === 'quoted',
        'Dialytika/tonos match failed: ' . $customerCity);
}
$kos = array_replace($greekCity, [
    'id_route'=>102, 'city'=>'Κως', 'station_name'=>'Kos port',
]);
foreach (['Κως', 'Κωσ', 'ΚΩΣ', 'κως'] as $customerCity) {
    testAssert(GrTransportQuoteEngine::resolve([$kos], '00000', $customerCity)['status'] === 'quoted',
        'Final sigma match failed: ' . $customerCity);
}
foreach (['Λάρισα', 'Κρήτη', 'Χανιά'] as $otherCity) {
    testAssert(GrTransportQuoteEngine::resolve([$greekCity], '00000', $otherCity)['status'] === 'unmatched',
        'Unserved Greek city must fail closed: ' . $otherCity);
}
// Accent-equivalent stored cities compete under the existing ambiguity rule.
// Distinct station details cause an unavailable quote; identical details work.
$accentDuplicate = array_replace($greekCity, [
    'id_route'=>103, 'city'=>'Ναξοσ', 'station_name'=>'Different Naxos port',
]);
testAssert(GrTransportQuoteEngine::resolve([$greekCity, $accentDuplicate], '00000', 'ΝΑΞΟΣ')['status'] === 'ambiguous',
    'Normalized city ties with differing stations must fail closed');
$accentDuplicate['station_name'] = 'Naxos Port';
testAssert(GrTransportQuoteEngine::resolve([$greekCity, $accentDuplicate], '00000', 'Ναξος')['status'] === 'quoted',
    'Identical normalized city records should remain usable');
testAssert($greekCity['city'] === 'Νάξος' && $accentDuplicate['city'] === 'Ναξοσ',
    'Matching must not rewrite stored Greek city names');
// Preserve the existing Latin matching rule and unknown-city denial.
testAssert((float) GrTransportQuoteEngine::select($routes, '26000', 'PATRA')['price'] === 12.5,
    'Latin city comparison must remain case-insensitive');
testAssert(GrTransportQuoteEngine::select($routes, '26000', 'OTHER') === null,
    'Unknown Latin city must fail closed');

echo "Quote tests passed\\n";
