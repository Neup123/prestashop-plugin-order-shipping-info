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
    // Existing shops must also load the checkout confirmation UI.
    // The hook may already be registered on an existing installation.
    // A redundant registration must not fail the whole upgrade after its
    // schema ALTERs have already succeeded.
    // The CLI upgrade can pass a module object without its numeric ID while
    // refreshing the module cache. Hook::registerHook then returns false
    // AFTER schema migrations have already changed the tables. Recover only
    // the existing installed-module ID; never create or guess an ID.
    if ((int)$module->id<=0) {
        $id=(int)$db->getValue('SELECT id_module FROM '
            ._DB_PREFIX_.'module WHERE name = \''.pSQL($module->name).'\'');
        if ($id<=0) return false;
        $module->id=$id;
    }
    return $module->isRegisteredInHook('displayHeader')
        || $module->registerHook('displayHeader');
}
