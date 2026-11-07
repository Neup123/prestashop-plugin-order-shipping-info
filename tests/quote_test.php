<?php
require __DIR__ . '/../grtransportshipping/classes/QuoteEngine.php';
$routes = [
 ['id_route'=>1, 'active'=>1,'postal_prefix'=>'84','city'=>'', 'price'=>22],
 ['id_route'=>2, 'active'=>1,'postal_prefix'=>'843','city'=>'', 'price'=>30],
 ['id_route'=>3, 'active'=>0,'postal_prefix'=>'844','city'=>'', 'price'=>1],
 ['id_route'=>4, 'active'=>1,'postal_prefix'=>'26','city'=>'Patra', 'price'=>12.5],
];
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
check(GrTransportQuoteEngine::select($routes,'843 00','Naxos')['price'] === 30, 'longer prefix priority');
check(GrTransportQuoteEngine::select($routes,'841 00','Syros')['price'] === 22, 'general prefix');
check(GrTransportQuoteEngine::select($routes,'260 00','Patra')['price'] === 12.5, 'city and prefix');
check(GrTransportQuoteEngine::select($routes,'260 00','Other') === null, 'unmatched must fail closed');
check(GrTransportQuoteEngine::select($routes,'99999','Other') === null, 'unknown destination');
$conflicting = [
 ['id_route'=>10,'active'=>1,'postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Port A','price'=>20,'pickup_only'=>1],
 ['id_route'=>11,'active'=>1,'postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Port B','price'=>20,'pickup_only'=>1],
];
check(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos') === null, 'ambiguous stations must fail closed');
$conflicting[1]['station_name'] = 'Port A';
$conflicting[1]['price'] = 21;
check(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos') === null, 'ambiguous prices must fail closed');
$conflicting[1]['price'] = 20;
check(GrTransportQuoteEngine::select($conflicting, '84300', 'Naxos')['price'] === 20, 'identical rules remain usable');
$suspended = [
 ['id_route'=>20,'active'=>1,'postal_prefix'=>'84','city'=>'','mode'=>'ferry','station_name'=>'Regional port','price'=>20,'pickup_only'=>1],
 ['id_route'=>21,'active'=>0,'postal_prefix'=>'843','city'=>'','mode'=>'ferry','station_name'=>'Naxos Port','price'=>29,'pickup_only'=>1],
];
check(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos') === null, 'suspended Naxos may not fall back to regional rate');
check(GrTransportQuoteEngine::select($suspended, '84100', 'Syros')['price'] === 20, 'unaffected region remains available');
$suspended[1]['active'] = 1;
check(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos')['price'] === 29, 'route resumes on reactivation');
$suspended[0]['postal_prefix'] = '843';
check(GrTransportQuoteEngine::select($suspended, '84300', 'Naxos') === null, 'equally specific mismatched routes remain unavailable');
echo "Quote tests passed\\n";
