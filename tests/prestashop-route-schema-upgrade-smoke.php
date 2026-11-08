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
echo "0.5.0 route field schema migration and seed-marker initialization passed\n";
