<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkRepository.php';

/** Creates only missing network tables; never seeds/replaces merchant network data. */
function upgrade_module_0_6_0($module)
{
    return GrTransportNetworkRepository::installSchema();
}
