<?php
/**
 * Real core order lifecycle test: uses PaymentModule::validateOrder, not a
 * manually fabricated Order object. Disposable PrestaShop 9.2 Docker only.
 * No funds charged and no mail emitted.
 */
require_once '/var/www/html/config/config.inc.php';
global $kernel;
$kernel = new AdminKernel('prod', false);
$kernel->boot();

function orderCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idCarrier = (int) Configuration::get('GRTRANS_CARRIER');
$idGr = (int) Country::getByIso('GR');
orderCheck($idLang > 0 && $idCarrier > 0 && $idGr > 0, 'Missing language, Greek country or carrier');
$context = Context::getContext();
$customer = new Customer();
$customer->firstname = 'CI';
$customer->lastname = 'RealOrder';
$customer->email = 'ci-real-order@example.invalid';
$customer->passwd = Tools::hash('DummyNeverUsedCIPassword123');
$customer->active = 1;
$customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
orderCheck($customer->add(), 'Cannot create disposable customer');
$customer->addGroups([(int) $customer->id_default_group]);

$address = new Address();
$address->id_customer = (int) $customer->id;
$address->alias = 'CI real Naxos order';
$address->firstname = 'CI';
$address->lastname = 'RealOrder';
$address->address1 = 'Test street 1';
$address->postcode = '84300';
$address->city = 'Νάξος';
$address->id_country = $idGr;
orderCheck($address->add(), 'Cannot create Naxos delivery address');

$product = new Product();
$product->name = [$idLang => 'CI order parcel'];
$product->link_rewrite = [$idLang => 'ci-order-parcel'];
$product->description_short = [$idLang => 'Disposable order-validation parcel'];
$product->id_shop_default = (int) $context->shop->id;
$product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
$product->id_tax_rules_group = 0;
$product->price = 10;
$product->active = 1;
$product->available_for_order = 1;
$product->visibility = 'both';
orderCheck($product->add(), 'Cannot create physical parcel');
orderCheck($product->updateCategories([(int) $product->id_category_default]), 'Cannot associate parcel category');
StockAvailable::setQuantity((int) $product->id, 0, 100);
orderCheck($product->checkAccess((int) $customer->id), 'Customer cannot access physical parcel');

$cart = new Cart();
$cart->id_customer = (int) $customer->id;
$cart->secure_key = (string) $customer->secure_key;
$cart->id_address_delivery = (int) $address->id;
$cart->id_address_invoice = (int) $address->id;
$cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$cart->id_lang = $idLang;
$cart->id_carrier = $idCarrier;
$cart->id_shop = (int) $context->shop->id;
orderCheck($cart->add(), 'Cannot create persisted cart');
orderCheck($cart->updateQty(1, (int) $product->id), 'Cannot put parcel in cart');
$context->cart = $cart;
$context->customer = $customer;
$context->country = new Country($idGr);
$context->currency = new Currency((int) $cart->id_currency);
$context->language = new Language($idLang);
$available = $cart->getDeliveryOptionList($context->country, true);
$key = null;
foreach ($available[(int) $address->id] ?? [] as $candidateKey => $entry) {
    foreach ($entry['carrier_list'] ?? [] as $item) {
        if ((int) ($item['instance']->id ?? 0) === $idCarrier) {
            $key = $candidateKey;
            break 2;
        }
    }
}
orderCheck(is_string($key), 'Our carrier is not a selectable checkout option');
$cart->setDeliveryOption([(int) $address->id => $key]);
orderCheck($cart->update(), 'Cannot persist delivery selection');
$freight = $cart->getPackageShippingCost($idCarrier, false, $context->country);
orderCheck(is_numeric($freight) && abs((float) $freight - 29.0) < 0.001, 'Incorrect Naxos checkout shipping quote');

