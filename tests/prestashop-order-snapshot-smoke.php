<?php
/**
 * Real PrestaShop module hook smoke: snapshot station and notice at order creation.
 * Uses a transient Order object to invoke the documented actionValidateOrder
 * contract; it does NOT replace browser-based paid-order acceptance.
 */
require_once '/var/www/html/config/config.inc.php';

function transportAssert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException($message);
    }
}
$module = Module::getInstanceByName('grtransportshipping');
transportAssert($module instanceof CarrierModule, 'Module unavailable');
$carrierId = (int) Configuration::get('GRTRANS_CARRIER');
$idRoute = (int) Db::getInstance()->getValue(
    "SELECT id_route FROM `" . _DB_PREFIX_ . "grtrans_route` WHERE postal_prefix = '843' LIMIT 1"
);
transportAssert($carrierId > 0 && $idRoute > 0, 'Missing demo carrier or Naxos route');
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
transportAssert($address->add(), 'Could not create delivery address');

$cart = new Cart();
$cart->id_address_delivery = (int) $address->id;
$order = new Order();
$order->id_carrier = $carrierId;
$order->id = (int) Db::getInstance()->getValue('SELECT COALESCE(MAX(id_order), 0) + 1000000 FROM `' . _DB_PREFIX_ . 'orders`');
$idOrder = (int) $order->id;
transportAssert($idOrder > 0, 'Invalid temporary order id');
try {
    Db::getInstance()->delete('grtrans_order', 'id_order = ' . $idOrder);
    $module->hookActionValidateOrder(['order' => $order, 'cart' => $cart]);
    $original = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder);
    transportAssert((bool) $original, 'Order shipment snapshot not stored');
    transportAssert($original['station_name'] === 'Naxos Port', 'Wrong pickup station');
    transportAssert(abs((float) $original['price'] - 29.0) < 0.001, 'Wrong saved freight estimate');
    transportAssert((int) $original['pickup_only'] === 1, 'Pickup-only flag lost');
    transportAssert(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'Naxos Port'), 'Customer detail omits pickup station');
    transportAssert(str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'STATION PICKUP ONLY'), 'Back office omits pickup');
    transportAssert(str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'NO HOME DELIVERY'), 'Confirmation implies home delivery');

    transportAssert(Db::getInstance()->update('grtrans_route', [
        'station_name' => pSQL('Changed pickup location'),
        'price' => 91.5,
        'notice' => pSQL('Changed later'),
    ], 'id_route = ' . $idRoute), 'Could not simulate route price change');
    $after = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder);
    transportAssert($after['station_name'] === $original['station_name']
        && $after['notice'] === $original['notice']
        && $after['price'] === $original['price'], 'Historical pickup snapshot mutated');

    // The quote hook must ignore orders using a different carrier.
    $other = new Order();
    $other->id = $idOrder + 1;
    $other->id_carrier = 0;
    $module->hookActionValidateOrder(['order' => $other, 'cart' => $cart]);
    transportAssert(!Db::getInstance()->getValue('SELECT id_order FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . (int) $other->id), 'Unrelated order changed');
    echo "Order pickup snapshot, customer/admin render, and historical immutability passed\n";
} finally {
    Db::getInstance()->update('grtrans_route', [
        'station_name' => pSQL($oldRow['station_name']),
        'price' => (float) $oldRow['price'],
        'notice' => pSQL((string) $oldRow['notice']),
    ], 'id_route = ' . $idRoute);
    Db::getInstance()->delete('grtrans_order', 'id_order = ' . $idOrder);
    $address->delete();
}
