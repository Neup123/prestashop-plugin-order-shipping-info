<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/OrderCharge.php';

$pin = ['price' => 29.00, 'handling_fee' => 6.25];
testAssert(GrTransportOrderCharge::classify(35.25, $pin, false)
    === GrTransportOrderCharge::NORMAL, 'Pinned handling fee accepted');
testAssert(GrTransportOrderCharge::classify(0.0, $pin, true)
    === GrTransportOrderCharge::FREE_SHIPPING, 'Applied free-shipping voucher accepted');
testAssert(GrTransportOrderCharge::classify(0.0, $pin, false) === null,
    'Zero charge without a persisted voucher must fail closed');
testAssert(GrTransportOrderCharge::classify(29.00, $pin, false) === null,
    'Missing expected handling fee is not authorized by mere configuration');
testAssert(GrTransportOrderCharge::classify(35.30, $pin, true) === null,
    'Voucher flag cannot excuse unrelated nonzero divergence');
testAssert(GrTransportOrderCharge::classify(38.00, $pin, false) === null,
    'Unexplained extra shipping cost must fail closed');
testAssert(GrTransportOrderCharge::classify(-1.00, $pin, true) === null,
    'Negative shipping cannot be legitimized by a voucher');
testAssert(GrTransportOrderCharge::classify(29.00, ['price' => 29.00], false)
    === GrTransportOrderCharge::NORMAL, 'Old pinned quote without fee remains supported');
echo "Order charge adjustment classifications passed\n";
