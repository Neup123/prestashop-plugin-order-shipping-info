<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Panel I: make the existing station outer radius an optional override.
 * SQL MODIFY retains every existing numeric value: old stations remain explicit
 * overrides; only newly inserted stations start NULL and inherit the global.
 * Direct areas gain a nullable override; old normal radius remains unchanged.
 */
function upgrade_module_0_13_0($module)
{
    $db=Db::getInstance();
    $station=_DB_PREFIX_.'grtrans_station';
    $columns=$db->executeS('SHOW COLUMNS FROM `'.bqSQL($station).'`');
    if (!is_array($columns)) return false;
    $outer=null;
    foreach ($columns as $column) {
        if (($column['Field'] ?? '')==='outer_radius_km') $outer=$column;
    }
    if ($outer===null) return false;
    if (strtoupper((string)($outer['Null'] ?? 'NO'))!=='YES'
        && !$db->execute('ALTER TABLE `'.bqSQL($station).'` MODIFY COLUMN `outer_radius_km` DECIMAL(8,2) NULL DEFAULT NULL')) {
        return false;
    }
    $leg=_DB_PREFIX_.'grtrans_leg';
    $columns=$db->executeS('SHOW COLUMNS FROM `'.bqSQL($leg).'`');
    if (!is_array($columns)) return false;
    if (!in_array('area_outer_radius_km',array_column($columns,'Field'),true)
        && !$db->execute('ALTER TABLE `'.bqSQL($leg).'` ADD COLUMN `area_outer_radius_km` DECIMAL(8,2) NULL DEFAULT NULL')) {
        return false;
    }
    return true;
}
