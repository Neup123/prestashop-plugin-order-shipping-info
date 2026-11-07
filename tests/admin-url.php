<?php
require_once '/var/www/html/config/config.inc.php';
$employees = Db::getInstance()->executeS('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` ORDER BY id_employee ASC LIMIT 1') ?: [];
$id = (int) ($employees[0]['id_employee'] ?? 0);
if (!$id) {
    throw new RuntimeException('No active PrestaShop employee');
}
Context::getContext()->employee = new Employee($id);
echo Context::getContext()->link->getAdminLink('AdminModules', true, [], [
    'configure' => 'grtransportshipping',
    'module_name' => 'grtransportshipping',
]);
