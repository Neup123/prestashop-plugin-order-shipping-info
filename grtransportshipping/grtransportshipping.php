<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/classes/QuoteEngine.php';
require_once __DIR__ . '/classes/CartQuote.php';
require_once __DIR__ . '/classes/OrderSnapshot.php';
require_once __DIR__ . '/classes/OrderCharge.php';
require_once __DIR__ . '/classes/CarrierLifecycle.php';
require_once __DIR__ . '/classes/RouteRepository.php';

/**
 * Athens-origin public transport parcel quotes. Prices in EUR.
 */
class Grtransportshipping extends CarrierModule
{
    /** One customer/admin text table for the same pickup, demo and station state. */
    private const NOTICE_TEXT = [
        'pickup' => 'STATION PICKUP ONLY — NO HOME DELIVERY',
        'arrangement' => 'Delivery arrangement must be confirmed.',
        'location' => 'Collection point: ',
        'demo' => 'DEMO ESTIMATE — NOT A LIVE SHIPPING OFFER',
        'mode' => 'Mode: ',
    ];

    private array $requestQuotes = [];

    public function __construct()
    {
        $this->name = 'grtransportshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '0.5.0';
        $this->author = 'Neup123';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Greek public transport shipping');
        $this->description = $this->l('Station-based parcel transport by KTEL bus, ferry and air.');
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        GrTransportCarrierLifecycle::activeId($this);
    }

    public function install()
    {
        // PrestaShop's parent::install() rejects an already inserted module.
        // A failed late hook registration leaves a partially installed module;
        // resume that state instead of orphaning another carrier.
        if (Module::isInstalled($this->name)) {
            $this->id = (int) Module::getModuleIdByName($this->name);
            if (!$this->id) {
                return false;
            }
        } elseif (!parent::install()) {
            return false;
        }
        if (!$this->installSchema() || !$this->createCarrier()) {
            return false;
        }
        try {
            GrTransportRouteRepository::seedOnce();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Transport demo seed failed: ' . $e->getMessage(), 3);
            return false;
        }
        return $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('displayCarrierExtraContent')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('displayOrderConfirmation');
    }

    public function uninstall()
    {
        // Preserve historical order/route data, but retire the single
        // canonical owned carrier and clear its identity through one writer.
        return GrTransportCarrierLifecycle::retire($this) && parent::uninstall();
    }

    private function installSchema(): bool
    {
        // All SQL widths and the notice CHECK derive from RouteFields::spec().
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'grtrans_route` ('
            . GrTransportRouteFields::schemaColumns()
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        return (bool) Db::getInstance()->execute($sql)
            && $this->installOrderSchema() && $this->installCartQuoteSchema();
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
            `address_fingerprint` CHAR(64) NOT NULL,
            `quote_data` TEXT NOT NULL,
            `quote_status` VARCHAR(12) NOT NULL DEFAULT \'pinned\',
            `created_at` DATETIME NOT NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'] ?? null;
        $cart = $params['cart'] ?? null;
        if (!$order || !$cart || (int) $order->id_carrier !== GrTransportCarrierLifecycle::activeId($this)) {
            return;
        }
        $quote = $this->currentQuote($cart);
        if (!$quote) {
            throw new RuntimeException('Cannot confirm order: transport quote is unavailable');
        }
        // One invariant for both validation and subsequent snapshot repair:
        // distinguish an actual applied free-shipping voucher or configured
        // handling fee from a genuine unexplained price discrepancy.
        GrTransportOrderCharge::checkOrder((int) $order->id, $quote);
        // One checked, idempotent writer. Any failed persistence raises an
        // exception and a PrestaShop error log rather than silently losing
        // historical pickup details.
        GrTransportOrderSnapshot::capture((int) $order->id, $quote);
        GrTransportCartQuote::consume((int) ($cart->id ?? 0));
    }

