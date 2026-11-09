<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/NetworkRepository.php';

/** Adds optional shop-scoped branches and per-combination stock, without
 * replacing/altering PrestaShop stock_available or legacy network data. */
function upgrade_module_0_10_0($module)
{
    return GrTransportNetworkRepository::installSchema();
}
