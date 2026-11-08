<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/classes/QuoteEngine.php';
require_once __DIR__ . '/classes/CartQuote.php';

/**
 * Athens-origin public transport parcel quotes. Prices in EUR.
 */
class Grtransportshipping extends CarrierModule
{
    private array $requestQuotes = [];

    public function __construct()
    {
        $this->name = 'grtransportshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '0.4.0';
        $this->author = 'Neup123';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Greek public transport shipping');
        $this->description = $this->l('Station-based parcel transport by KTEL bus, ferry and air.');
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->id_carrier = (int) Configuration::get('GRTRANS_CARRIER');
    }

    public function install()
    {
        if (!parent::install() || !$this->installSchema() || !$this->createCarrier()) {
            return false;
        }
        $this->seedDemo();
        return $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('displayCarrierExtraContent')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('displayOrderConfirmation');
    }

    public function uninstall()
    {
        $id = (int) Configuration::get('GRTRANS_CARRIER');
        if ($id) {
            $carrier = new Carrier($id);
            if (Validate::isLoadedObject($carrier)) {
                $carrier->deleted = true;
                $carrier->save();
            }
        }
        Configuration::deleteByName('GRTRANS_CARRIER');
        // Preserve route data on uninstall to avoid silent loss of merchant prices.
        return parent::uninstall();
    }

    private function installSchema(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'grtrans_route` (
            `id_route` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `mode` VARCHAR(16) NOT NULL,
            `origin_name` VARCHAR(190) NOT NULL,
            `station_name` VARCHAR(190) NOT NULL,
            `city` VARCHAR(128) NOT NULL,
            `postal_prefix` VARCHAR(5) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL,
            `pickup_only` TINYINT(1) NOT NULL DEFAULT 1,
            `route_status` VARCHAR(12) NOT NULL DEFAULT 'draft',
            `demo` TINYINT(1) NOT NULL DEFAULT 0,
            `verified_at` DATETIME NULL,
            `verified_by_employee` INT NULL,
            `notice` TEXT NULL,
            INDEX `lookup_prefix` (`postal_prefix`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        return (bool) Db::getInstance()->execute($sql) && $this->installOrderSchema() && $this->installCartQuoteSchema();
    }

    public function installOrderSchema(): bool
    {
        return (bool) Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'grtrans_order` (
            `id_order` INT UNSIGNED NOT NULL PRIMARY KEY,
            `mode` VARCHAR(16) NOT NULL,
            `station_name` VARCHAR(190) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL,
            `pickup_only` TINYINT(1) NOT NULL DEFAULT 1,
            `notice` TEXT NULL,
            `demo` TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public function installCartQuoteSchema(): bool
    {
        return (bool) Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'grtrans_cart_quote` (
            `id_cart` INT UNSIGNED NOT NULL PRIMARY KEY,
            `id_address` INT UNSIGNED NOT NULL,
            `quote_data` TEXT NOT NULL,
            `quote_status` VARCHAR(12) NOT NULL DEFAULT \'pinned\',
            `created_at` DATETIME NOT NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'] ?? null;
        $cart = $params['cart'] ?? null;
        if (!$order || !$cart || (int) $order->id_carrier !== (int) Configuration::get('GRTRANS_CARRIER')) {
            return;
        }
        $quote = $this->currentQuote($cart);
        if (!$quote) {
            return;
        }
        Db::getInstance()->insert('grtrans_order', [
            'id_order' => (int) $order->id,
            'mode' => pSQL($quote['mode']),
            'station_name' => pSQL($quote['station_name']),
            'price' => (float) $quote['price'],
            'pickup_only' => (int) $quote['pickup_only'],
            'notice' => pSQL((string) $quote['notice']),
            'demo' => (int) $quote['demo'],
        ]);
    }

    public function hookDisplayAdminOrder($params)
    {
        $id = (int) ($params['id_order'] ?? 0);
        if (!$id) {
            return '';
        }
        $info = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $id);
        if (!$info) {
            return '';
        }
        return '<section class="card"><div class="card-body"><h3>Public transport shipping</h3>'
            . '<p>Mode: ' . $this->escape($info['mode']) . '</p>'
            . '<p><strong>' . (!empty($info['pickup_only']) ? 'STATION PICKUP ONLY' : 'Delivery arrangement')
            . ': ' . $this->escape($info['station_name']) . '</strong></p>'
            . '<p>' . $this->escape($info['notice']) . '</p>'
            . (!empty($info['demo']) ? '<strong>DEMO PRICE — NOT VERIFIED</strong>' : '')
            . '</div></section>';
    }

    public function hookDisplayOrderDetail($params)
    {
        return $this->renderOrderPickup($params['order'] ?? null);
    }

    public function hookDisplayOrderConfirmation($params)
    {
        return $this->renderOrderPickup($params['order'] ?? null);
    }

    private function renderOrderPickup($order): string
    {
        if (!$order || !(int) $order->id) {
            return '';
        }
        $info = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . (int) $order->id);
        if (!$info) {
            return '';
        }
        $title = !empty($info['pickup_only'])
            ? $this->l('STATION PICKUP ONLY — NO HOME DELIVERY')
            : $this->l('Transport destination information');
        return '<div class="alert alert-info grtransport-pickup">'
            . '<strong>' . $this->escape($title) . '</strong>'
            . '<p>' . $this->escape($this->l('Collection location: ')) . $this->escape($info['station_name']) . '</p>'
            . '<p>' . $this->escape($info['notice']) . '</p>'
            . (!empty($info['demo']) ? '<strong>' . $this->escape($this->l('DEMO ESTIMATE — NOT VERIFIED')) . '</strong>' : '')
            . '</div>';
    }

