<?php
/**
 * Real core order lifecycle test: uses PaymentModule::validateOrder, not a
 * manually fabricated Order object. Disposable PrestaShop 9.2 Docker only.
 * No funds charged and no mail emitted.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop(true);

// Establish an independent disposable-shop baseline. A fresh PrestaShop
// installation can enable handling fees by default; this smoke expects the
// bare EUR 29 route before its explicit handling-fee scenario below.
testAssert(Configuration::updateValue('PS_SHIPPING_HANDLING', 0),
    'Could not establish zero-fee real-order CI baseline');


$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idCarrier = (int) Configuration::get('GRTRANS_CARRIER');
$idGr = (int) Country::getByIso('GR');
testAssert($idLang > 0 && $idCarrier > 0 && $idGr > 0, 'Missing language, Greek country or carrier');
$context = Context::getContext();
$customer = new Customer();
$customer->firstname = 'CI';
$customer->lastname = 'RealOrder';
$customer->email = 'ci-real-order@example.invalid';
$customer->passwd = Tools::hash('DummyNeverUsedCIPassword123');
$customer->active = 1;
$customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
testAssert($customer->add(), 'Cannot create disposable customer');
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
testAssert($address->add(), 'Cannot create Naxos delivery address');

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
testAssert($product->add(), 'Cannot create physical parcel');
testAssert($product->updateCategories([(int) $product->id_category_default]), 'Cannot associate parcel category');
StockAvailable::setQuantity((int) $product->id, 0, 100);
testAssert($product->checkAccess((int) $customer->id), 'Customer cannot access physical parcel');

/**
 * One fixture path for baseline, voucher, and handling-fee orders. Uses the
 * real Cart, delivery options and physical product. No mock carrier prices.
 * Coverage: one Greek address/one physical package; not multi-shipment.
 */
function makeCheckoutCart(Customer $customer, Address $address, Product $product,
    int $carrierId, int $langId, int $countryId, Context $context): Cart
{
    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->secure_key = (string) $customer->secure_key;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_lang = $langId;
    $cart->id_carrier = $carrierId;
    $cart->id_shop = (int) $context->shop->id;
    testAssert($cart->add(), 'Cannot create persisted cart');
    testAssert($cart->updateQty(1, (int) $product->id), 'Cannot put parcel in cart');
    $context->cart = $cart;
    $context->customer = $customer;
    $context->country = new Country($countryId);
    $context->currency = new Currency((int) $cart->id_currency);
    $context->language = new Language($langId);
    $available = $cart->getDeliveryOptionList($context->country, true);
    $key = null;
    foreach ($available[(int) $address->id] ?? [] as $candidateKey => $entry) {
        foreach ($entry['carrier_list'] ?? [] as $item) {
            if ((int) ($item['instance']->id ?? 0) === $carrierId) {
                $key = $candidateKey;
                break 2;
            }
        }
    }
    testAssert(is_string($key), 'Our carrier is not a selectable checkout option');
    $cart->setDeliveryOption([(int) $address->id => $key]);
    testAssert($cart->update(), 'Cannot persist delivery selection');
    return $cart;
}

$cart = makeCheckoutCart($customer, $address, $product, $idCarrier, $idLang, $idGr, $context);
$freight = $cart->getPackageShippingCost($idCarrier, false, $context->country);
testAssert(is_numeric($freight) && abs((float) $freight - 29.0) < 0.001, 'Incorrect Naxos checkout shipping quote');

