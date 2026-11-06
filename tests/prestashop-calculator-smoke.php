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

$address = new Address();
$address->id_customer = 0;
$address->alias = 'CI ferry station';
$address->firstname = 'Test';
$address->lastname = 'Customer';
$address->address1 = 'Test street 1';
$address->postcode = '84300';
$address->city = 'Νάξος';
$address->id_country = $idGr;
verify($address->add(), 'Cannot create Greek delivery address');

$cart = new Cart();
$cart->id_customer = 0;
$cart->id_address_delivery = (int) $address->id;
$cart->id_address_invoice = (int) $address->id;
$cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$cart->id_lang = $idLang;
$cart->id_carrier = $idCarrier;
$cart->id_shop = (int) Context::getContext()->shop->id;
verify($cart->add(), 'Cannot create test cart');
verify($cart->updateQty(1, (int) $product->id), 'Cannot add physical parcel to cart');
Context::getContext()->cart = $cart;
Context::getContext()->country = $gr;
Context::getContext()->currency = new Currency((int) $cart->id_currency);

$shipping = $cart->getPackageShippingCost($idCarrier, false, $gr);
verify(is_numeric($shipping) && abs((float) $shipping - 29.0) < 0.001, 'Cart shipping calculator returned ' . var_export($shipping, true));

$options = $cart->getDeliveryOptionList($gr, true);
verify(isset($options[(int)$address->id]), 'No delivery options for Greek address');
$matches = false;
foreach ($options[(int)$address->id] as $entry) {
    foreach ($entry['carrier_list'] ?? [] as $item) {
        if ((int) ($item['instance']->id ?? 0) === $idCarrier) {
            $matches = true;
        }
    }
}
verify($matches, 'Transport carrier not displayed in checkout delivery options');
echo "PrestaShop shipping calculator and checkout delivery options passed\n";
$cart->delete();
$address->delete();
$product->delete();
