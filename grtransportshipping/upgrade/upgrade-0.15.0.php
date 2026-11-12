<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkRepository.php';
/** #130: Existing records remain intact and enabled by default. */
function upgrade_module_0_15_0($module)
{
    return GrTransportNetworkRepository::migrateActiveColumns();
}
