<?php
require_once '/var/www/html/config/config.inc.php';
$id = (int) Db::getInstance()->getValue('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` WHERE active = 1 ORDER BY id_employee ASC LIMIT 1');
if (!$id) {
    throw new RuntimeException('No active PrestaShop employee');
}
Context::getContext()->employee = new Employee($id);
echo Context::getContext()->link->getAdminLink('AdminModules', true, [], [
    'configure' => 'grtransportshipping',
    'module_name' => 'grtransportshipping',
]);