    private function createCarrier(): bool
    {
        $carrier = new Carrier();
        $carrier->name = 'Greek transport — station pickup';
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
        $carrier->is_module = true;
        $carrier->need_range = true;
        $carrier->range_behavior = 0;
        $carrier->grade = 0;
        $carrier->delay = [];
        foreach (Language::getLanguages(false) as $lang) {
            $carrier->delay[(int) $lang['id_lang']] = 'Collection from the named transport station (not home delivery)';
        }
        if (!$carrier->add()) {
            return false;
        }
        Configuration::updateValue('GRTRANS_CARRIER', (int) $carrier->id);
        $range = new RangeWeight();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = 0;
        $range->delimiter2 = 100000;
        if (!$range->add()) {
            return false;
        }
        $gr = Country::getByIso('GR');
        if ($gr) {
            $country = new Country((int) $gr);
            $carrier->addZone((int) $country->id_zone);
        }
        $groupIds = [];
        foreach (Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')) as $group) {
            $groupIds[] = (int) $group['id_group'];
        }
        return $carrier->setGroups($groupIds);
    }

    private function seedDemo(): void
    {
        $existing = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'grtrans_route`');
        if ($existing) {
            return;
        }
        foreach (require __DIR__ . '/demo/routes.php' as $r) {
            Db::getInstance()->insert('grtrans_route', [
                'mode' => pSQL($r[0]), 'origin_name' => pSQL($r[1]),
                'station_name' => pSQL($r[2]), 'city' => pSQL($r[3]),
                'postal_prefix' => pSQL($r[4]), 'price' => (float) $r[5],
                'pickup_only' => (int) $r[6], 'route_status' => GrTransportRouteState::AVAILABLE, 'demo' => 1,
                'notice' => pSQL($r[7]),
            ]);
        }
    }

    public function hookActionCarrierUpdate($params)
    {
        if ((int) ($params['id_carrier'] ?? 0) === (int) Configuration::get('GRTRANS_CARRIER')) {
            Configuration::updateValue('GRTRANS_CARRIER', (int) $params['carrier']->id);
        }
    }

    private function currentQuote($cart)
    {
        if (!$cart || !(int) $cart->id_address_delivery) {
            return null;
        }
        $address = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            return null;
        }
        $country = new Country((int) $address->id_country);
        if ($country->iso_code !== 'GR') {
            return null;
        }
        $routes = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route`');
        return GrTransportQuoteEngine::select($routes ?: [], (string) $address->postcode, (string) $address->city);
    }

    public function getOrderShippingCost($cart, $shipping_cost)
    {
        return $this->getOrderShippingCostExternal($cart);
    }

    public function getOrderShippingCostExternal($cart)
    {
        if ((int) $this->id_carrier !== (int) Configuration::get('GRTRANS_CARRIER')) {
            return false;
        }
        $quote = $this->currentQuote($cart);
        return $quote === null ? false : (float) $quote['price'];
    }

    public function hookDisplayCarrierExtraContent($params)
    {
        $quote = $this->currentQuote($this->context->cart);
        if (!$quote) {
            return '<p>' . $this->escape($this->l('No transport service is available for this address.')) . '</p>';
        }
        $station = $this->escape($quote['station_name']);
        $notice = $this->escape($quote['notice']);
        $message = !empty($quote['pickup_only'])
            ? $this->l('STATION PICKUP ONLY — no home delivery.')
            : $this->l('Delivery arrangement must be confirmed.');
        return '<div class="alert alert-info"><strong>' . $this->escape($message)
            . '</strong><p>' . $this->escape($this->l('Collection point: ')) . $station
            . '</p><p>' . $notice . '</p>'
            . (!empty($quote['demo']) ? '<strong>' . $this->escape($this->l('DEMO ESTIMATE — NOT A LIVE SHIPPING OFFER')) . '</strong>' : '')
            . '</div>';
    }

    private function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('grtrans_save') || Tools::isSubmit('grtrans_delete')) {
            // PrestaShop 9 uses Symfony masked CSRF tokens: two valid tokens
            // need not have identical byte strings. Ask the core validator
            // rather than comparing against a newly generated token.
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            $tokenManager = $container ? $container->get(\PrestaShopBundle\Security\Admin\UserTokenManager::class) : null;
            $hasFormToken = isset($_POST['token']) && is_string($_POST['token']) && $_POST['token'] !== '';
            if (!$hasFormToken || !$tokenManager || !$tokenManager->isTokenValid()) {
                return $this->displayError($this->l('Invalid admin token.'));
            }
            try {
                if (Tools::isSubmit('grtrans_save')) {
                    $this->saveRule();
                } else {
                    $id = (int) Tools::getValue('id_route');
                    if ($id <= 0) {
                        throw new Exception('Invalid route ID');
                    }
                    Db::getInstance()->delete('grtrans_route', 'id_route = ' . $id);
                }
                $out .= $this->displayConfirmation($this->l('Route dataset updated.'));
            } catch (Exception $e) {
                $out .= $this->displayError($this->escape($e->getMessage()));
            }
        }
        $url = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name, 'module_name' => $this->name,
        ]);
        $action = $this->escape($url);
        $csrf = $this->escape(Tools::getAdminTokenLite('AdminModules'));
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route` ORDER BY id_route ASC') ?: [];
        $out .= '<div class="panel"><h3>Greek public transport routes</h3><p>DEMO rows are assumptions, not verified freight charges. Replace before accepting orders.</p>'
            . '<table class="table"><thead><tr><th>ID</th><th>Mode</th><th>From</th><th>Pickup station</th><th>City</th><th>Postcode prefix</th><th>EUR</th><th>Pickup only</th><th>Active</th><th>Demo</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr>';
            foreach (['id_route','mode','origin_name','station_name','city','postal_prefix','price','pickup_only','route_status','demo'] as $key) {
                $out .= '<td>' . $this->escape($r[$key]) . '</td>';
            }
            $out .= '<td><a class="btn btn-default" href="' . $action . '&amp;grtrans_edit=' . (int) $r['id_route'] . '">Edit</a><form method="post" action="' . $action . '"><input type="hidden" name="id_route" value="' . (int) $r['id_route'] . '"><input type="hidden" name="token" value="' . $csrf . '"><button name="grtrans_delete" value="1" onclick="return confirm(\'Delete route?\')">Delete</button></form></td></tr>';
        }
        $editing = null;
        $editId = (int) Tools::getValue('grtrans_edit', 0);
        foreach ($rows as $item) {
            if ((int) $item['id_route'] === $editId) {
                $editing = $item;
                break;
            }
        }
        $out .= '</tbody></table><h4>' . ($editing ? 'Edit route #' . $editId : 'Add route') . '</h4><form method="post" action="' . $action . '"><input type="hidden" name="token" value="' . $csrf . '">';
        $fields = [
            'id_route' => 'Route ID (0 to add)', 'mode' => 'Mode: bus / ferry / flight',
            'origin_name' => 'Origin terminal', 'station_name' => 'Destination pickup station',
            'city' => 'City (optional)', 'postal_prefix' => 'Postal prefix (optional)',
            'price' => 'Transport price EUR', 'pickup_only' => 'Station pickup only (1 / 0)',
            'route_status' => 'Route state (Draft ignores fallback; Suspended blocks broader fares)',
            'demo' => 'Unverified demo estimate (1) / operator-confirmed price (0)',
            'notice' => 'Customer notice',
        ];
        foreach ($fields as $field => $label) {
            $default = (string) ($editing[$field] ?? (['id_route'=>'0', 'mode'=>'bus', 'origin_name'=>'Athens', 'price'=>'0.00', 'pickup_only'=>'1', 'route_status'=>'draft', 'demo'=>'1'][$field] ?? ''));
            $out .= '<label style="display:block;margin:8px 0">' . $this->escape($label);
            if ($field === 'mode' || $field === 'route_status' || $field === 'pickup_only' || $field === 'demo') {
                $values = $field === 'mode'
                    ? ['bus' => 'KTEL bus', 'ferry' => 'Ferry', 'flight' => 'Domestic flight']
                    : ($field === 'route_status'
                        ? ['draft' => 'Draft — no quote, no veto', 'available' => 'Available — can be quoted',
                           'suspended' => 'Suspended — blocks broader matching routes']
                        : ['1' => 'Yes', '0' => 'No']);
                $out .= '<select class="form-control" style="max-width:600px" name="' . $field . '">';
                foreach ($values as $value => $name) {
                    $out .= '<option value="' . $this->escape($value) . '"'
                        . ($default === (string) $value ? ' selected' : '') . '>' . $this->escape($name) . '</option>';
                }
                $out .= '</select>';
            } elseif ($field === 'notice') {
                $out .= '<textarea class="form-control" rows="3" style="max-width:600px"'
                    . ' name="notice" maxlength="2000">' . $this->escape($default) . '</textarea>';
            } else {
                $lengths = ['id_route'=>10, 'origin_name'=>190, 'station_name'=>190, 'city'=>128, 'postal_prefix'=>5, 'price'=>16];
                $out .= '<input class="form-control" style="max-width:600px" name="' . $field
                    . '" value="' . $this->escape($default) . '" maxlength="' . $lengths[$field] . '">';
            }
            $out .= '</label>';
        }
        $out .= '<button type="submit" name="grtrans_save" value="1" class="btn btn-primary">Save route</button></form></div>';
        return $out;
    }

    private function saveRule(): void
    {
        $id = (int) Tools::getValue('id_route', 0);
        $mode = (string) Tools::getValue('mode');
        $price = (string) Tools::getValue('price');
        $prefix = trim((string) Tools::getValue('postal_prefix'));
        $city = trim((string) Tools::getValue('city'));
        $origin = trim((string) Tools::getValue('origin_name'));
        $station = trim((string) Tools::getValue('station_name'));
        $notice = trim((string) Tools::getValue('notice'));
        if (!in_array($mode, ['bus', 'ferry', 'flight'], true)
            || !preg_match('/^\\d{0,5}$/', $prefix)
            || ($prefix === '' && $city === '')
            || !is_numeric($price) || (float) $price < 0 || (float) $price > 999999
            || $origin === '' || $station === ''
            || mb_strlen($origin) > 190 || mb_strlen($station) > 190 || mb_strlen($city) > 128
            || mb_strlen($notice) > 2000) {
            throw new InvalidArgumentException('Invalid route data');
        }
        $db = Db::getInstance();
        $old = $id > 0 ? $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route` WHERE id_route = ' . $id) : null;
        if ($id > 0 && !$old) {
            throw new InvalidArgumentException('Route not found');
        }
        $state = GrTransportRouteState::transition($old ?: null, [
            'route_status' => (string) Tools::getValue('route_status'),
            'pickup_only' => (string) Tools::getValue('pickup_only'),
            'demo' => (string) Tools::getValue('demo'),
        ], (int) ($this->context->employee->id ?? 0),
            $old && round((float) $old['price'], 2) !== round((float) $price, 2));
        $data = [
            'mode' => pSQL($mode),
            'origin_name' => pSQL($origin),
            'station_name' => pSQL($station),
            'city' => pSQL($city),
            'postal_prefix' => pSQL($prefix),
            'price' => round((float) $price, 2),
            'notice' => pSQL($notice),
        ] + $state;
        $success = $id > 0
            ? $db->update('grtrans_route', $data, 'id_route = ' . $id)
            : $db->insert('grtrans_route', $data);
        if (!$success) {
            throw new RuntimeException('Route write failed');
        }
        $this->clearQuoteCache();
    }
}
