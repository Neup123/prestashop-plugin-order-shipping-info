<?php
require_once __DIR__ . '/bootstrap.php';
require __DIR__ . '/../grtransportshipping/classes/QuoteEngine.php';
require_once __DIR__ . '/../grtransportshipping/classes/RouteRepository.php';
$seed = require __DIR__ . '/../grtransportshipping/demo/routes.php';
$routes = [];
foreach ($seed as $i => $r) {
    $routes[] = ['id_route' => $i + 1] + GrTransportRouteRepository::prepare($r);
}
foreach ([['26221','Patra','bus',12.5],['54622','Thessaloniki','bus',18.0],['24100','Kalamata','bus',13.0],['84300','Naxos','ferry',29.0],['84100','Ermoupoli','ferry',24.0],['84500','Andros','ferry',22.0],['84002','Kea','ferry',18.0],['84006','Kythnos','ferry',24.0],['71601','Heraklion','flight',45.0],['85100','Rhodes','flight',55.0]] as $case) {
    $q = GrTransportQuoteEngine::select($routes, $case[0], $case[1]);
    if (!$q || $q['mode'] !== $case[2] || (float)$q['price'] !== $case[3] || !$q['pickup_only']) {
        throw new RuntimeException('Incorrect demo quote for ' . $case[1]);
    }
}
$greek = GrTransportQuoteEngine::select($routes, '843 00', 'Νάξος');
if (!$greek || $greek['mode'] !== 'ferry' || (float) $greek['price'] !== 29.0) {
    throw new RuntimeException('Greek address city must match recognized postal prefix');
}
if (GrTransportQuoteEngine::select($routes, '99999', 'unknown') !== null) {
    throw new RuntimeException('Unknown postcode must not be quoted');
}
echo "Demo route quotes passed\n";
