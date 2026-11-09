<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkRepository.php';

/** Safe additive migration of live merchant data; never rewrites fares/orders. */
function upgrade_module_0_7_0($module)
{
    if (!GrTransportNetworkRepository::installSchema()) return false;
    $p=_DB_PREFIX_;
    $columns=[
        $p.'grtrans_leg'=>[
            'tariff_type'=>"VARCHAR(16) NOT NULL DEFAULT 'flat'",
            'rate_per_unit'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
            'estimate'=>"TINYINT(1) NOT NULL DEFAULT 0",
            'estimate_low_factor'=>"DECIMAL(5,3) NOT NULL DEFAULT 0.9",
            'estimate_high_factor'=>"DECIMAL(5,3) NOT NULL DEFAULT 1.1",
        ],
        $p.'grtrans_order'=>[
            'route_snapshot'=>'LONGTEXT NULL',
            'estimate_min'=>'DECIMAL(12,2) NULL',
            'estimate_max'=>'DECIMAL(12,2) NULL',
            'estimate_state'=>"VARCHAR(16) NOT NULL DEFAULT 'not_estimate'",
            'actual_shipping_charge'=>'DECIMAL(12,2) NULL',
            'external_receipt'=>'VARCHAR(190) NULL',
            'settled_at'=>'DATETIME NULL',
        ],
    ];
    $db=Db::getInstance();
    foreach ($columns as $table=>$spec) {
        foreach ($spec as $name=>$sql) {
            $exists=(int)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
                ." AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='".pSQL($name)."'");
            if (!$exists && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `'
                . bqSQL($name) . '` ' . $sql)) return false;
        }
    }
    return true;
}
