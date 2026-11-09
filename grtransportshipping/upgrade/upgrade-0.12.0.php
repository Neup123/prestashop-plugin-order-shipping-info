<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Add override fields after 0.11.0 geometry migration with safe defaults to existing installations. */
function upgrade_module_0_12_0($module)
{
    $db=Db::getInstance();
    foreach ([
        'grtrans_station'=>['patch_precedence'=>"VARCHAR(12) NOT NULL DEFAULT 'none'"],
        'grtrans_leg'=>[
            'patch_precedence'=>"VARCHAR(12) NOT NULL DEFAULT 'none'",
            'override_winner'=>"VARCHAR(8) NOT NULL DEFAULT 'none'",
        ],
        'grtrans_transfer'=>['override_winner'=>"VARCHAR(8) NOT NULL DEFAULT 'none'"],
    ] as $tableSuffix=>$columns) {
        $table=_DB_PREFIX_.$tableSuffix;
        foreach ($columns as $column=>$definition) {
            $present=(int)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
                ." AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='".pSQL($column)."'");
            if (!$present && !$db->execute('ALTER TABLE `'.bqSQL($table).'` ADD COLUMN `'.bqSQL($column).'` '.$definition)) {
                return false;
            }
        }
    }
    return true;
}