$payment = Module::getInstanceByName('ps_checkpayment');
testAssert($payment instanceof PaymentModule && Module::isInstalled('ps_checkpayment'), 'PrestaShop cheque payment module unavailable in disposable shop');
if (!$payment->active) {
    testAssert((bool) Module::enableByName('ps_checkpayment'), 'Cannot enable test cheque payment module');
    $payment = Module::getInstanceByName('ps_checkpayment');
}
testAssert($payment instanceof PaymentModule && $payment->active, 'Test payment module not active');
$pendingState = (int) Configuration::get('PS_OS_CHEQUE');
testAssert($pendingState > 0, 'Missing waiting-for-cheque-payment order state');
$oldMailMode = (int) Configuration::get('PS_MAIL_METHOD');
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_DISABLE);
try {
    $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
    testAssert($amount >= 39, 'Checkout total did not include transport fee');
    testAssert($payment->validateOrder(
        (int) $cart->id, $pendingState, $amount, 'CI cheque payment pending', null, [],
        (int) $cart->id_currency, false, (string) $cart->secure_key
    ), 'PrestaShop PaymentModule::validateOrder returned failure');
    $idOrder = (int) $payment->currentOrder;
    testAssert($idOrder > 0, 'No real PrestaShop order was created');
    $order = new Order($idOrder);
    testAssert(Validate::isLoadedObject($order), 'Order record not persisted');
    testAssert((int) $order->id_carrier === $idCarrier, 'Real order used wrong carrier');
    $saved = Db::getInstance()->getRow(
        'SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $idOrder
    );
    testAssert(is_array($saved), 'Carrier hook failed to persist actual order pickup');
    testAssert($saved['mode'] === 'ferry' && $saved['station_name'] === 'Naxos Port',
        'Actual order has incorrect transport mode/station');
    testAssert(abs((float) $saved['price'] - 29.0) < 0.001 && (int) $saved['pickup_only'] === 1,
        'Actual order has incorrect saved amount or pickup status');
    $module = Module::getInstanceByName('grtransportshipping');
    testAssert(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'Naxos Port'),
        'Customer order detail omitted pickup');
    testAssert(str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'NO HOME DELIVERY'),
        'Order confirmation omitted no-home-delivery warning');
    testAssert(str_contains($module->hookDisplayAdminOrder(['id_order' => $idOrder]), 'STATION PICKUP ONLY'),
        'Admin order omitted pickup notice');
    // Issue #43: the real stored snapshot renders a destructive-regeneration
    // confirmation on the form itself (covers button and Enter-key submit).
    // This checks server markup, not JavaScript dialog handling; Chromium
    // independently tests cancel/confirm in the disposable admin order page.
    $storedAdmin = $module->hookDisplayAdminOrder(['id_order' => $idOrder]);
    testAssert(str_contains($storedAdmin, 'Regenerate original pickup snapshot')
        && str_contains($storedAdmin, 'onsubmit="return confirm(')
        && str_contains($storedAdmin, 'overwrite the saved historical pickup snapshot')
        && str_contains($storedAdmin, 'grtrans_repair_order')
        && str_contains($storedAdmin, 'name="token"'),
        'Existing historical order snapshot must warn and confirm before regeneration');
    // All three order and checkout surfaces must use the same human-readable
    // wording, including the demo warning; no separate admin-only copy.
    Context::getContext()->cart = $cart;
    foreach ([
        'checkout' => $module->hookDisplayCarrierExtraContent([]),
        'customer_order' => $module->hookDisplayOrderDetail(['order' => $order]),
        'confirmation' => $module->hookDisplayOrderConfirmation(['order' => $order]),
        'admin_order' => $module->hookDisplayAdminOrder(['id_order' => $idOrder]),
    ] as $surface => $html) {
        testAssert(str_contains($html, 'STATION PICKUP ONLY — NO HOME DELIVERY')
            && str_contains($html, 'DEMO ESTIMATE — NOT A LIVE SHIPPING OFFER')
            && str_contains($html, 'Collection point: ')
            && str_contains($html, 'Naxos Port'),
            'Wording diverged across shared pickup renderer: ' . $surface);
    }
    // Verify repeat hooks are idempotent rather than attempting a second
    // primary-key INSERT, and reconstruct a missing record from the pinned
    // cart quote, never from a newly edited carrier route.
    $module->hookActionValidateOrder(['order' => $order, 'cart' => $cart]);
    testAssert(GrTransportOrderSnapshot::status($idOrder) === GrTransportOrderSnapshot::STORED,
        'Second validation must retain the original snapshot');
    $db = Db::getInstance();
    $routeId = 0;
    foreach ($db->executeS('SELECT id_route, station_name FROM `' . _DB_PREFIX_ . 'grtrans_route`') ?: [] as $routeRow) {
        if ((string) $routeRow['station_name'] === 'Naxos Port') {
            $routeId = (int) $routeRow['id_route'];
            break;
        }
    }
    testAssert($routeId > 0, 'Could not locate demo Naxos route for repair exercise');
    $originalRoute = GrTransportRouteRepository::find($routeId);
    testAssert(is_array($originalRoute), 'Cannot load original route');
    GrTransportRouteRepository::save(array_replace($originalRoute, ['price' => 99]), 0, $routeId);
    try {
        testAssert($db->delete('grtrans_order', 'id_order = ' . $idOrder),
            'Cannot simulate missing order snapshot');
        testAssert(str_contains($module->hookDisplayOrderDetail(['order' => $order]), 'temporarily unavailable'),
            'Missing pickup snapshot must be visible to customer');
        $missingAdmin = $module->hookDisplayAdminOrder(['id_order' => $idOrder]);
        testAssert(str_contains($missingAdmin, 'Repair missing pickup snapshot')
            && str_contains($missingAdmin, 'grtrans_repair_order')
            && !str_contains($missingAdmin, 'onsubmit="return confirm('),
            'Missing snapshot should offer one-click creation without destructive overwrite confirmation');
        $repaired = GrTransportOrderSnapshot::repair($idOrder);
        testAssert((float) $repaired['price'] === 29.0 && $repaired['station_name'] === 'Naxos Port',
            'Repair must restore original checkout pickup and price, not later tariff');
        $repairedAgain = GrTransportOrderSnapshot::repair($idOrder);
        testAssert((float) $repairedAgain['price'] === 29.0,
            'Repeat repair should be idempotent using the original cart quote');
        testAssert(str_contains($module->hookDisplayOrderConfirmation(['order' => $order]), 'Naxos Port'),
            'Customer-facing order confirmation must recover after repair');
        // Unrecoverable state must be explicit, not silently recalculated
        // from the new tariff or falsely reported as repaired.
        testAssert($db->delete('grtrans_cart_quote', 'id_cart = ' . (int) $cart->id),
            'Cannot simulate lost original cart quote');
        testAssert($db->delete('grtrans_order', 'id_order = ' . $idOrder),
            'Cannot simulate a second missing snapshot');
        $failedClosed = false;
        try {
            GrTransportOrderSnapshot::repair($idOrder);
        } catch (RuntimeException $e) {
            $failedClosed = str_contains($e->getMessage(), 'No historical cart quote');
        }
        testAssert($failedClosed, 'Repair without authoritative original quote must fail closed');
    } finally {
        GrTransportRouteRepository::save($originalRoute, 0, $routeId);
    }
    echo 'Real pending-payment PrestaShop order created: ferry freight EUR 29.00 and Naxos station persisted' . PHP_EOL;
} finally {
    Configuration::updateValue('PS_MAIL_METHOD', $oldMailMode);
}


