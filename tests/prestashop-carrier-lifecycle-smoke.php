<?php
/**
 * Real PrestaShop 9.2 carrier lifecycle smoke. Runs last: it deliberately
 * retires the test carrier to exercise operator recovery without live orders.
 */
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop(false);

$module = Module::getInstanceByName('grtransportshipping');
testAssert($module instanceof CarrierModule, 'Carrier module not installed');
$initial = GrTransportCarrierLifecycle::activeId($module);
testAssert($initial > 0, 'Missing initial active carrier');
testAssert(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::READY,
    'Initial carrier not ready');
$rowsBefore = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
testAssert(count($rowsBefore) === 1, 'Expected exactly one active module-owned carrier before retry');

// Module::install normally fails on an already installed module; the module
// retry entrypoint must resume the partial state without parent::install().
testAssert((bool) $module->install(), 'Idempotent module install retry failed');
testAssert(GrTransportCarrierLifecycle::activeId($module) === $initial, 'Retry created a second carrier');
testAssert((bool) GrTransportCarrierLifecycle::ensure($module), 'Repeated carrier ensure failed');
$rowsAfter = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
testAssert(count($rowsAfter) === 1, 'Reinstall orphaned an earlier module carrier');

// A deliberately removed back-office carrier must deny quotes and require
// an explicit operator recovery, not silently register another at checkout.
$carrier = new Carrier($initial);
$carrier->deleted = true;
testAssert((bool) $carrier->save(), 'Could not simulate carrier deletion');
testAssert(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::DELETED,
    'Deleted carrier state was not recognized');
testAssert(str_contains($module->getContent(), 'Repair transport carrier'),
    'Admin configuration must offer an explicit carrier repair action');
testAssert(GrTransportCarrierLifecycle::activeId($module) === 0 && (int) $module->id_carrier === 0,
    'Deleted carrier still selected for customer quotes');
testAssert((bool) GrTransportCarrierLifecycle::ensure($module), 'Admin repair could not replace deleted carrier');
$replacement = GrTransportCarrierLifecycle::activeId($module);
testAssert($replacement > $initial && $replacement > 0, 'Repair did not replace deleted carrier');
testAssert((bool) GrTransportCarrierLifecycle::ensure($module), 'Repeat repair not idempotent');
testAssert(GrTransportCarrierLifecycle::activeId($module) === $replacement,
    'Repeated repair produced another carrier');
$recovered = new Carrier($replacement);
testAssert($recovered->external_module_name === 'grtransportshipping' && !$recovered->deleted,
    'Replacement carrier is not owned/ready');

// The platform-required cached property is always derived from canonical
// config at quote time; it must never become an independent identity.
$module->id_carrier = $initial;
testAssert(GrTransportCarrierLifecycle::activeId($module) === $replacement
    && (int) $module->id_carrier === $replacement, 'Stale module carrier property did not recover');
Configuration::deleteByName(GrTransportCarrierLifecycle::CONFIG);
testAssert(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::ORPHANED,
    'Unconfigured but existing module carrier not discovered');
testAssert((bool) GrTransportCarrierLifecycle::ensure($module)
    && GrTransportCarrierLifecycle::activeId($module) === $replacement,
    'Partial install recovery should re-adopt the existing carrier');

$activeRows = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
testAssert(count($activeRows) === 1, 'Exactly one non-deleted module carrier must remain');
// The initial demo seed is a terminal, explicit initialization state,
// not inferred from COUNT(*) after a merchant removes every route.
// This disposable test runs last; its deliberate route deletion is isolated.
testAssert((int) Configuration::get(GrTransportRouteFields::SEED_CONFIG) === 1,
    'Initial route seed must be marked complete');
testAssert((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'grtrans_route`') > 0,
    'Expected fixture routes before verifying terminal seed marker');
testAssert(Db::getInstance()->delete('grtrans_route', '1=1'), 'Could not simulate merchant deleting all routes');
GrTransportRouteRepository::seedOnce();
testAssert((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'grtrans_route`') === 0,
    'Deleted merchant routes must not be silently re-seeded');
testAssert($module->install(), 'Partial install retry after empty dataset failed');
testAssert((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'grtrans_route`') === 0,
    'Idempotent reinstall must respect initialized-but-empty merchant dataset');
echo "Carrier install retry, back-office deletion recovery and canonical identity smoke passed\n";
