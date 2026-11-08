<?php
/**
 * #47 second request/second PHP process only. The parent real-order smoke
 * creates the actual Cart, pins EUR 29, advances the SQL timestamp past
 * CartQuote::TTL_SECONDS, changes the real route to EUR 39 and verifies the
 * new EUR 39 pin. THIS fresh PHP process performs the normal core payment
 * validation. Never emulate an HTTP request by reusing old PHP static caches.
 *
 * Coverage: actual PrestaShop 9.2.1 pending-cheque order, one Greek address
 * and physical product; not real card gateways or multishipment.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop(true);

$cartId = (int) ($argv[1] ?? 0);
testAssert($cartId > 0, 'Missing parent expired-cart ID');
$cart = new Cart($cartId);
testAssert(Validate::isLoadedObject($cart), 'Cannot load parent expired Cart');
$carrierId = (int) Configuration::get('GRTRANS_CARRIER');
testAssert($carrierId > 0 && (int) $cart->id_carrier === $carrierId,
    'Expired order test cart lost original transport carrier selection');
$address = new Address((int) $cart->id_address_delivery);
testAssert(Validate::isLoadedObject($address), 'Expired order test cart lost delivery address');
$quote = GrTransportCartQuote::forOrder($cartId, (int) $address->id);
testAssert(is_array($quote) && (float) $quote['price'] === 39.0,
    'New checkout must read re-pinned EUR 39 historical quote');

$ctx = Context::getContext();
$ctx->cart = $cart;
$ctx->customer = new Customer((int) $cart->id_customer);
$ctx->country = new Country((int) $address->id_country);
$ctx->currency = new Currency((int) $cart->id_currency);
$ctx->language = new Language((int) $cart->id_lang);
$ctx->shop = new Shop((int) $cart->id_shop);
testAssert(Validate::isLoadedObject($ctx->customer), 'Expired cart owner cannot be loaded');

$transport = Module::getInstanceByName('grtransportshipping');
testAssert(abs((float) $transport->getOrderShippingCostExternal($cart) - 39.0) < 0.001,
    'Fresh checkout request must quote EUR 39');
Cache::clean('getPackageShippingCost_*');
$shipping = (float) $cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
testAssert(abs($shipping - 39.0) < 0.001,
    'Fresh native PrestaShop shipping calculation must use EUR 39, not stale EUR 29');
$payment = Module::getInstanceByName('ps_checkpayment');
testAssert($payment instanceof PaymentModule && $payment->active,
    'Disposable test payment module must remain enabled');
$amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
testAssert($payment->validateOrder(
    $cartId, (int) Configuration::get('PS_OS_CHEQUE'), $amount,
    'CI expired transport quote', null, [],
    (int) $cart->id_currency, false, (string) $cart->secure_key
), 'Actual PrestaShop payment validation failed after quote expiry');
$order = new Order((int) $payment->currentOrder);
$snapshot = GrTransportOrderSnapshot::find((int) $order->id);
testAssert(Validate::isLoadedObject($order)
    && (int) $order->id_carrier === $carrierId
    && abs((float) $order->total_shipping_tax_excl - 39.0) < 0.001
    && is_array($snapshot)
    && abs((float) $snapshot['price'] - 39.0) < 0.001,
    'Validated order must persist the current EUR 39 shipping and pickup snapshot');
$row = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_
    . 'grtrans_cart_quote WHERE id_cart = ' . $cartId);
testAssert(($row['quote_status'] ?? '') === GrTransportCartQuote::CONSUMED,
    'Successful post-expiry order must consume the refreshed pin');

// Terminal consumed quote remains the immutable order-repair source even
// when aged beyond the checkout TTL after order placement.
$expiredAgain = gmdate('Y-m-d H:i:s', time() - GrTransportCartQuote::TTL_SECONDS - 120);
testAssert(Db::getInstance()->update('grtrans_cart_quote',
    ['created_at'=>$expiredAgain], 'id_cart = ' . $cartId),
    'Could not age consumed quote for historical repair check');
$history = GrTransportCartQuote::forOrder($cartId, (int)$address->id);
testAssert(is_array($history) && (float)$history['price'] === 39.0,
    'Historical consumed order quote must survive TTL for repair');
echo 'PASS #47 fresh-process native order: expired EUR 29 re-quoted EUR 39, charged/snapshotted/consumed EUR 39, immutable history' . PHP_EOL;
