<?php
if (!defined('_PS_VERSION_')) { exit; }
/** #131: Stored stop order for connected legs, defaulting to the old unordered state. */
function upgrade_module_0_16_0($module)
{
    $db=Db::getInstance();
    $table=_DB_PREFIX_.'grtrans_leg';
    $columns=$db->executeS('SHOW COLUMNS FROM `'.bqSQL($table).'`');
    if (!is_array($columns)) return false;
    if (in_array('stop_order',array_column($columns,'Field'),true)) return true;
    return $db->execute('ALTER TABLE `'.bqSQL($table)
        .'` ADD COLUMN `stop_order` INT UNSIGNED NOT NULL DEFAULT 0');
}
