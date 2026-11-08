<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/RouteRepository.php';

$fields = GrTransportRouteFields::spec();
$schema = GrTransportRouteFields::schemaColumns();
$form = GrTransportRouteFields::formFields();
foreach (['origin_name', 'station_name', 'city', 'postal_prefix', 'notice'] as $name) {
    $limit = GrTransportRouteFields::limit($name);
    testAssert(($form[$name]['max'] ?? null) === $limit, 'Form lost field limit ' . $name);
    if ($fields[$name]['sql'] === 'VARCHAR') {
        testAssert(str_contains($schema, '`' . $name . '` VARCHAR(' . $limit . ')'),
            'SQL schema did not derive field limit ' . $name);
    }
}
testAssert(str_contains($schema, 'CHAR_LENGTH(`notice`) <= '
    . GrTransportRouteFields::limit('notice')), 'SQL notice CHECK differs from form/validation cap');

$sample = require __DIR__ . '/../grtransportshipping/demo/routes.php';
$valid = GrTransportRouteRepository::prepare($sample[0]);
testAssert($valid['mode'] === 'bus' && $valid['demo'] === 1, 'Named demo route mapping failed');
foreach ([
    ['origin_name' => str_repeat('A', GrTransportRouteFields::limit('origin_name') + 1)],
    ['station_name' => str_repeat('B', GrTransportRouteFields::limit('station_name') + 1)],
    ['city' => str_repeat('C', GrTransportRouteFields::limit('city') + 1)],
    ['postal_prefix' => '123456'],
    ['notice' => str_repeat('N', GrTransportRouteFields::limit('notice') + 1)],
    ['mode' => 'hovercraft'],
    ['route_status' => 'unknown'],
    ['price' => -1],
    ['price' => str_repeat('0', GrTransportRouteFields::limit('price') + 1)],
] as $invalid) {
    $caught = false;
    try {
        GrTransportRouteRepository::prepare(array_replace($sample[0], $invalid));
    } catch (InvalidArgumentException $e) {
        $caught = true;
    }
    testAssert($caught, 'Invalid named fixture must be rejected: ' . implode(',', array_keys($invalid)));
}
// Legacy TEXT rows can have NULL notices. An edit normalizes to the same
// empty string the form uses, rather than failing for an optional field.
$nullNotice = GrTransportRouteRepository::prepare(array_replace($sample[0], ['notice' => null]));
testAssert($nullNotice['notice'] === '', 'Nullable legacy notice should normalize to empty text');
echo "Route field schema/form/validation contract passed\n";
