<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_0_2_0($module)
{
    return $module->installOrderSchema()
        && $module->registerHook('actionValidateOrder')
        && $module->registerHook('displayAdminOrder');
}
