<?php
/**
 * #50: Verify real PrestaShop legacy module translation lookup with the
 * shipped Greek dictionary. Pure hash checks alone do not prove it loads.
 * Does not alter merchant settings or require a paid order.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop(false);
$module = Module::getInstanceByName('grtransportshipping');
testAssert($module instanceof Grtransportshipping, 'Cannot load module for real Greek translation smoke');
$messages = [
    'STATION PICKUP ONLY — NO HOME DELIVERY' => 'ΠΑΡΑΛΑΒΗ ΜΟΝΟ ΑΠΟ ΣΤΑΘΜΟ',
    'DEMO ESTIMATE — NOT A LIVE SHIPPING OFFER' => 'ΕΝΔΕΙΚΤΙΚΗ ΕΚΤΙΜΗΣΗ',
    'No priced transport route matches this destination.' => 'Δεν υπάρχει διαδρομή',
    'Greek public transport routes' => 'Διαδρομές μεταφοράς στην Ελλάδα',
    'Transport carrier ready' => 'Η μεταφορική υπηρεσία είναι έτοιμη',
];
foreach ($messages as $english => $expected) {
    $actual = $module->l($english, 'grtransportshipping', 'el-GR');
    testAssert(str_contains($actual, $expected),
        'Actual PrestaShop Greek translation did not load: ' . $english . ' returned ' . $actual);
}
$idGreek = (int) Language::getIdByIso('el');
if ($idGreek > 0) {
    $carrierId = (int) Configuration::get('GRTRANS_CARRIER');
    $carrier = new Carrier($carrierId);
    testAssert(Validate::isLoadedObject($carrier)
        && str_contains((string)$carrier->name, 'Παραλαβή από σταθμό')
        && isset($carrier->delay[$idGreek])
        && str_contains((string)$carrier->delay[$idGreek], 'Παραλαβή'),
        'Fresh carrier must show a bilingual global name and a Greek per-language delay');
}
echo 'PASS #50 real PrestaShop Greek module dictionary loaded; carrier language checks='
    . ($idGreek > 0 ? 'Greek installed' : 'Greek not installed in disposable shop') . PHP_EOL;
