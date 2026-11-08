<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../classes/CarrierLifecycle.php';

/**
 * Keep existing carrier ownership and merchant-specific text intact.
 * Only original default name/delay text is localized; no route/order data
 * or carrier identity is rewritten.
 */
function upgrade_module_0_5_3($module)
{
    return GrTransportCarrierLifecycle::localizeDefaults($module);
}
