<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * In-place 0.3.x migration: enabled legacy entries stay available, inactive
 * entries become explicit suspensions. New entries start as drafts. Existing
 * demo flags remain unverified until merchant confirmation.
 */
function upgrade_module_0_4_0($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'grtrans_route';
    $columns = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '`') ?: [];
    $names = array_column($columns, 'Field');
    if (!in_array('route_status', $names, true)
        && !$db->execute('ALTER TABLE `' . bqSQL($table)
            . "` ADD COLUMN `route_status` VARCHAR(12) NOT NULL DEFAULT 'draft'")) {
        return false;
    }
    foreach ([
        'verified_at' => 'DATETIME NULL',
        'verified_by_employee' => 'INT NULL',
    ] as $name => $definition) {
        if (!in_array($name, $names, true)
            && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `' . $name . '` ' . $definition)) {
            return false;
        }
    }
    if (in_array('active', $names, true)) {
        // Only historical rows used the old boolean; convert exactly once.
        if (!$db->execute('UPDATE `' . bqSQL($table)
            . "` SET route_status = CASE WHEN active = 1 THEN 'available' ELSE 'suspended' END")
            || !$db->execute('ALTER TABLE `' . bqSQL($table) . '` DROP COLUMN `active`')) {
            return false;
        }
    }
    return $module->installCartQuoteSchema();
}
