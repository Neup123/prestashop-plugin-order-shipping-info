<?php
/**
 * Real PrestaShop module hook smoke: snapshot station and notice at order creation.
 * Uses a transient Order object to invoke the documented actionValidateOrder
 * contract; it does NOT replace browser-based paid-order acceptance.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop(false);


$module = Module::getInstanceByName('grtransportshipping');
testAssert($module instanceof CarrierModule, 'Module unavailable');
$carrierId = (int) Configuration::get('GRTRANS_CARRIER');
$idRoute = 0;
foreach (Db::getInstance()->executeS('SELECT id_route, postal_prefix FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [] as $row) {
    if ((string) $row['postal_prefix'] === '843') {
        $idRoute = (int) $row['id_route'];
        break;
    }
}
testAssert($carrierId > 0 && $idRoute > 0, 'Missing demo carrier or Naxos route');
$oldRow = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route` WHERE id_route = ' . $idRoute);
$address = new Address();
$address->id_customer = 0;
$address->alias = 'CI station pickup snapshot';
$address->firstname = 'Test';
$address->lastname = 'Order';
$address->address1 = 'Sample street 1';
$address->postcode = '84300';
$address->city = 'Νάξος';
$address->id_country = (int) Country::getByIso('GR');
testAssert($address->add(), 'Could not create delivery address');

$cart = new Cart();
$cart->id_address_delivery = (int) $address->id;
$order = new Order();
$order->id_carrier = $carrierId;
$order->id = (int) Db::getInstance()->getValue('SELECT COALESCE(MAX(id_order), 0) + 1000000 FROM `' . _DB_PREFIX_ . 'orders`');
$idOrder = (int) $order->id;
testAssert($idOrder > 0, 'Invalid temporary order id');
try {
    Db::getInstance()->delete('grtrans_order', 'id_order = ' . $idOrder);
    $module->hookActionValidateOrder(['order' => $order, 'cart' => $cart]);
    $original = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder);
    testAssert((bool) $original, 'Order shipment snapshot not stored');
    testAssert($original['station_name'] === 'Naxos Port', 'Wrong pickup station');
    testAssert(abs((float) $original['price'] - 29.0) < 0.001, 'Wrong saved freight estimate');
    testAssert((int) $original['pickup_only'] === 1, 'Pickup-only flag lost');
    testAssert(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'Naxos Port'), 'Customer detail omits pickup station');
    testAssert((str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'STATION PICKUP ONLY') || str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'ΠΑΡΑΛΑΒΗ ΜΟΝΟ ΑΠΟ ΣΤΑΘΜΟ')), 'Back office omits pickup');
    testAssert((str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'NO HOME DELIVERY') || str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'ΟΧΙ ΠΑΡΑΔΟΣΗ ΣΤΟ ΣΠΙΤΙ')), 'Confirmation implies home delivery');

    GrTransportRouteRepository::save(array_replace($oldRow, [
        'station_name' => 'Changed pickup location',
        'price' => 91.5,
        'notice' => 'Changed later',
    ]), 0, $idRoute);
    $after = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder);
    testAssert($after['station_name'] === $original['station_name']
        && $after['notice'] === $original['notice']
        && $after['price'] === $original['price'], 'Historical pickup snapshot mutated');

    // The quote hook must ignore orders using a different carrier.
    $other = new Order();
    $other->id = $idOrder + 1;
    $other->id_carrier = 0;
    $module->hookActionValidateOrder(['order' => $other, 'cart' => $cart]);
    testAssert(!Db::getInstance()->getValue('SELECT id_order FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . (int) $other->id), 'Unrelated order changed');
    echo "Order pickup snapshot, customer/admin render, and historical immutability passed\n";
} finally {
    GrTransportRouteRepository::save($oldRow, 0, $idRoute);
    Db::getInstance()->delete('grtrans_order', 'id_order = ' . $idOrder);
    $address->delete();
}
