<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/QuoteCheck.php';
require_once __DIR__ . '/../grtransportshipping/classes/OrderCharge.php';
$routes = require __DIR__ . '/../grtransportshipping/demo/routes.php';
foreach ($routes as $i => &$route) {
    $route['id_route'] = $i + 1;
}
unset($route);
// Real destination inputs go to the same engine as checkout, without cart state.
foreach ([['84300', 'Νάξος'], ['26221', 'Πάτρα'], ['99999', 'Unknown']] as [$postal, $city]) {
    $checkout = GrTransportQuoteEngine::resolve($routes, $postal, $city);
    $page = GrTransportQuoteCheck::text($routes, $postal);
    testAssert($checkout === $page, 'Checkout/page route parity: ' . $postal);
    if ($page['quote']) {
        testAssert(GrTransportOrderCharge::expectedShipping($page['quote'])
            === GrTransportOrderCharge::expectedShipping($checkout['quote']), 'Charged amount parity');
        testAssert($page['quote']['pickup_only'] === 1, 'Station pickup flag retained');
    }
}
// Non-pickup merchant route, not just demo fixtures.
$custom = array_replace($routes[0], ['postal_prefix' => '', 'city' => 'Χαϊδάρι', 'pickup_only' => 0, 'demo' => 0]);
foreach (['Χαϊδάρι', 'Χαιδαρι', 'ΧΑΪΔΑΡΙ', "Χαι\u{0308}δα\u{0301}ρι"] as $input) {
    $page = GrTransportQuoteCheck::text([$custom], $input);
    testAssert($page === GrTransportQuoteEngine::resolve([$custom], '', $input), 'Custom Greek city parity');
    testAssert($page['quote']['price'] === 12.5 && $page['quote']['pickup_only'] === 0, 'Priced route preserved');
}
foreach (['Ναξος', 'ΝΑΞΟΣ', 'Νάξοσ', "Να\u{0301}ξος", 'Naxos'] as $input) {
    testAssert(GrTransportQuoteCheck::text($routes, $input)['quote'] === $routes[3], 'Seed alias ' . $input);
}
foreach (GrTransportQuoteCheck::stations() as $station) {
    $pin = GrTransportQuoteCheck::pin($routes, $station['lat'], $station['lng']);
    testAssert($pin['station']['name'] === $station['name'], 'Nearest station must be itself');
    testAssert($pin['quote'] === GrTransportQuoteCheck::text($routes, $station['postcode'])['quote'], 'Pin/postcode parity');
    testAssert($pin['quote'] === GrTransportQuoteCheck::text($routes, $station['cities'][1])['quote'], 'Pin/city parity');
    testAssert(count(GrTransportQuoteCheck::routeStations($pin['quote'])) === 2, 'Origin/destination mapped');
}
$naxos = GrTransportQuoteCheck::stations()[3];
// Exact great-circle offsets north of Naxos. No other terminal lies within 25 km.
foreach ([[24.999, true], [25.001, false]] as [$km, $inside]) {
    $lat = $naxos['lat'] + rad2deg($km / 6371.0088);
    testAssert((GrTransportQuoteCheck::pin($routes, $lat, $naxos['lng'])['quote'] !== null) === $inside, 'Radius boundary');
}
$near = GrTransportQuoteCheck::pin($routes, $naxos['lat'] + .01, $naxos['lng'] + .01);
testAssert($near['station']['name'] === 'Naxos Port', 'Nearby pin chooses nearest station');
foreach ([[0, 0], [91, 20], [38, 181], ['bad', 25], [[], 25], [INF, 25]] as [$lat, $lng]) {
    testAssert(GrTransportQuoteCheck::pin($routes, $lat, $lng)['quote'] === null, 'Invalid/distant pin denied');
}
$blocked = $routes;
$blocked[3]['route_status'] = 'suspended';
testAssert(GrTransportQuoteCheck::pin($blocked, $naxos['lat'], $naxos['lng'])['status'] === 'suspended', 'No bypass of suspended nearest station');
$draft = $routes;
$draft[3]['route_status'] = 'draft';
testAssert(GrTransportQuoteCheck::pin($draft, $naxos['lat'], $naxos['lng'])['quote'] === null, 'No draft pricing');
$conflict = $routes;
$conflict[] = array_replace($routes[3], ['id_route' => 99, 'price' => 300]);
testAssert(GrTransportQuoteCheck::text($conflict, 'Ναξος')['status'] === 'ambiguous', 'No bypass of ambiguity');
$cityOnly = [array_replace($routes[3], ['postal_prefix' => '', 'city' => 'Νάξος'])];
testAssert(GrTransportQuoteCheck::pin($cityOnly, $naxos['lat'], $naxos['lng'])['quote'] === $cityOnly[0], 'Pin supports Greek city-only routes');
$renamed = array_replace($routes[3], ['station_name' => '<script>renamed</script>']);
testAssert(GrTransportQuoteCheck::routeStations($renamed) === [], 'Never plot stale merchant station coordinates');
// A merchant's new combined city/postcode rule still needs only one input.
$combined = [array_replace($custom, ['postal_prefix' => '124'])];
foreach (['Χαιδαρι', '12461'] as $input) {
    testAssert(GrTransportQuoteCheck::text($combined, $input)['quote'] === $combined[0], 'Unambiguous partial destination');
}
$combined[] = array_replace($combined[0], ['id_route' => 101, 'postal_prefix' => '125', 'price' => 50]);
testAssert(GrTransportQuoteCheck::text($combined, 'Χαιδαρι')['status'] === 'ambiguous', 'Do not guess between multiple postcode areas');
// Overlapping 25 km circles still choose the closer station, never catalogue-first.
$syros = GrTransportQuoteCheck::stations()[4];
$lat = $naxos['lat'] * .45 + $syros['lat'] * .55;
$lng = $naxos['lng'] * .45 + $syros['lng'] * .55;
$nearest = GrTransportQuoteCheck::pin($routes, $lat, $lng);
testAssert($nearest['station']['name'] === 'Syros Port', 'Nearest wins in overlapping station radii');
echo "Quote-check parity, Greek input, nearest station and radius tests passed\n";
