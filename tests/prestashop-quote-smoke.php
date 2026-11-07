<?php
/**
 * PrestaShop 9.2 cart/address integration smoke test.
 * Runs only against a disposable shop created by tests/compose.yml.
 */
require_once '/var/www/html/config/config.inc.php';

function assertion($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$module = Module::getInstanceByName('grtransportshipping');
assertion($module instanceof CarrierModule, 'Carrier module not loaded');
$carrierId = (int) Configuration::get('GRTRANS_CARRIER');
assertion($carrierId > 0, 'No carrier registered');
$module->id_carrier = $carrierId;

$idGr = (int) Country::getByIso('GR');
assertion($idGr > 0, 'Greek country missing');
$gr = new Country($idGr);
$defaultGroup = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
$eligible = Carrier::getCarriers(
    (int) Configuration::get('PS_LANG_DEFAULT'),
    true, false, (int) $gr->id_zone, [$defaultGroup],
    Carrier::PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE
);
$eligibleIds = array_map(static function ($r) { return (int) $r['id_carrier']; }, $eligible ?: []);
assertion(in_array($carrierId, $eligibleIds, true), 'Carrier unavailable for Greek delivery zone/group');

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
    assertion($address->add(), 'Could not create address for ' . $case[1]);

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
    if ($case[2] === false) {
        assertion($actual === false, 'Unexpected available quote for ' . $case[1]);
    } else {
        assertion(is_numeric($actual) && abs((float) $actual - $case[2]) < 0.001, 'Wrong quote for ' . $case[1]);
    }
    $address->delete();
}
// A disabled precise island route must block a broader active regional rule.
$db = Db::getInstance();
$naxosRoute = null;
foreach ($db->executeS('SELECT id_route, postal_prefix, active FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [] as $rule) {
    if ((string) $rule['postal_prefix'] === '843') {
        $naxosRoute = $rule;
        break;
    }
}
assertion(is_array($naxosRoute), 'Missing Naxos seed for disabled-route test');
assertion($db->insert('grtrans_route', [
    'mode' => pSQL('ferry'), 'origin_name' => pSQL('Port of Piraeus'),
    'station_name' => pSQL('Regional island port'), 'city' => pSQL(''),
    'postal_prefix' => pSQL('84'), 'price' => 9.00,
    'pickup_only' => 1, 'active' => 1, 'demo' => 1,
    'notice' => pSQL('CI regional fallback only'),
]), 'Unable to create broad regional route');
$regionalId = (int) $db->Insert_ID();
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
    assertion($address->add(), 'Could not create disabled-route address');
    $cart = new Cart();
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_carrier = $carrierId;
    assertion($db->update('grtrans_route', ['active' => 0], 'id_route = ' . (int) $naxosRoute['id_route']),
        'Could not suspend Naxos');
    assertion($module->getOrderShippingCostExternal($cart) === false,
        'Disabled Naxos must not inherit the generic region shipping price');
    assertion($db->update('grtrans_route', ['active' => 1], 'id_route = ' . (int) $naxosRoute['id_route']),
        'Could not reactivate Naxos');
    assertion(abs((float) $module->getOrderShippingCostExternal($cart) - 29.00) < 0.001,
        'Reactivated Naxos should quote its precise own price');
} finally {
    $db->update('grtrans_route', ['active' => (int) $naxosRoute['active']], 'id_route = ' . (int) $naxosRoute['id_route']);
    $db->delete('grtrans_route', 'id_route = ' . $regionalId);
    if ((int) $address->id) {
        $address->delete();
    }
}
echo "Real PrestaShop cart, Greek address, carrier visibility and quote smoke checks passed\n";
