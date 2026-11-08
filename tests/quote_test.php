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
echo "Quote tests passed\\n";
