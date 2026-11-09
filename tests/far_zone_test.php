<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/FarZoneConsent.php';

define('_COOKIE_KEY_', 'ci-test-secret-that-is-not-a-real-shop-cookie');
$cart=(object)['id'=>27,'id_address_delivery'=>81];
$quote=['source'=>'network','extended'=>1,'station_name'=>'Piraeus','price'=>11.5];
$cookie=new class {
    public $grtrans_far_zone_consent='';
    public $writes=0;
    public function write() {$this->writes++;}
};

testAssert(!GrTransportFarZoneConsent::confirmed($cookie,$cart,$quote),
    'Panel I: far-zone quote must start UNCONFIRMED');
$token=GrTransportFarZoneConsent::token($cart);
testAssert(GrTransportFarZoneConsent::validToken($cart,$token),
    'Correct cart/address signed token is valid');
testAssert(!GrTransportFarZoneConsent::validToken(
    (object)['id'=>27,'id_address_delivery'=>82],$token),
    'Token is invalidated by changed delivery address');
testAssert(!GrTransportFarZoneConsent::validToken($cart,'fake-token'),
    'A forged token can never confirm far-zone shipping');
GrTransportFarZoneConsent::set($cookie,$cart,$quote,true);
testAssert($cookie->writes===1 && GrTransportFarZoneConsent::confirmed($cookie,$cart,$quote),
    'Explicit confirmation persists with the exact pinned quote');
testAssert(!GrTransportFarZoneConsent::confirmed($cookie,$cart,['extended'=>1,
    'station_name'=>'Piraeus','price'=>12.5]),
    'Any price change invalidates existing far-zone confirmation');
testAssert(!GrTransportFarZoneConsent::confirmed(
    $cookie,(object)['id'=>27,'id_address_delivery'=>82],$quote),
    'New delivery address invalidates existing far-zone confirmation');
testAssert(!GrTransportFarZoneConsent::confirmed(
    $cookie,(object)['id'=>28,'id_address_delivery'=>81],$quote),
    'Different cart cannot reuse far-zone confirmation');
GrTransportFarZoneConsent::set($cookie,$cart,$quote,false);
testAssert(!GrTransportFarZoneConsent::confirmed($cookie,$cart,$quote)
    && $cookie->writes===2, 'Customer can revoke consent');
testAssert(GrTransportFarZoneConsent::confirmed($cookie,$cart,['extended'=>0]),
    'Normal-zone orders do not require far-zone consent');
try {
    GrTransportFarZoneConsent::set($cookie,$cart,['extended'=>0],true);
    throw new RuntimeException('Non-far-zone should not accept a confirmation');
} catch (InvalidArgumentException $e) {
    testAssert(str_contains($e->getMessage(),'not a far-zone'),
        'Only far-zone quotes may store consent');
}
$module=file_get_contents(__DIR__ . '/../grtransportshipping/grtransportshipping.php');
testAssert(str_contains($module,'hookActionValidateOrder') &&
    str_contains($module,'GrTransportFarZoneConsent::confirmed(') &&
    str_contains($module,'hookDisplayCarrierExtraContent'),
    'Order guard, selected carrier warning and active consent are all required');
$quoteTemplate=file_get_contents(__DIR__ . '/../grtransportshipping/views/templates/front/quote.tpl');
testAssert(str_contains($quoteTemplate,'$quote_far_required && !$quote_far_confirmed')
    && str_contains($quoteTemplate,'name="far_ack" value="1" required')
    && str_contains($quoteTemplate,'Proceed with far-zone transport quote'),
    'Public quote must warn, demand an explicit checkbox, and gate the price result');
$publicController=file_get_contents(__DIR__ . '/../grtransportshipping/controllers/front/quote.php');
testAssert(str_contains($publicController,"'REQUEST_METHOD'") &&
    str_contains($publicController,"=== 'POST'"),
    'GET query parameters must not bypass public far-zone confirmation');
echo "Panel I far-zone confirmation, signed order guard and customer warning passed\n";
