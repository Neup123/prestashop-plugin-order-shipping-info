<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * PrestaShop only runs new hook registrations during a module installation
 * or an explicit upgrade. Existing merchant installations must be subscribed
 * to the delivery-step hook when upgrading to 0.5.1.
 *
 * No database or route/quote state is modified.
 */
function upgrade_module_0_5_1($module)
{
    return $module->isRegisteredInHook('displayBeforeCarrier')
        || $module->registerHook('displayBeforeCarrier');
}
