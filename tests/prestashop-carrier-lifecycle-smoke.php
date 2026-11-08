<?php
/**
 * Real PrestaShop 9.2 carrier lifecycle smoke. Runs last: it deliberately
 * retires the test carrier to exercise operator recovery without live orders.
 */
require_once '/var/www/html/config/config.inc.php';
function carrierCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$module = Module::getInstanceByName('grtransportshipping');
carrierCheck($module instanceof CarrierModule, 'Carrier module not installed');
$initial = GrTransportCarrierLifecycle::activeId($module);
carrierCheck($initial > 0, 'Missing initial active carrier');
carrierCheck(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::READY,
    'Initial carrier not ready');
$rowsBefore = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
carrierCheck(count($rowsBefore) === 1, 'Expected exactly one active module-owned carrier before retry');

// Module::install normally fails on an already installed module; the module
// retry entrypoint must resume the partial state without parent::install().
carrierCheck((bool) $module->install(), 'Idempotent module install retry failed');
carrierCheck(GrTransportCarrierLifecycle::activeId($module) === $initial, 'Retry created a second carrier');
carrierCheck((bool) GrTransportCarrierLifecycle::ensure($module), 'Repeated carrier ensure failed');
$rowsAfter = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
carrierCheck(count($rowsAfter) === 1, 'Reinstall orphaned an earlier module carrier');

// A deliberately removed back-office carrier must deny quotes and require
// an explicit operator recovery, not silently register another at checkout.
$carrier = new Carrier($initial);
$carrier->deleted = true;
carrierCheck((bool) $carrier->save(), 'Could not simulate carrier deletion');
carrierCheck(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::DELETED,
    'Deleted carrier state was not recognized');
carrierCheck(str_contains($module->getContent(), 'Repair transport carrier'),
    'Admin configuration must offer an explicit carrier repair action');
carrierCheck(GrTransportCarrierLifecycle::activeId($module) === 0 && (int) $module->id_carrier === 0,
    'Deleted carrier still selected for customer quotes');
carrierCheck((bool) GrTransportCarrierLifecycle::ensure($module), 'Admin repair could not replace deleted carrier');
$replacement = GrTransportCarrierLifecycle::activeId($module);
carrierCheck($replacement > $initial && $replacement > 0, 'Repair did not replace deleted carrier');
carrierCheck((bool) GrTransportCarrierLifecycle::ensure($module), 'Repeat repair not idempotent');
carrierCheck(GrTransportCarrierLifecycle::activeId($module) === $replacement,
    'Repeated repair produced another carrier');
$recovered = new Carrier($replacement);
carrierCheck($recovered->external_module_name === 'grtransportshipping' && !$recovered->deleted,
    'Replacement carrier is not owned/ready');

// The platform-required cached property is always derived from canonical
// config at quote time; it must never become an independent identity.
$module->id_carrier = $initial;
carrierCheck(GrTransportCarrierLifecycle::activeId($module) === $replacement
    && (int) $module->id_carrier === $replacement, 'Stale module carrier property did not recover');
Configuration::deleteByName(GrTransportCarrierLifecycle::CONFIG);
carrierCheck(GrTransportCarrierLifecycle::inspect()['state'] === GrTransportCarrierLifecycle::ORPHANED,
    'Unconfigured but existing module carrier not discovered');
carrierCheck((bool) GrTransportCarrierLifecycle::ensure($module)
    && GrTransportCarrierLifecycle::activeId($module) === $replacement,
    'Partial install recovery should re-adopt the existing carrier');

$activeRows = Db::getInstance()->executeS('SELECT id_carrier FROM `' . _DB_PREFIX_
    . 'carrier` WHERE external_module_name = \'grtransportshipping\' AND deleted = 0') ?: [];
carrierCheck(count($activeRows) === 1, 'Exactly one non-deleted module carrier must remain');
echo "Carrier install retry, back-office deletion recovery and canonical identity smoke passed\n";
