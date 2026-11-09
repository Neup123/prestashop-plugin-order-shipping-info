<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Same-origin, authenticated-cart far-zone consent AJAX endpoint. */
class GrtransportshippingFarconfirmModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;
    public $ajax = true;

    public function displayAjax()
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                throw new InvalidArgumentException('POST required');
            }
            $cart=$this->context->cart ?? null;
            if (!$cart || (int)($cart->id ?? 0) <= 0) {
                throw new InvalidArgumentException('An active checkout cart is required');
            }
            $token=Tools::getValue('far_token','');
            if (!is_string($token)) throw new InvalidArgumentException('Invalid token');
            $confirm=Tools::getValue('far_confirm','') === '1';
            $accepted=$this->module->confirmFarZoneForCart($cart,$token,$confirm);
            $this->ajaxRender(json_encode(['ok'=>true,'confirmed'=>$accepted],
                JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            if (function_exists('http_response_code')) http_response_code(400);
            $this->ajaxRender(json_encode(['ok'=>false,'confirmed'=>false,
                'error'=>'Far-zone confirmation was not saved; please try again.'],
                JSON_UNESCAPED_SLASHES));
        }
    }
}
