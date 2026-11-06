<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_0_3_0($module)
{
    return $module->registerHook('displayOrderDetail')
        && $module->registerHook('displayOrderConfirmation');
}
