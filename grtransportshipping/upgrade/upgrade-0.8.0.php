<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkSettings.php';

/** Non-destructive, idempotent addition of per-leg journey hours. */
function upgrade_module_0_8_0($module)
{
    $db=Db::getInstance();
    $table=_DB_PREFIX_.'grtrans_leg';
    foreach (['lead_hours','preparation_hours','transit_min_hours','transit_max_hours'] as $column) {
        $exists=(int)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
            ." AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='".pSQL($column)."'");
        if (!$exists && !$db->execute('ALTER TABLE `'.bqSQL($table).'` ADD COLUMN `'
            .bqSQL($column).'` DECIMAL(8,2) NOT NULL DEFAULT 0')) return false;
    }
    return GrTransportNetworkSettings::ensureDefaults();
}
