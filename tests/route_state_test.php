<?php
require_once __DIR__ . '/bootstrap.php';
require __DIR__ . '/../grtransportshipping/classes/RouteState.php';


$draft = GrTransportRouteState::transition(null, [
    'route_status' => 'draft', 'demo' => '1', 'pickup_only' => '1',
], 0);
testAssert($draft['route_status'] === 'draft' && $draft['verified_at'] === null,
    'new draft must be unverified and must have no confirmation provenance');
$live = GrTransportRouteState::transition($draft, [
    'route_status' => 'available', 'demo' => '0', 'pickup_only' => '1',
], 44);
testAssert($live['verified_by_employee'] === 44 && $live['verified_at'] !== null,
    'verified tariff must preserve confirming employee and time');
$suspended = GrTransportRouteState::transition($live, [
    'route_status' => 'suspended', 'demo' => '0', 'pickup_only' => '1',
], 0);
testAssert($suspended['verified_at'] === $live['verified_at'],
    'suspension without tariff change preserves verification provenance');
$revised = GrTransportRouteState::transition($live, [
    'route_status' => 'available', 'demo' => '1', 'pickup_only' => '0',
], 0);
testAssert($revised['verified_at'] === null && $revised['verified_by_employee'] === null,
    'return to demo clears verification; delivery policy is explicit');
foreach ([
    ['route_status' => 'invalid', 'demo' => '1', 'pickup_only' => '1'],
    ['route_status' => 'available', 'demo' => '2', 'pickup_only' => '1'],
] as $invalid) {
    $threw = false;
    try { GrTransportRouteState::transition(null, $invalid, 22); }
    catch (InvalidArgumentException $e) { $threw = true; }
    testAssert($threw, 'Invalid state must fail closed');
}
$threw = false;
try {
    GrTransportRouteState::transition($draft, [
        'route_status' => 'available', 'demo' => '0', 'pickup_only' => '1',
    ], 0);
} catch (InvalidArgumentException $e) { $threw = true; }
testAssert($threw, 'Unattributed confirmation must fail');
echo "Route state transitions passed\n";