// #39: real PrestaShop adjusted orders. Reuse the existing fixture, including
// its physical product, customer, address, carrier and cheque-payment module.
// Coverage: a single address/package; no charged card or production gateway.
$placeAdjusted = static function (Cart $c, string $label) use ($payment, $pendingState): Order {
    $amount = (float) $c->getOrderTotal(true, Cart::BOTH);
    testAssert($payment->validateOrder((int) $c->id, $pendingState, $amount,
        'CI ' . $label, null, [], (int) $c->id_currency, false, (string) $c->secure_key),
        'PrestaShop failed order: ' . $label);
    $placed = new Order((int) $payment->currentOrder);
    testAssert(Validate::isLoadedObject($placed)
        && GrTransportOrderSnapshot::find((int) $placed->id) !== null,
        'Missing order or pickup after ' . $label);
    return $placed;
};
$previousHandling = (float) Configuration::get('PS_SHIPPING_HANDLING');
$carrier = new Carrier($idCarrier);
$previousCarrierHandling = (int) $carrier->shipping_handling;
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_DISABLE);
try {
    $voucherCart = makeCheckoutCart($customer, $address, $product, $idCarrier, $idLang, $idGr, $context);
    testAssert(abs((float) $voucherCart->getPackageShippingCost($idCarrier, false, $context->country) - 29) < 0.001,
        'Voucher cart should begin with pinned EUR 29 freight');
    $rule = new CartRule();
    $rule->name = [$idLang => 'CI free shipping'];
    $rule->code = 'CI_FREE_SHIPPING_39';
    $rule->id_customer = (int) $customer->id;
    $rule->date_from = date('Y-m-d H:i:s', time() - 3600);
    $rule->date_to = date('Y-m-d H:i:s', time() + 86400);
    $rule->quantity = 10;
    $rule->quantity_per_user = 10;
    $rule->priority = 1;
    $rule->minimum_amount = 0;
    $rule->minimum_amount_tax = 1;
    $rule->minimum_amount_shipping = 0;
    $rule->minimum_amount_currency = (int) $voucherCart->id_currency;
    $rule->free_shipping = 1;
    $rule->reduction_percent = 0;
    $rule->reduction_amount = 0;
    $rule->active = 1;
    testAssert($rule->add(), 'Cannot create core free-shipping cart rule');
    testAssert($voucherCart->addCartRule((int) $rule->id), 'Cannot apply core free-shipping cart rule');
    $ruleContextRows = $voucherCart->getCartRules(CartRule::FILTER_ACTION_ALL, false);
    echo 'CI voucher cart diagnostics: ids='
        . json_encode(array_map(static function ($r) {
            return ['id' => $r['id_cart_rule'] ?? null, 'free' => $r['free_shipping'] ?? null];
        }, $ruleContextRows ?: []))
        . ', shipping=' . $voucherCart->getOrderTotal(false, Cart::ONLY_SHIPPING)
        . ', discounts=' . $voucherCart->getOrderTotal(false, Cart::ONLY_DISCOUNTS)
        . ', validity=' . var_export($rule->checkValidity($context, true, false), true) . PHP_EOL;
    $voucherOrder = $placeAdjusted($voucherCart, 'free voucher');
    echo 'CI voucher order diagnostics: order_rules='
        . json_encode($voucherOrder->getCartRules() ?: [])
        . ', shipping=' . $voucherOrder->total_shipping_tax_excl
        . ', discounts=' . $voucherOrder->total_discounts_tax_excl . PHP_EOL;
    testAssert(GrTransportOrderCharge::hasAppliedFreeShipping((int) $voucherOrder->id),
        'Voucher was not actually applied to PrestaShop order');
    $voucherCharged = (float) $voucherOrder->total_shipping_tax_excl;
    testAssert(abs($voucherCharged) <= 0.02
        || (float) $voucherOrder->total_discounts_tax_excl >= 28.98,
        'PrestaShop did not actually waive transport shipping');
    echo 'PASS voucher order: shipping ' . $voucherCharged
        . ', discount ' . $voucherOrder->total_discounts_tax_excl . PHP_EOL;

    testAssert(Configuration::updateValue('PS_SHIPPING_HANDLING', 6.25), 'Cannot enable handling fee');
    $carrier->shipping_handling = 1;
    testAssert($carrier->update(), 'Cannot enable handling on test carrier');
    Cache::clean('getPackageShippingCost_*');
    $feeCart = makeCheckoutCart($customer, $address, $product, $idCarrier, $idLang, $idGr, $context);
    testAssert(abs((float) $feeCart->getPackageShippingCost($idCarrier, false, $context->country) - 35.25) < 0.001,
        'Expected EUR 29 route plus EUR 6.25 handling once');
    $feePin = json_decode((string) Db::getInstance()->getValue('SELECT quote_data FROM `'
        . _DB_PREFIX_ . 'grtrans_cart_quote` WHERE id_cart = ' . (int) $feeCart->id), true);
    testAssert(is_array($feePin) && abs((float) ($feePin['handling_fee'] ?? 0) - 6.25) < 0.001,
        'Handling fee must be pinned with route quote');
    $feeOrder = $placeAdjusted($feeCart, 'handling');
    testAssert(abs((float) $feeOrder->total_shipping_tax_excl - 35.25) < 0.001,
        'Actual PrestaShop order did not include handling');
    echo 'PASS handling order: shipping ' . $feeOrder->total_shipping_tax_excl . PHP_EOL;

    // Original pinned fee must remain authoritative when the merchant
    // later changes configuration before repairing an order snapshot.
    $carrier->shipping_handling = $previousCarrierHandling;
    testAssert($carrier->update(), 'Cannot restore carrier handling setting');
    Configuration::updateValue('PS_SHIPPING_HANDLING', $previousHandling);
    testAssert(Db::getInstance()->delete('grtrans_order', 'id_order = ' . (int) $feeOrder->id),
        'Cannot simulate absent pickup snapshot');
    $repaired = GrTransportOrderSnapshot::repair((int) $feeOrder->id);
    testAssert(abs((float) $repaired['price'] - 29) < 0.001,
        'Historical repair must preserve pinned base rate');
    echo 'PASS historical repair after handling settings changed' . PHP_EOL;

    // #47 — Native-order acceptance over an expired persisted cart pin.
    // Advance only the database timestamp (no sleeping/mock clock). Reuse
    // the real physical cart, payment module and order validator fixture.
    $ttlRoute = GrTransportRouteRepository::find((int) $routeId);
    testAssert(is_array($ttlRoute) && (float) $ttlRoute['price'] === 29.0,
        'Cannot find authoritative Naxos route for expiry order scenario');
    try {
        $ttlCart = makeCheckoutCart($customer, $address, $product,
            $idCarrier, $idLang, $idGr, $context);
        testAssert(abs((float)$ttlCart->getPackageShippingCost($idCarrier, false, $context->country) - 29.0) < 0.001,
            'Initial checkout must pin EUR 29');
        $ttlId = (int) $ttlCart->id;
        $ttlRow = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_
            . 'grtrans_cart_quote WHERE id_cart = ' . $ttlId);
        $oldQuote = json_decode((string) ($ttlRow['quote_data'] ?? ''), true);
        testAssert(is_array($oldQuote) && (float) $oldQuote['price'] === 29.0,
            'Initial order cart must pin original EUR 29');
        GrTransportRouteRepository::save(array_replace($ttlRoute, ['price'=>39.0]),
            0, (int) $ttlRoute['id_route']);
        testAssert(abs((float)(new Grtransportshipping())->getOrderShippingCostExternal($ttlCart) - 29.0) < 0.001,
            'Unexpired pin must continue to hold original EUR 29');
        $staleAt = gmdate('Y-m-d H:i:s', time() - GrTransportCartQuote::TTL_SECONDS - 60);
        testAssert(Db::getInstance()->update('grtrans_cart_quote',
            ['created_at' => $staleAt], 'id_cart = ' . $ttlId),
            'Could not expire initial order cart quote');
        testAssert(abs((float)(new Grtransportshipping())->getOrderShippingCostExternal($ttlCart) - 39.0) < 0.001,
            'Expired pin must reflect current EUR 39 route, not historical EUR 29');
        $newRow = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_
            . 'grtrans_cart_quote WHERE id_cart = ' . $ttlId);
        $newQuote = json_decode((string) ($newRow['quote_data'] ?? ''), true);
        testAssert(is_array($newQuote) && (float) $newQuote['price'] === 39.0
            && ($newRow['quote_status'] ?? '') === GrTransportCartQuote::PINNED
            && ($newRow['created_at'] ?? '') > $staleAt,
            'Expired quote must be re-pinned with current fare and fresh timestamp');
        // Native PaymentModule and Cart keep request-local shipping totals.
        // A 30-minute-later checkout is a NEW PHP request; validate the
        // just-refreshed EUR 39 pin in a clean subprocess, using this SAME
        // persisted cart, payment module and real PrestaShop order lifecycle.
        $childOutput = [];
        $childExit = 1;
        exec('php /tmp/prestashop-ttl-order-validation.php ' . $ttlId . ' 2>&1',
            $childOutput, $childExit);
        $childResult = implode(PHP_EOL, $childOutput);
        echo $childResult . PHP_EOL;
        testAssert($childExit === 0
            && str_contains($childResult, 'PASS #47 fresh-process native order'),
            'Real post-expiry order validation in independent request failed: ' . $childResult);
    } finally {
        GrTransportRouteRepository::save($ttlRoute, 0, (int) $ttlRoute['id_route']);
        Cache::clean('getPackageShippingCost_*');
    }


    // CI-only invalid order totals: the actual validation hook must reject
    // unrelated differences and record a clear PrestaShop error log.
    $moduleGuard = Module::getInstanceByName('grtransportshipping');
    $chargedBefore = (float) $feeOrder->total_shipping_tax_excl;
    try {
        foreach ([40.00, 0.00] as $incorrectAmount) {
            testAssert(Db::getInstance()->update('orders',
                ['total_shipping_tax_excl' => $incorrectAmount],
                'id_order = ' . (int) $feeOrder->id), 'Could not set invalid CI shipping charge');
            $rejected = false;
            try {
                $moduleGuard->hookActionValidateOrder([
                    'order' => new Order((int) $feeOrder->id), 'cart' => $feeCart,
                ]);
            } catch (RuntimeException $e) {
                $rejected = str_contains($e->getMessage(), 'shipping charge divergence');
            }
            testAssert($rejected, 'Unexplained charged amount was accepted: ' . $incorrectAmount);
        }
        $logged = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `'
            . _DB_PREFIX_ . 'log` WHERE object_id = ' . (int) $feeOrder->id
            . " AND message LIKE '%shipping charge divergence%'");
        testAssert($logged >= 2, 'Invalid charges must leave a clear PrestaShop log entry');
    } finally {
        Db::getInstance()->update('orders',
            ['total_shipping_tax_excl' => $chargedBefore],
            'id_order = ' . (int) $feeOrder->id);
    }
    echo 'PASS genuine shipping charge divergence blocked and logged' . PHP_EOL;

} finally {
    $carrier = new Carrier($idCarrier);
    $carrier->shipping_handling = $previousCarrierHandling;
    $carrier->update();
    Configuration::updateValue('PS_SHIPPING_HANDLING', $previousHandling);
    Configuration::updateValue('PS_MAIL_METHOD', $oldMailMode);
}
