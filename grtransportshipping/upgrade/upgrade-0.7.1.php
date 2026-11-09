<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkSettings.php';

/** Populate only missing settings; all defaults work before upgrade too. */
function upgrade_module_0_7_1($module)
{
    return GrTransportNetworkSettings::ensureDefaults();
}
