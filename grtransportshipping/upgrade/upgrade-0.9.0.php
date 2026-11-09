<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkSettings.php';

/** Add nullable UTC verification timestamps without falsely dating old data. */
function upgrade_module_0_9_0($module)
{
    $db=Db::getInstance();
    foreach (['grtrans_leg','grtrans_transfer'] as $suffix) {
        $table=_DB_PREFIX_.$suffix;
        $exists=(int)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
            ." AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='verified_at'");
        if (!$exists && !$db->execute('ALTER TABLE `'.bqSQL($table)
            .'` ADD COLUMN `verified_at` DATETIME NULL')) return false;
    }
    return GrTransportNetworkSettings::ensureDefaults();
}
