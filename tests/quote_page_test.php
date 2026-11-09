<?php
require_once __DIR__ . '/bootstrap.php';
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'ps_');
define('_MODULE_DIR_', '/modules/');
// Local contracts exercise the real module entry points and front controller.
// These platform doubles do not pretend to be a running PrestaShop installation.
class CarrierModule
{
    public $context;
    public $name = 'grtransportshipping';
    public $id_carrier;
    public function l($text, $source = null) { return $text; }
}
class Configuration
{
    public static function get($key) { return $key === 'GRTRANS_CARRIER' ? 1 : 2.5; }
}
class Carrier
{
    public $id = 1;
    public $name = 'Test transport carrier';
    public $active = true;
    public $deleted = false;
    public $external_module_name = 'grtransportshipping';
    public $shipping_handling = true;
    public function __construct($id) { $this->id = $id; }
}
class Validate { public static function isLoadedObject($object) { return (bool) $object->id; } }
class Currency { public static function getCurrencyInstance($id) { return (object) ['id' => $id]; } }
class Tools
{
    public static $request = [];
    public static function convertPrice($amount, $currency) { return $amount; }
    public static function getValue($key, $default = false) { return self::$request[$key] ?? $default; }
}
class Address
{
    public static $postal = '84300';
    public static $locality = 'Νάξος';
    public $id;
    public $id_country = 1;
    public $postcode;
    public $city;
    public function __construct($id) { $this->id = $id; $this->postcode = self::$postal; $this->city = self::$locality; }
}
class Country { public $iso_code = 'GR'; public function __construct($id) {} }
class Db
{
    public static $routes;
    public static function getInstance() { return new self(); }
    public function executeS($sql) { return self::$routes; }
}
class TestSmarty
{
    public $vars;
    public function assign($vars) { $this->vars = $vars; }
}
class TestLink
{
    public function getModuleLink($module, $controller) { return 'https://shop.test/index.php?fc=module&module=' . $module . '&controller=' . $controller; }
}
class ModuleFrontController
{
    public $module;
    public $context;
    public $template;
    public function initContent() {}
    public function setMedia() {}
    public function registerStylesheet(...$args) {}
    public function registerJavascript(...$args) {}
    public function setTemplate($template) { $this->template = $template; }
}
require_once __DIR__ . '/../grtransportshipping/grtransportshipping.php';
require_once __DIR__ . '/../grtransportshipping/controllers/front/quote.php';
Db::$routes = require __DIR__ . '/../grtransportshipping/demo/routes.php';
foreach (Db::$routes as $id => &$route) { $route['id_route'] = $id + 1; }
unset($route);
$module = (new ReflectionClass(Grtransportshipping::class))->newInstanceWithoutConstructor();
$context = (object) [
    'currency' => (object) ['id' => 1, 'iso_code' => 'EUR'],
    'smarty' => new TestSmarty(), 'link' => new TestLink(),
    'currentLocale' => new class {
        public function formatPrice($amount, $currency) { return number_format($amount, 2) . ' ' . $currency; }
    },
];
$module->context = $context;
$controller = new GrtransportshippingQuoteModuleFrontController();
$controller->module = $module;
$controller->context = $context;
$cart = (object) ['id' => 0, 'id_address_delivery' => 1, 'id_currency' => 1];
// Actual page and checkout module methods share routes, prices and handling.
foreach ([['84300', 'Νάξος'], ['26221', 'Πάτρα'], ['99999', 'Unknown']] as [$postal, $city]) {
    Address::$postal = $postal;
    Address::$locality = $city;
    $module->clearQuoteCache();
    $checkoutPrice = $module->getOrderShippingCostExternal($cart);
    Tools::$request = ['destination' => $postal];
    $controller->initContent();
    $vars = $context->smarty->vars;
    if ($checkoutPrice === false) {
        testAssert($vars['quote_route'] === null, 'No-route page/checkout parity');
        testAssert($vars['quote_message'] === 'No priced transport route matches this destination.', 'Exact no-route copy');
    } else {
        testAssert($vars['quote_price'] === number_format($checkoutPrice, 2) . ' EUR', 'Rendered amount matches checkout including handling');
        testAssert($vars['quote_carrier'] === 'Test transport carrier', 'Live carrier name');
        testAssert(str_contains($vars['quote_notice'], 'STATION PICKUP ONLY'), 'Same pickup warning');
        testAssert(str_contains($vars['quote_notice'], 'DEMO ESTIMATE'), 'Same demo warning');
        testAssert(count(json_decode($vars['quote_map'], true)['stations']) === 2, 'Map has both terminals');
        testAssert(json_decode($vars['quote_map'], true)['leafletImagePath']
            === '/modules/grtransportshipping/views/vendor/leaflet/images/',
            'Marker icons use the bundled module path, not the quote controller path');
    }
}
Db::$routes = [array_replace(Db::$routes[3], ['postal_prefix' => '', 'city' => 'Χαϊδάρι', 'pickup_only' => 0, 'demo' => 0])];
Address::$postal = '';
Address::$locality = 'ΧΑΙΔΑΡΙ';
$module->clearQuoteCache();
$checkoutPrice = $module->getOrderShippingCostExternal($cart);
Tools::$request = ['destination' => 'Χαιδαρι'];
$controller->initContent();
testAssert($context->smarty->vars['quote_price'] === number_format($checkoutPrice, 2) . ' EUR', 'Priced non-pickup route parity');
testAssert(str_contains($context->smarty->vars['quote_notice'], 'Delivery arrangement must be confirmed.'), 'Delivery arrangement warning');
// HTML-bearing merchant data stays escaped in the reused renderer.
$notice = $module->customerQuoteNotice(array_replace(Db::$routes[0], ['station_name' => '<script>alert(1)</script>']));
testAssert(!str_contains($notice, '<script>') && str_contains($notice, '&lt;script&gt;'), 'Notice XSS escaping');
Tools::$request = ['destination' => ['invalid']];
$controller->initContent();
testAssert(!$context->smarty->vars['quote_submitted'], 'Reject array text input without PHP warning');
Tools::$request = ['pin' => '1', 'lat' => ['invalid'], 'lng' => '24'];
$controller->initContent();
testAssert($context->smarty->vars['quote_route'] === null, 'Reject invalid pin');
echo "Quote page/controller and actual checkout entry point contracts passed\n";
