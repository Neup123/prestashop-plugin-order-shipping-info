<?php
/**
 * Uses PrestaShop's own Cart shipping calculator with a physical product.
 * Only run in the disposable CI shop.
 */
require_once '/var/www/html/config/config.inc.php';
// Checkout's native Cart calculator resolves services through the Symfony kernel.
global $kernel;
$kernel = new AdminKernel('prod', false);
$kernel->boot();

function verify($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idGr = (int) Country::getByIso('GR');
$idCarrier = (int) Configuration::get('GRTRANS_CARRIER');
verify($idGr && $idCarrier, 'Missing country or transport carrier');
$gr = new Country($idGr);

$product = new Product();
$product->name = [$idLang => 'CI physical parcel'];
$product->link_rewrite = [$idLang => 'ci-physical-parcel'];
$product->description_short = [$idLang => 'Temporary test product'];
$product->price = 10;
$product->active = 1;
$product->available_for_order = 1;
$product->visibility = 'both';
$product->id_tax_rules_group = 0;
$product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
verify($product->add(), 'Unable to add physical product');
StockAvailable::setQuantity((int) $product->id, 0, 100);

$module = Module::getInstanceByName('grtransportshipping');
verify($module instanceof CarrierModule, 'Transport module not available');
$cases = [
    ['26221', 'Πάτρα', 12.50, 'Patras KTEL station', 'bus'],
    ['84300', 'Νάξος', 29.00, 'Naxos Port', 'ferry'],
    ['71601', 'Ηράκλειο', 45.00, 'Heraklion Airport', 'flight'],
    ['85100', 'Ρόδος', 55.00, 'Rhodes Airport', 'flight'],
];
foreach ($cases as [$postal, $city, $price, $station, $mode]) {
    $address = new Address();
    $address->id_customer = 0;
    $address->alias = 'CI ' . $mode . ' station';
    $address->firstname = 'Test';
    $address->lastname = 'Customer';
    $address->address1 = 'Test street 1';
    $address->postcode = $postal;
    $address->city = $city;
    $address->id_country = $idGr;
    verify($address->add(), 'Cannot create Greek delivery address for ' . $city);
    $cart = new Cart();
    $cart->id_customer = 0;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_lang = $idLang;
    $cart->id_carrier = $idCarrier;
    $cart->id_shop = (int) Context::getContext()->shop->id;
    try {
        verify($cart->add(), 'Cannot create test cart for ' . $city);
        verify($cart->updateQty(1, (int) $product->id), 'Cannot add parcel to cart for ' . $city);
        Context::getContext()->cart = $cart;
        Context::getContext()->country = $gr;
        Context::getContext()->currency = new Currency((int) $cart->id_currency);
        $shipping = $cart->getPackageShippingCost($idCarrier, false, $gr);
        verify(is_numeric($shipping) && abs((float) $shipping - $price) < 0.001,
            'Cart shipping calculator returned ' . var_export($shipping, true) . ' instead of ' . $price . ' for ' . $city);
        $options = $cart->getDeliveryOptionList($gr, true);
        verify(isset($options[(int) $address->id]), 'No delivery options for Greek address ' . $city);
        $matches = false;
        foreach ($options[(int) $address->id] as $entry) {
            foreach ($entry['carrier_list'] ?? [] as $item) {
                if ((int) ($item['instance']->id ?? 0) === $idCarrier) {
                    $matches = true;
                }
            }
        }
        verify($matches, 'Transport carrier not displayed in checkout options for ' . $city);
        $notice = $module->hookDisplayCarrierExtraContent([]);
        verify(str_contains($notice, $station), 'Checkout notice does not name ' . $station);
        verify(str_contains($notice, 'STATION PICKUP ONLY'), 'Pickup-only notice missing for ' . $city);
        verify(str_contains($notice, 'DEMO ESTIMATE'), 'Unverified demo warning missing for ' . $city);
    } finally {
        if ((int) $cart->id > 0) {
            $cart->delete();
        }
        $address->delete();
    }
}
echo "PrestaShop native cart, options, and pickup notices passed for bus, ferry and flights\n";
$product->delete();
