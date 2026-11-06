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
    ['71601', 'Ηράκλειο', 45.00],
    ['85100', 'Ρόδος', 55.00],
    ['99999', 'Άγνωστο', false],
];
$idCustomer = (int) Db::getInstance()->getValue('SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer` ORDER BY id_customer ASC LIMIT 1');
assertion($idCustomer > 0, 'No demo customer available');
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
    assertion($cart->add(), 'Unable to create cart');
    $actual = $module->getOrderShippingCostExternal($cart);
    if ($case[2] === false) {
        assertion($actual === false, 'Unexpected available quote for ' . $case[1]);
    } else {
        assertion(is_numeric($actual) && abs((float) $actual - $case[2]) < 0.001, 'Wrong quote for ' . $case[1]);
    }
    $cart->delete();
    $address->delete();
}
echo "Real PrestaShop cart, Greek address, carrier visibility and quote smoke checks passed\n";
