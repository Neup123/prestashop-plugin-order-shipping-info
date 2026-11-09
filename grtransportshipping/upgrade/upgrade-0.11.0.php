<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Add optional map geometry to existing network tables without losing radii,
 * prices or existing service records. Safe to rerun after partial upgrades. */
function upgrade_module_0_11_0($module)
{
    $db = Db::getInstance();
    foreach ([
        'grtrans_station' => [
            'coverage_kind' => "VARCHAR(24) NOT NULL DEFAULT 'radius'",
            'coverage_geojson' => 'TEXT NULL',
        ],
        'grtrans_leg' => [
            'area_coverage_kind' => "VARCHAR(24) NOT NULL DEFAULT 'radius'",
            'area_coverage_geojson' => 'TEXT NULL',
            'route_geojson' => 'TEXT NULL',
        ],
    ] as $table => $columns) {
        $name = _DB_PREFIX_ . $table;
        $fields = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($name) . '`');
        if (!is_array($fields)) return false;
        $existing = array_column($fields, 'Field');
        foreach ($columns as $field => $definition) {
            if (in_array($field, $existing, true)) continue;
            if (!$db->execute('ALTER TABLE `' . bqSQL($name) . '` ADD COLUMN `' .
                bqSQL($field) . '` ' . $definition)) return false;
        }
    }
    return true;
}