    public function hookDisplayAdminOrder($params)
    {
        $id = (int) ($params['id_order'] ?? 0);
        if (!$id) {
            return '';
        }
        $info = GrTransportOrderSnapshot::find($id);
        $order = new Order($id);
        if (!$info && (!Validate::isLoadedObject($order)
            || !GrTransportOrderSnapshot::belongsToCarrier((int) $order->id_carrier))) {
            return '';
        }
        $url = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name, 'module_name' => $this->name,
        ]);
        $repairForm = '<form method="post" action="' . $this->escape($url) . '">'
            . '<input type="hidden" name="token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '">'
            . '<button class="btn btn-warning" type="submit" name="grtrans_repair_order" value="' . $id . '">'
            . ($info ? 'Regenerate original pickup snapshot' : 'Repair missing pickup snapshot') . '</button></form>';
        if (!$info) {
            return '<div class="alert alert-danger"><strong>Transport pickup data are missing for this order.</strong>'
                . '<p>Repair is possible only from the original stored cart quote; do not use a current route price.</p>'
                . $repairForm . '</div>';
        }
        return '<section class="card"><div class="card-body"><h3>Public transport shipping</h3>'
            . $this->renderNotice($info, 'admin') . $repairForm . '</div></section>';
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
        $info = GrTransportOrderSnapshot::find((int) $order->id);
        if (!$info) {
            // Never make a missing snapshot look like an ordinary carrier:
            // customers must be warned to contact the merchant before travel.
            return GrTransportOrderSnapshot::belongsToCarrier((int) ($order->id_carrier ?? 0))
                ? '<div class="alert alert-danger">Transport pickup details are temporarily unavailable. Please contact the merchant before collection.</div>'
                : '';
        }
        return $this->renderNotice($info, 'order');
    }

    /**
     * One renderer and one wording table for checkout, customer order and
     * administrative order views. Context changes markup and optional mode,
     * never the pickup, warning or collection-point text.
     */
    private function renderNotice(array $row, string $context): string
    {
        if (!in_array($context, ['checkout', 'order', 'admin'], true)) {
            throw new InvalidArgumentException('Invalid transport notice context');
        }
        $heading = !empty($row['pickup_only']) ? self::NOTICE_TEXT['pickup'] : self::NOTICE_TEXT['arrangement'];
        $body = '<strong>' . $this->escape($this->l($heading)) . '</strong>'
            . ($context === 'admin'
                ? '<p>' . $this->escape($this->l(self::NOTICE_TEXT['mode']))
                    . $this->escape($row['mode'] ?? '') . '</p>'
                : '')
            . '<p>' . $this->escape($this->l(self::NOTICE_TEXT['location']))
                . $this->escape($row['station_name'] ?? '') . '</p>'
            . '<p>' . $this->escape($row['notice'] ?? '') . '</p>'
            . (!empty($row['demo'])
                ? '<strong>' . $this->escape($this->l(self::NOTICE_TEXT['demo'])) . '</strong>'
                : '');
        return $context === 'admin'
            ? '<div class="grtransport-pickup">' . $body . '</div>'
            : '<div class="alert alert-info grtransport-pickup">' . $body . '</div>';
    }

    private function createCarrier(): bool
    {
        return GrTransportCarrierLifecycle::ensure($this);
    }

    public function hookActionCarrierUpdate($params)
    {
        GrTransportCarrierLifecycle::onCarrierUpdate(
            $this, (int) ($params['id_carrier'] ?? 0), $params['carrier'] ?? null
        );
    }

    /** Request-level cache: no repeated route-table scans for one cart/address. */
    public function clearQuoteCache(): void
    {
        $this->requestQuotes = [];
    }

    private function quoteResult($cart): array
    {
        if (!$cart || !(int) $cart->id_address_delivery) {
            return ['status' => 'missing_address', 'quote' => null];
        }
        $cartId = (int) ($cart->id ?? 0);
        $addressId = (int) $cart->id_address_delivery;
        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address)) {
            return ['status' => 'invalid_address', 'quote' => null];
        }
        $fingerprint = hash('sha256', (int) $address->id_country . ':'
            . trim((string) $address->postcode) . ':' . trim((string) $address->city));
        $key = $cartId . ':' . $addressId . ':' . $fingerprint . ':' . ($cartId ? '' : spl_object_id($cart));
        if (isset($this->requestQuotes[$key])) {
            return $this->requestQuotes[$key];
        }
        $pinned = GrTransportCartQuote::pinned($cartId, $addressId, $fingerprint);
        if ($pinned !== null) {
            return $this->requestQuotes[$key] = ['status' => 'quoted', 'quote' => $pinned];
        }
        $country = new Country((int) $address->id_country);
        if ($country->iso_code !== 'GR') {
            return $this->requestQuotes[$key] = ['status' => 'outside_greece', 'quote' => null];
        }
        $routes = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route`');
        if (!is_array($routes)) {
            throw new RuntimeException('Transport route lookup failed');
        }
        $result = GrTransportQuoteEngine::resolve($routes, (string) $address->postcode, (string) $address->city);
        if ($result['status'] === 'quoted') {
            // A CarrierModule overrides PrestaShop's precomputed shipping_cost;
            // persist the eligible shop handling fee with the quote so charge,
            // display and order snapshot all reuse the same adjustment.
            $result['quote']['handling_fee'] = GrTransportOrderCharge::configuredHandlingFee(
                GrTransportCarrierLifecycle::activeId($this), (int) ($cart->id_currency ?? 0)
            );
            $result['quote'] = GrTransportCartQuote::pin($cartId, $addressId, $fingerprint, $result['quote']);
        } elseif (in_array($result['status'], ['ambiguous', 'suspended'], true)) {
            PrestaShopLogger::addLog('Transport route resolution: ' . $result['status']
                . ' for delivery address #' . $addressId, 2, null, 'Address', $addressId);
        }
        return $this->requestQuotes[$key] = $result;
    }

    private function currentQuote($cart): ?array
    {
        return $this->quoteResult($cart)['quote'];
    }

    public function getOrderShippingCost($cart, $shipping_cost)
    {
        return $this->getOrderShippingCostExternal($cart);
    }

    public function getOrderShippingCostExternal($cart)
    {
        if (GrTransportCarrierLifecycle::activeId($this) <= 0) {
            return false;
        }
        $resolved = $this->quoteResult($cart);
        return $resolved['status'] !== 'quoted' ? false
            : GrTransportOrderCharge::expectedShipping($resolved['quote']);
    }

    public function hookDisplayCarrierExtraContent($params)
    {
        $resolved = $this->quoteResult($this->context->cart);
        $quote = $resolved['quote'];
        if (!$quote) {
            $messages = [
                'missing_address' => 'Enter a delivery address to obtain a transport quote.',
                'invalid_address' => 'The delivery address could not be loaded.',
                'outside_greece' => 'Transport service is available in Greece only.',
                'unmatched' => 'No priced transport route matches this destination.',
                'draft_only' => 'The matching transport route is a draft and is not for sale.',
                'suspended' => 'This destination is suspended; broader regional prices are not applied.',
                'ambiguous' => 'Conflicting transport routes require an administrator to resolve them.',
            ];
            $message = $messages[$resolved['status']] ?? 'The transport quote is temporarily unavailable.';
            return '<p>' . $this->escape($this->l($message)) . '</p>';
        }
        return $this->renderNotice($quote, 'checkout');
    }

    private function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function getContent()
    {
        $out = '';
        $submitted = null;
        $fieldErrors = [];
        $saveError = '';
        $saveAttempt = Tools::isSubmit('grtrans_save');
        if (Tools::isSubmit('grtrans_save') || Tools::isSubmit('grtrans_delete')
            || Tools::isSubmit('grtrans_repair_order') || Tools::isSubmit('grtrans_repair_carrier')) {
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
                if (Tools::isSubmit('grtrans_repair_carrier')) {
                    if (!GrTransportCarrierLifecycle::ensure($this)) {
                        throw new RuntimeException('Could not recover module carrier identity or delivery range');
                    }
                    $out .= $this->displayConfirmation($this->l('Transport carrier restored and assigned to this module.'));
                } elseif (Tools::isSubmit('grtrans_repair_order')) {
                    $idOrder = (int) Tools::getValue('grtrans_repair_order');
                    GrTransportOrderSnapshot::repair($idOrder);
                    $out .= $this->displayConfirmation($this->l('Order pickup snapshot regenerated from its original cart quote.'));
                } else {
                    if ($saveAttempt) {
                        // This same named-field collection feeds the one repository
                        // validator and, on failure, the exact form re-render.
                        $submitted = $this->routeInputValues();
                        $this->saveRule($submitted);
                    } else {
                        $id = (int) Tools::getValue('id_route');
                        GrTransportRouteRepository::delete($id);
                        $this->clearQuoteCache();
                    }
                    $out .= $this->displayConfirmation($this->l('Route dataset updated.'));
                }
            } catch (GrTransportRouteValidationException $e) {
                if ($saveAttempt) {
                    $submitted ??= $this->routeInputValues();
                    $fieldErrors = $e->fieldErrors();
                    $saveError = 'Route not saved. Correct the highlighted fields and try again.';
                } else {
                    $out .= $this->displayError($this->escape($e->getMessage()));
                }
            } catch (Exception $e) {
                if ($saveAttempt) {
                    $submitted ??= $this->routeInputValues();
                    $saveError = 'Route not saved: ' . $e->getMessage();
                } else {
                    $out .= $this->displayError($this->escape($e->getMessage()));
                }
            }
        }
        $url = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name, 'module_name' => $this->name,
        ]);
        $action = $this->escape($url);
        $csrf = $this->escape(Tools::getAdminTokenLite('AdminModules'));
        $carrierState = GrTransportCarrierLifecycle::inspect();
        if ($carrierState['state'] !== GrTransportCarrierLifecycle::READY) {
            $out .= '<div class="alert alert-danger"><strong>Transport carrier unavailable: '
                . $this->escape($carrierState['state']) . ' (configured ID ' . (int) $carrierState['id'] . ').</strong>'
                . '<p>The carrier cannot quote until an administrator repairs it. A deleted or foreign carrier will not be reused.</p>'
                . '<form method="post" action="' . $action . '"><input type="hidden" name="token" value="' . $csrf . '">'
                . '<button name="grtrans_repair_carrier" value="1" class="btn btn-warning">Repair transport carrier</button></form></div>';
        } else {
            $out .= '<div class="alert alert-success" role="status"><strong>Transport carrier ready</strong>'
                . ' — Carrier #' . (int) $carrierState['id'] . ' is available for matching routes.</div>';
        }
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_route` ORDER BY id_route ASC') ?: [];
        $warnings = GrTransportQuoteEngine::shadowedSuspensions($rows);
        $out .= '<div class="panel"><h3>Greek public transport routes</h3><p>DEMO rows are assumptions, not verified freight charges. Replace before accepting orders.</p>';
        $out .= '<p><strong>Draft:</strong> no quote and no block. <strong>Available:</strong> matching quotes can be sold. <strong>Suspended:</strong> a specific rule blocks broader regional quotes for that destination, including existing rules. Cart quotes already pinned may remain valid for 30 minutes.</p>';
        foreach ($warnings as $warning) {
            $out .= '<div class="alert alert-warning">Suspended route #' . (int) $warning['suspended']
                . ' shadows available regional route #' . (int) $warning['fallback'] . '; no fallback price will be offered.</div>';
        }
        // Bootstrap back-office conventions plus scoped, readable state colors.
        // Colors reinforce explicit text: they are never the sole state signal.
        $out .= '<style>
            .grtrans-route-grid{max-width:100%;overflow-x:auto}
            .grtrans-route-grid th,.grtrans-route-grid td{vertical-align:middle!important;white-space:nowrap}
            .grtrans-route-grid.table-responsive tr.grtrans-row-demo>td{background-color:#fff8e6!important}
            .grtrans-route-grid.table-responsive tr.grtrans-row-suspended>td{background-color:#ffefed!important}
            .grtrans-pill{display:inline-block;border-radius:4px;padding:4px 8px;font-size:12px;font-weight:600;line-height:1.3}
            .grtrans-state-draft{background:#e5e7eb;color:#303845}
            .grtrans-state-available,.grtrans-verified{background:#d9f3e5;color:#14532d}
            .grtrans-state-suspended{background:#ffe0de;color:#8c2222}
            .grtrans-demo{background:#ffe7aa;color:#664500}
            .grtrans-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;min-width:142px}
            .grtrans-actions form{margin:0}
            .grtrans-editor{scroll-margin-top:90px}
            .grtrans-editor.is-editing{border-left:5px solid #337ab7}
            .grtrans-editor .form-group{max-width:640px}
            .grtrans-editor .help-block{display:block;margin-top:5px}
        </style>';
        $out .= '<div class="grtrans-route-grid table-responsive"><table class="table table-hover">'
            . '<thead><tr><th>ID</th><th>Mode</th><th>From</th><th>Pickup station</th><th>City</th>'
            . '<th>Postcode prefix</th><th>EUR</th><th>Pickup only</th><th>State</th><th>Demo</th>'
            . '<th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int) $r['id_route'];
            $status = GrTransportRouteState::status($r);
            $isDemo = (int) $r['demo'] === 1;
            $rowClass = $status === GrTransportRouteState::SUSPENDED
                ? 'grtrans-row-suspended' : ($isDemo ? 'grtrans-row-demo' : '');
            $out .= '<tr class="' . $rowClass . '"><td>' . $id . '</td>';
            $modeChoices = GrTransportRouteFields::choices('mode');
            $out .= '<td>' . $this->escape($modeChoices[$r['mode']] ?? $r['mode']) . '</td>';
            foreach (['origin_name', 'station_name', 'city', 'postal_prefix', 'price'] as $field) {
                $out .= '<td>' . $this->escape($r[$field]) . '</td>';
            }
            $pickupChoices = GrTransportRouteFields::choices('pickup_only');
            $out .= '<td>' . $this->escape($pickupChoices[(string) $r['pickup_only']] ?? 'Unknown') . '</td>'
                . '<td><span class="grtrans-pill grtrans-state-' . $this->escape($status) . '">'
                . $this->escape(GrTransportRouteState::STATE_LABELS[$status]) . '</span></td>'
                . '<td><span class="grtrans-pill ' . ($isDemo ? 'grtrans-demo' : 'grtrans-verified') . '">'
                . ($isDemo ? 'Unverified estimate' : 'Operator-confirmed') . '</span></td>';
            $out .= '<td><div class="grtrans-actions">'
                . '<a class="btn btn-default btn-sm" href="' . $action . '&amp;grtrans_edit=' . $id
                . '#grtrans-route-editor">Edit</a>'
                . '<form method="post" action="' . $action . '"><input type="hidden" name="id_route" value="'
                . $id . '"><input type="hidden" name="token" value="' . $csrf . '">'
                . '<button type="submit" class="btn btn-danger btn-sm" name="grtrans_delete" value="1"'
                . ' onclick="return confirm(&quot;Delete route #' . $id
                . '? This cannot be undone.&quot;)">Delete</button></form></div></td></tr>';
        }
        $out .= '</tbody></table></div></div>';

        // Edit intent comes only from the route the user opened, in the URL,
        // and is cryptographically bound to the employee's form submission.
        // No editable route primary-key field exists in either form.
        $editId = isset($_GET['grtrans_edit']) && is_scalar($_GET['grtrans_edit'])
            ? (int) $_GET['grtrans_edit'] : 0;
        $editing = null;
        foreach ($rows as $item) {
            if ($editId > 0 && (int) $item['id_route'] === $editId) {
                $editing = $item;
                break;
            }
        }
        $formAction = $action . ($editing ? '&amp;grtrans_edit=' . $editId : '') . '#grtrans-route-editor';
        $out .= '<section id="grtrans-route-editor" aria-labelledby="grtrans-editor-title" class="panel grtrans-editor'
            . ($editing ? ' is-editing' : '') . '">'
            . '<h3 id="grtrans-editor-title">' . ($editing ? 'Editing route #' . $editId : 'Add route') . '</h3>';
        if ($editing) {
            $out .= '<div class="alert alert-info">Editing route #' . $editId
                . '. Changes affect this route only. <a href="' . $action
                . '#grtrans-route-editor">Cancel editing</a>.</div>';
        }
        if ($saveError !== '') {
            $out .= '<div id="grtrans-form-errors" role="alert" class="alert alert-danger">'
                . '<strong>' . $this->escape($saveError) . '</strong>';
            if ($fieldErrors) {
                $out .= '<ul>';
                foreach ($fieldErrors as $field => $message) {
                    $out .= '<li><a href="#grtrans-field-' . $this->escape($field) . '">'
                        . $this->escape($message) . '</a></li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }
        $out .= '<form method="post" action="' . $formAction . '">'
            . '<input type="hidden" name="token" value="' . $csrf . '">';
        if ($editing) {
            $out .= '<input type="hidden" name="grtrans_edit_proof" value="'
                . $this->escape($this->routeEditProof($editId)) . '">';
        }
        foreach (GrTransportRouteFields::formFields() as $field => $spec) {
            $value = $submitted !== null && array_key_exists($field, $submitted)
                ? (string) $submitted[$field] : (string) ($editing[$field] ?? $spec['default']);
            $hasError = isset($fieldErrors[$field]);
            $fieldId = 'grtrans-field-' . $field;
            $errorId = $fieldId . '-error';
            $helpId = $fieldId . '-help';
            $out .= '<div class="form-group' . ($hasError ? ' has-error' : '') . '">'
                . '<label class="control-label" for="' . $fieldId . '">'
                . $this->escape($this->l($spec['label'])) . '</label>';
            $aria = $hasError ? ' aria-invalid="true" aria-describedby="' . $errorId . '"' : '';
            if ($spec['kind'] === 'select') {
                $choices = GrTransportRouteFields::choices($field);
                $out .= '<select class="form-control" id="' . $fieldId . '" name="' . $field . '"' . $aria . '>';
                // Even an invalid submitted choice is not silently replaced
                // with the first valid option after a failed save.
                if (!array_key_exists($value, $choices)) {
                    $out .= '<option value="' . $this->escape($value)
                        . '" selected disabled>Invalid submitted selection</option>';
                }
                foreach ($choices as $option => $label) {
                    $out .= '<option value="' . $this->escape($option) . '"'
                        . ($value === (string) $option ? ' selected' : '') . '>'
                        . $this->escape($this->l($label)) . '</option>';
                }
                $out .= '</select>';
            } elseif ($spec['kind'] === 'textarea') {
                $out .= '<textarea class="form-control" rows="3" id="' . $fieldId . '" name="'
                    . $field . '" maxlength="' . GrTransportRouteFields::limit($field)
                    . '"' . $aria . '>' . $this->escape($value) . '</textarea>';
            } else {
                $out .= '<input class="form-control" id="' . $fieldId . '" name="' . $field
                    . '" value="' . $this->escape($value) . '" maxlength="'
                    . GrTransportRouteFields::limit($field) . '"' . $aria . '>';
            }
            if (isset($spec['help'])) {
                $out .= '<span class="help-block" id="' . $helpId . '">'
                    . $this->escape($this->l($spec['help'])) . '</span>';
            }
            if ($hasError) {
                $out .= '<span class="help-block text-danger" id="' . $errorId . '">'
                    . $this->escape($fieldErrors[$field]) . '</span>';
            }
            $out .= '</div>';
        }
        if ($editing && empty($editing['demo']) && !empty($editing['verified_at'])) {
            $out .= '<p>Operator-confirmed: ' . $this->escape($editing['verified_at'])
                . ' by employee #' . (int) $editing['verified_by_employee'] . '</p>';
        }
        $out .= '<button type="submit" name="grtrans_save" value="1" class="btn btn-primary">'
            . ($editing ? 'Save route changes' : 'Add route') . '</button></form></section>';
        return $out;
    }

    /** One named-field collection shared by saving and failed-save re-rendering. */
    private function routeInputValues(): array
    {
        $values = [];
        foreach (GrTransportRouteFields::formFields() as $field => $_spec) {
            $values[$field] = Tools::getValue($field);
        }
        return $values;
    }

    /** A stateless, employee-bound edit intent; it never grants edit permission. */
    private function routeEditProof(int $id): string
    {
        return hash_hmac('sha256', 'grtrans-edit:' . $id . ':'
            . (int) ($this->context->employee->id ?? 0), _COOKIE_KEY_);
    }

    private function saveRule(array $values): void
    {
        // The primary key cannot be supplied from the edit form. Only an
        // explicitly opened route (GET + signed, employee-bound intent) can
        // be edited; a submitted replacement ID cannot turn an edit into an
        // update of a different route, or an unintended insertion.
        if (array_key_exists('id_route', $_POST)) {
            throw new InvalidArgumentException('Route ID cannot be changed in the form.');
        }
        $rawId = $_GET['grtrans_edit'] ?? null;
        if ($rawId !== null && (!is_string($rawId) || !ctype_digit($rawId) || (int) $rawId <= 0)) {
            throw new InvalidArgumentException('Invalid route edit target. Reopen the route from the grid.');
        }
        $id = $rawId === null ? 0 : (int) $rawId;
        if ($id > 0) {
            $received = $_POST['grtrans_edit_proof'] ?? '';
            if (!is_string($received) || !hash_equals($this->routeEditProof($id), $received)) {
                throw new InvalidArgumentException('Route edit target changed. Reopen the correct route from the grid.');
            }
        } elseif (isset($_POST['grtrans_edit_proof'])) {
            throw new InvalidArgumentException('Route edit target missing. Reopen the route from the grid.');
        }
        GrTransportRouteRepository::save($values, (int) ($this->context->employee->id ?? 0), $id);
        $this->clearQuoteCache();
    }

}
