<?php
require_once __DIR__ . '/bootstrap.php';
$_MODULE = [];
require __DIR__ . '/../grtransportshipping/translations/el.php';

$prefix = '<{grtransportshipping}prestashop>grtransportshipping_';
foreach ([
    'STATION PICKUP ONLY — NO HOME DELIVERY' => 'ΠΑΡΑΛΑΒΗ ΜΟΝΟ ΑΠΟ ΣΤΑΘΜΟ',
    'DEMO ESTIMATE — NOT A LIVE SHIPPING OFFER' => 'ΕΝΔΕΙΚΤΙΚΗ ΕΚΤΙΜΗΣΗ',
    'No priced transport route matches this destination.' => 'Δεν υπάρχει διαδρομή',
    'Greek public transport routes' => 'Διαδρομές μεταφοράς στην Ελλάδα',
    'Seeded demo routes start Available and can immediately quote if this carrier is enabled.' => 'Οι ενδεικτικές διαδρομές',
] as $english => $expected) {
    $key = $prefix . md5($english);
    testAssert(isset($_MODULE[$key]) && str_contains($_MODULE[$key], $expected),
        'Missing classic Greek module dictionary entry: ' . $english);
}
echo "Classic Greek dictionary source-hash contracts passed\n";
