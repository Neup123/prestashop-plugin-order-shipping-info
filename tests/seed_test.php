<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/RouteRepository.php';

// The pure fixture uses the same production field and state mapping as
// admin saves and install seeding, not private positional array indexes.
$rows = require __DIR__ . '/../grtransportshipping/demo/routes.php';
testAssert(count($rows) >= 6, 'Demo coverage insufficient');
foreach ($rows as $row) {
    testAssert(count($row) === 10, 'Seed must have every named writable field');
    $prepared = GrTransportRouteRepository::prepare($row);
    testAssert($prepared['price'] > 0 && $prepared['demo'] === 1
        && $prepared['route_status'] === GrTransportRouteState::AVAILABLE,
        'Invalid demo route or unverified shipping state');
}
echo "Demo seed tests passed\n";
