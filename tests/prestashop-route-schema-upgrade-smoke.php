<?php
/**
 * Schema upgrade contract for disposable PrestaShop 9.2/MariaDB shop only.
 * Simulates the 0.4.x state (no notice CHECK or seed marker); it does not
 * alter the merchant's real database and does not validate every MySQL brand.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop();

require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-0.5.0.php';
$table = _DB_PREFIX_ . 'grtrans_route';
$name = GrTransportRouteFields::NOTICE_CHECK_NAME;
$db = Db::getInstance();
$countCheck = static function () use ($db, $table, $name): int {
    return (int) $db->getValue('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS'
        . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = \''
        . pSQL($table) . '\' AND CONSTRAINT_NAME = \''
        . pSQL($name) . '\'');
};
testAssert($countCheck() === 1, 'Clean installation missing the field-spec-derived notice CHECK');
testAssert($db->execute('ALTER TABLE `' . bqSQL($table) . '` DROP CONSTRAINT `'
    . bqSQL($name) . '`'), 'Could not simulate pre-0.5 route schema');
testAssert($countCheck() === 0, 'Pre-upgrade constraint simulation failed');
Configuration::deleteByName(GrTransportRouteFields::SEED_CONFIG);
$module = Module::getInstanceByName('grtransportshipping');
testAssert(upgrade_module_0_5_0($module), 'Cannot migrate 0.4.x schema to notice field spec');
testAssert($countCheck() === 1, 'Upgrade did not restore notice limit from field spec');
testAssert((int) Configuration::get(GrTransportRouteFields::SEED_CONFIG) === 1,
    'Upgrade did not mark historical seed state as terminal');
testAssert(upgrade_module_0_5_0($module), 'Repeated route schema migration must be idempotent');
// #44: Installed 0.5.0 shops do not call install() again. Verify that
// the 0.5.1 upgrade subscribes the pre-carrier checkout surface, including
// an idempotent retry, without changing routes or order records.
require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-0.5.1.php';
testAssert($module->isRegisteredInHook('displayBeforeCarrier'),
    'Fresh 0.5.1 install failed to subscribe the independent checkout hook');
testAssert($module->unregisterHook('displayBeforeCarrier'),
    'Could not simulate legacy install without checkout availability hook');
testAssert(!$module->isRegisteredInHook('displayBeforeCarrier'),
    'Legacy hook registration removal did not take effect');
testAssert(upgrade_module_0_5_1($module)
    && $module->isRegisteredInHook('displayBeforeCarrier'),
    '0.5.1 upgrade did not subscribe before-carrier checkout explanation hook');
testAssert(upgrade_module_0_5_1($module),
    'Repeated 0.5.1 hook upgrade must remain idempotent');
echo "0.5.1 independent checkout hook installation and upgrade registration passed\n";
// #48/#49: Compare clean installation against the versioned historical
// schema upgrade. Test that missing indexes are restored and retries are safe.
require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-0.5.2.php';
$countIndex = static function (string $tableName, string $index) use ($db): int {
    return (int) $db->getValue(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = '" . pSQL($tableName) . "'"
        . " AND INDEX_NAME = '" . pSQL($index) . "'"
    );
};
$indexes = [
    [$table, GrTransportRouteFields::LOOKUP_PREFIX_INDEX],
    [_DB_PREFIX_ . 'grtrans_cart_quote', GrTransportCartQuote::PRUNE_INDEX],
];
foreach ($indexes as [$targetTable, $index]) {
    testAssert($countIndex($targetTable, $index) > 0,
        'Clean install missing ' . $index);
    testAssert($db->execute('ALTER TABLE `' . bqSQL($targetTable)
        . '` DROP INDEX `' . bqSQL($index) . '`'),
        'Could not simulate pre-0.5.2 index ' . $index);
    testAssert($countIndex($targetTable, $index) === 0,
        'Pre-upgrade missing-index simulation failed');
}
testAssert(upgrade_module_0_5_2($module), 'Cannot upgrade indexes on 0.5.1 schema');
foreach ($indexes as [$targetTable, $index]) {
    testAssert($countIndex($targetTable, $index) > 0,
        'Upgrade did not restore ' . $index);
}
testAssert(upgrade_module_0_5_2($module),
    'Repeated 0.5.2 schema migration must be idempotent');
echo "0.5.2 existing-install route and quote indexes restored idempotently\n";
echo "0.5.0 route field schema migration and seed-marker initialization passed\n";
