<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/RouteFields.php';

/**
 * Existing 0.4.x installations already have VARCHAR sizes matching the
 * field spec. Add only the notice length CHECK that clean installs now derive
 * from RouteFields::schemaColumns(). Never silently truncate merchant text.
 */
function upgrade_module_0_5_0($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'grtrans_route';
    $sql = 'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS'
        . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = \''
        . pSQL($table) . '\''
        . ' AND CONSTRAINT_NAME = \''
        . pSQL(GrTransportRouteFields::NOTICE_CHECK_NAME) . '\'';
    if ((int) $db->getValue($sql) > 0) {
        // Every 0.4.x install already passed its initial seed stage; an
        // empty table may be an intentional merchant action, not a fresh shop.
        return Configuration::updateValue(GrTransportRouteFields::SEED_CONFIG, 1);
    }
    $tooLong = (int) $db->getValue('SELECT COUNT(*) FROM `'
        . bqSQL($table) . '` WHERE CHAR_LENGTH(`notice`) > '
        . GrTransportRouteFields::limit('notice'));
    if ($tooLong > 0) {
        PrestaShopLogger::addLog('Transport route migration: ' . $tooLong
            . ' notices exceed the field-spec limit; edit them before upgrading', 3);
        return false;
    }
    return (bool) $db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD '
        . GrTransportRouteFields::noticeConstraintSql())
        && Configuration::updateValue(GrTransportRouteFields::SEED_CONFIG, 1);
}