$payment = Module::getInstanceByName('ps_checkpayment');
orderCheck($payment instanceof PaymentModule && Module::isInstalled('ps_checkpayment'), 'PrestaShop cheque payment module unavailable in disposable shop');
if (!$payment->active) {
    orderCheck((bool) Module::enableByName('ps_checkpayment'), 'Cannot enable test cheque payment module');
    $payment = Module::getInstanceByName('ps_checkpayment');
}
orderCheck($payment instanceof PaymentModule && $payment->active, 'Test payment module not active');
$pendingState = (int) Configuration::get('PS_OS_CHEQUE');
orderCheck($pendingState > 0, 'Missing waiting-for-cheque-payment order state');
$oldMailMode = (int) Configuration::get('PS_MAIL_METHOD');
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_DISABLE);
try {
    $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
    orderCheck($amount >= 39, 'Checkout total did not include transport fee');
    orderCheck($payment->validateOrder(
        (int) $cart->id, $pendingState, $amount, 'CI cheque payment pending', null, [],
        (int) $cart->id_currency, false, (string) $cart->secure_key
    ), 'PrestaShop PaymentModule::validateOrder returned failure');
    $idOrder = (int) $payment->currentOrder;
    orderCheck($idOrder > 0, 'No real PrestaShop order was created');
    $order = new Order($idOrder);
    orderCheck(Validate::isLoadedObject($order), 'Order record not persisted');
    orderCheck((int) $order->id_carrier === $idCarrier, 'Real order used wrong carrier');
    $saved = Db::getInstance()->getRow(
        'SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder
    );
    orderCheck(is_array($saved), 'Carrier hook failed to persist actual order pickup');
    orderCheck($saved['mode'] === 'ferry' && $saved['station_name'] === 'Naxos Port',
        'Actual order has incorrect transport mode/station');
    orderCheck(abs((float) $saved['price'] - 29.0) < 0.001 && (int) $saved['pickup_only'] === 1,
        'Actual order has incorrect saved amount or pickup status');
    $module = Module::getInstanceByName('grtransportshipping');
    orderCheck(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'Naxos Port'),
        'Customer order detail omitted pickup');
    orderCheck(str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'NO HOME DELIVERY'),
        'Order confirmation omitted no-home-delivery warning');
    orderCheck(str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'STATION PICKUP ONLY'),
        'Admin order omitted pickup notice');
    // Verify repeat hooks are idempotent rather than attempting a second
    // primary-key INSERT, and reconstruct a missing record from the pinned
    // cart quote, never from a newly edited carrier route.
    $module->hookActionValidateOrder(['order' => $order, 'cart' => $cart]);
    orderCheck(GrTransportOrderSnapshot::status($idOrder) === GrTransportOrderSnapshot::STORED,
        'Second validation must retain the original snapshot');
    $db = Db::getInstance();
    $routeId = 0;
    foreach ($db->executeS('SELECT id_route, station_name FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [] as $routeRow) {
        if ((string) $routeRow['station_name'] === 'Naxos Port') {
            $routeId = (int) $routeRow['id_route'];
            break;
        }
    }
    orderCheck($routeId > 0, 'Could not locate demo Naxos route for repair exercise');
    orderCheck($db->update('grtrans_route', ['price' => 99], 'id_route = ' . $routeId),
        'Could not simulate later merchant tariff edit');
    try {
        orderCheck($db->delete('grtrans_order', 'id_order = ' . $idOrder),
            'Cannot simulate missing order snapshot');
        orderCheck(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'temporarily unavailable'),
            'Missing pickup snapshot must be visible to customer');
        orderCheck(str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'Repair missing pickup snapshot'),
            'Missing pickup snapshot must be visible to administrator');
        $repaired = GrTransportOrderSnapshot::repair($idOrder);
        orderCheck((float) $repaired['price'] === 29.0 && $repaired['station_name'] === 'Naxos Port',
            'Repair must restore original checkout pickup and price, not later tariff');
        $repairedAgain = GrTransportOrderSnapshot::repair($idOrder);
        orderCheck((float) $repairedAgain['price'] === 29.0,
            'Repeat repair should be idempotent using the original cart quote');
        orderCheck(str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'Naxos Port'),
            'Customer-facing order confirmation must recover after repair');
    } finally {
        $db->update('grtrans_route', ['price' => 29], 'id_route = ' . $routeId);
    }
    echo 'Real pending-payment PrestaShop order created: ferry freight EUR 29.00 and Naxos station persisted' . PHP_EOL;
} finally {
    Configuration::updateValue('PS_MAIL_METHOD', $oldMailMode);
}
