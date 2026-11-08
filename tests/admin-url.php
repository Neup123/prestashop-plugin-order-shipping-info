<?php
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop();
$employees = Db::getInstance()->executeS('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` ORDER BY id_employee ASC LIMIT 1') ?: [];
$id = (int) ($employees[0]['id_employee'] ?? 0);
if (!$id) {
    throw new RuntimeException('No active PrestaShop employee');
}
Context::getContext()->employee = new Employee($id);
$token = Tools::getAdminTokenLite('AdminModules');
if (!is_string($token) || strlen($token) < 8) {
    throw new RuntimeException('Could not generate an admin module token');
}
// CLI has no HTTP request context; Link::getAdminLink can return an empty
// value even when the shop and employee are valid.
echo 'http://localhost:8080/admin-dev/index.php?controller=AdminModules'
    . '&configure=grtransportshipping&module_name=grtransportshipping&token=' . rawurlencode($token);
