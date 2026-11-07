<?php
require_once '/var/www/html/config/config.inc.php';
$password = getenv('TEST_CUSTOMER_PASSWORD');
if (!is_string($password) || strlen($password) < 12) {
    throw new RuntimeException('Missing disposable test customer password');
}
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$customer = new Customer();
$customer->firstname = 'CI';
$customer->lastname = 'Buyer';
$customer->email = 'ci-buyer@example.invalid';
$customer->passwd = Tools::hash($password);
$customer->active = 1;
$customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
if (!$customer->add()) {
    throw new RuntimeException('Could not create disposable customer');
}
$customer->addGroups([(int) $customer->id_default_group]);
$address = new Address();
$address->id_customer = (int) $customer->id;
$address->alias = 'Naxos station pickup';
$address->firstname = 'CI';
$address->lastname = 'Buyer';
$address->address1 = 'Test street 1';
$address->postcode = '84300';
$address->city = 'Νάξος';
$address->id_country = (int) Country::getByIso('GR');
if (!$address->add()) {
    throw new RuntimeException('Could not create disposable Greek address');
}
$product = new Product();
$product->name = [$idLang => 'CI physical parcel'];
$product->link_rewrite = [$idLang => 'ci-physical-parcel'];
$product->description_short = [$idLang => 'Disposable demonstration food parcel'];
$product->price = 10;
$product->active = 1;
$product->available_for_order = 1;
$product->visibility = 'both';
$product->id_tax_rules_group = 0;
$product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
$product->id_shop_default = (int) Context::getContext()->shop->id;
if (!$product->add()) {
    throw new RuntimeException('Could not create disposable parcel product');
}
if (!$product->updateCategories([(int) $product->id_category_default])) {
    throw new RuntimeException('Could not associate disposable parcel with visible category');
}
StockAvailable::setQuantity((int) $product->id, 0, 100);
if (!$product->checkAccess((int) $customer->id)) {
    throw new RuntimeException('Disposable customer cannot view parcel product/category');
}
$context = Context::getContext();
echo json_encode([
    'product_url' => $context->link->getProductLink($product),
    'login_url' => 'http://localhost:8080/index.php?controller=authentication',
    'checkout_url' => 'http://localhost:8080/index.php?controller=order',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
