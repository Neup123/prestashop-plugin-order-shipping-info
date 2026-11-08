<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/RouteFields.php';
require_once __DIR__ . '/../classes/CartQuote.php';

/**
 * Idempotently bring upgraded shops into parity with clean installations.
 * Index-only changes; no merchant route, quote, or order state is touched.
 */
function upgrade_module_0_5_2($module)
{
    $db = Db::getInstance();
    foreach ([
        [_DB_PREFIX_ . 'grtrans_route',
            GrTransportRouteFields::LOOKUP_PREFIX_INDEX,
            GrTransportRouteFields::lookupIndexSql()],
        [_DB_PREFIX_ . 'grtrans_cart_quote',
            GrTransportCartQuote::PRUNE_INDEX,
            GrTransportCartQuote::pruneIndexSql()],
    ] as [$table, $index, $definition]) {
        $existing = $db->getValue(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . pSQL($table) . "'"
            . " AND INDEX_NAME = '" . pSQL($index) . "'"
        );
        if ($existing === false) {
            return false; // Do not treat a failed schema query as missing.
        }
        if ((int) $existing === 0
            && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD ' . $definition)) {
            return false;
        }
    }
    return true;
}
