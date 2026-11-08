<?php
/**
 * PrestaShop 9.2 cart/address integration smoke test.
 * Runs only against a disposable shop created by tests/compose.yml.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop();
// The disposable demo shop can ship with a nonzero default handling fee.
// Explicitly establish a zero-fee baseline for existing route-price checks;
// the real-order adjustment scenario separately enables and verifies a fee.
testAssert(Configuration::updateValue('PS_SHIPPING_HANDLING', 0),
    'Cannot set deterministic CI handling-fee baseline');


$module = Module::getInstanceByName('grtransportshipping');
testAssert($module instanceof CarrierModule, 'Carrier module not loaded');
$carrierId = (int) Configuration::get('GRTRANS_CARRIER');
testAssert($carrierId > 0, 'No carrier registered');
$module->id_carrier = $carrierId;

$idGr = (int) Country::getByIso('GR');
testAssert($idGr > 0, 'Greek country missing');
$gr = new Country($idGr);
$defaultGroup = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
$eligible = Carrier::getCarriers(
    (int) Configuration::get('PS_LANG_DEFAULT'),
    true, false, (int) $gr->id_zone, [$defaultGroup],
    Carrier::PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE
);
$eligibleIds = array_map(static function ($r) { return (int) $r['id_carrier']; }, $eligible ?: []);
testAssert(in_array($carrierId, $eligibleIds, true), 'Carrier unavailable for Greek delivery zone/group');

$cases = [
    ['26221', 'Πάτρα', 12.50],
    ['54622', 'Θεσσαλονίκη', 18.00],
    ['24100', 'Καλαμάτα', 13.00],
    ['84300', 'Νάξος', 29.00],
    ['84100', 'Ερμούπολη', 24.00],
    ['84500', 'Άνδρος', 22.00],
    ['84002', 'Κέα', 18.00],
    ['84006', 'Κύθνος', 24.00],
    ['71601', 'Ηράκλειο', 45.00],
    ['85100', 'Ρόδος', 55.00],
    ['99999', 'Άγνωστο', false],
];
$idCustomer = (int) Db::getInstance()->getValue('SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer` ORDER BY id_customer ASC LIMIT 1');
// An unregistered checkout can have customer ID zero.
foreach ($cases as $case) {
    $address = new Address();
    $address->id_customer = $idCustomer;
    $address->alias = 'CI temporary';
    $address->firstname = 'Test';
    $address->lastname = 'Checkout';
    $address->address1 = 'Test street 1';
    $address->postcode = $case[0];
    $address->city = $case[1];
    $address->id_country = $idGr;
    testAssert($address->add(), 'Could not create address for ' . $case[1]);

    $cart = new Cart();
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_customer = $idCustomer;
    $cart->id_carrier = $carrierId;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $cart->id_shop = (int) Context::getContext()->shop->id;
    // The shipping module reads the address directly; no persisted cart is needed for this quote check.
    $actual = $module->getOrderShippingCostExternal($cart);
    $preCarrier = $module->hookDisplayBeforeCarrier(['cart' => $cart]);
    if ($case[2] === false) {
        testAssert($actual === false, 'Unexpected available quote for ' . $case[1]);
        testAssert(str_contains($preCarrier, 'alert alert-info grtransport-no-quote')
            && str_contains($preCarrier, 'No priced transport route matches this destination'),
            'Unmatched Greek address must explain missing route using independent hook');
    } else {
        testAssert(is_numeric($actual) && abs((float) $actual - $case[2]) < 0.001, 'Wrong quote for ' . $case[1]);
        testAssert($preCarrier === '', 'Matched Greek address must not show no-quote explanation');
    }
    $address->delete();
}
// A disabled precise island route must block a broader active regional rule.
$db = Db::getInstance();
$naxosRoute = null;
foreach ($db->executeS('SELECT id_route, postal_prefix, route_status FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [] as $rule) {
    if ((string) $rule['postal_prefix'] === '843') {
        $naxosRoute = GrTransportRouteRepository::find((int) $rule['id_route']);
        break;
    }
}
testAssert(is_array($naxosRoute), 'Missing Naxos seed for disabled-route test');
$regionalId = GrTransportRouteRepository::save([
    'mode' => 'ferry', 'origin_name' => 'Port of Piraeus',
    'station_name' => 'Regional island port', 'city' => '',
    'postal_prefix' => '84', 'price' => 9.00,
    'pickup_only' => 1, 'route_status' => GrTransportRouteState::AVAILABLE,
    'demo' => 1, 'notice' => 'CI regional fallback only',
]);
$address = new Address();
$address->id_customer = $idCustomer;
$address->alias = 'CI suspended Naxos';
$address->firstname = 'Test';
$address->lastname = 'Checkout';
$address->address1 = 'Test street 1';
$address->postcode = '84300';
$address->city = 'Νάξος';
$address->id_country = $idGr;
try {
    testAssert($address->add(), 'Could not create disabled-route address');
    $cart = new Cart();
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_carrier = $carrierId;
    GrTransportRouteRepository::save(array_replace($naxosRoute, [
        'route_status' => GrTransportRouteState::SUSPENDED,
    ]), 0, (int) $naxosRoute['id_route']);
    $module->clearQuoteCache();
    testAssert($module->getOrderShippingCostExternal($cart) === false,
        'Disabled Naxos must not inherit the generic region shipping price');
    GrTransportRouteRepository::save($naxosRoute, 0, (int) $naxosRoute['id_route']);
    $module->clearQuoteCache();
    testAssert(abs((float) $module->getOrderShippingCostExternal($cart) - 29.00) < 0.001,
        'Reactivated Naxos should quote its precise own price');
} finally {
    GrTransportRouteRepository::save($naxosRoute, 0, (int) $naxosRoute['id_route']);
    GrTransportRouteRepository::delete($regionalId);
    if ((int) $address->id) {
        $address->delete();
    }
}
// A persisted cart pins its quote across HTTP requests (module instances),
// including during a merchant price edit. Address changes require fresh quotes.
$lockAddress = new Address();
$lockAddress->id_customer = $idCustomer;
$lockAddress->alias = 'CI pinned quote';
$lockAddress->firstname = 'Test';
$lockAddress->lastname = 'Checkout';
$lockAddress->address1 = 'Test street 1';
$lockAddress->postcode = '84300';
$lockAddress->city = 'Νάξος';
$lockAddress->id_country = $idGr;
testAssert($lockAddress->add(), 'Could not create pinned quote address');
$pinnedCart = new Cart();
$pinnedCart->id_customer = $idCustomer;
$pinnedCart->id_address_delivery = (int) $lockAddress->id;
$pinnedCart->id_address_invoice = (int) $lockAddress->id;
$pinnedCart->id_carrier = $carrierId;
$pinnedCart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$pinnedCart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$pinnedCart->id_shop = (int) Context::getContext()->shop->id;
testAssert($pinnedCart->add(), 'Could not persist quote test cart');
try {
    testAssert(abs((float) $module->getOrderShippingCostExternal($pinnedCart) - 29.00) < 0.001,
        'Expected initial Naxos quote');
    GrTransportRouteRepository::save(array_replace($naxosRoute, [
        'price' => 99,
    ]), 0, (int) $naxosRoute['id_route']);
    $newRequestModule = new Grtransportshipping();
    testAssert(abs((float) $newRequestModule->getOrderShippingCostExternal($pinnedCart) - 29.00) < 0.001,
        'Later request must charge original pinned quote, not edited rate');
    Context::getContext()->cart = $pinnedCart;
    testAssert(str_contains($newRequestModule->hookDisplayCarrierExtraContent([]), 'Naxos Port'),
        'Pinned checkout notice must show original station');
    $lockAddress->postcode = '84100';
    $lockAddress->city = 'Ερμούπολη';
    testAssert($lockAddress->update(), 'Could not change address on cart');
    $newRequestModule->clearQuoteCache();
    testAssert(abs((float) $newRequestModule->getOrderShippingCostExternal($pinnedCart) - 24.00) < 0.001,
        'Changed delivery address must re-quote instead of using old pin');
} finally {
    GrTransportRouteRepository::save($naxosRoute, 0, (int) $naxosRoute['id_route']);
    $db->delete('grtrans_cart_quote', 'id_cart = ' . (int) $pinnedCart->id);
    $pinnedCart->delete();
    $lockAddress->delete();
}
echo "Real PrestaShop cart, Greek address, carrier visibility and quote smoke checks passed\n";
