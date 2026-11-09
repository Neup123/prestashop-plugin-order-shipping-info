<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/../../classes/QuoteCheck.php';

/** Public read-only quote check; no cart/address/quote pins are created. */
class GrtransportshippingQuoteModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;

    public function setMedia()
    {
        parent::setMedia();
        $base = 'modules/' . $this->module->name . '/views/';
        $this->registerStylesheet('grtransport-leaflet', $base . 'vendor/leaflet/leaflet.css', ['media' => 'all', 'priority' => 100]);
        $this->registerStylesheet('grtransport-quote', $base . 'css/quote.css', ['media' => 'all', 'priority' => 110]);
        $this->registerJavascript('grtransport-leaflet', $base . 'vendor/leaflet/leaflet.js', ['position' => 'bottom', 'priority' => 100]);
        $this->registerJavascript('grtransport-quote', $base . 'js/quote.js', ['position' => 'bottom', 'priority' => 110]);
    }

    public function initContent()
    {
        parent::initContent();
        $input = Tools::getValue('destination', '');
        $input = is_string($input) ? trim($input) : '';
        $isPin = Tools::getValue('pin') === '1';
        $submitted = $isPin || $input !== '';
        $result = $submitted ? $this->module->customerQuoteCheck(
            $input, $isPin ? [Tools::getValue('lat'), Tools::getValue('lng')] : null
        ) : null;
        $quote = $result['quote'] ?? null;
        // The map receives only the customer's own selected coordinates.
        // The pricing adapter still resolves the pin against the existing catalogue.
        $lat = Tools::getValue('lat', '');
        $lng = Tools::getValue('lng', '');
        $validPin = $isPin && is_scalar($lat) && is_scalar($lng)
            && is_numeric($lat) && is_numeric($lng)
            && is_finite((float)$lat) && is_finite((float)$lng)
            && abs((float)$lat) <= 90 && abs((float)$lng) <= 180;
        $map = [
            'zoomIn' => $this->module->l('Zoom in', 'quote'),
            'zoomOut' => $this->module->l('Zoom out', 'quote'),
            'marker' => $this->module->l('Destination pin', 'quote'),
            'attribution' => $this->module->l('© OpenStreetMap contributors', 'quote'),
            'leafletImagePath' => _MODULE_DIR_ . $this->module->name . '/views/vendor/leaflet/images/',
        ];
        if ($validPin) {
            $map['pin'] = [(float)$lat, (float)$lng];
        }
        // Notes are display-only context for this quote, never pricing inputs
        // or an order field. Escape at the Smarty output boundary.
        $note = Tools::getValue('note', '');
        $note = is_string($note) ? trim($note) : '';
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 500, 'UTF-8');
        } else {
            $note = substr($note, 0, 500);
        }
        $this->context->smarty->assign([
            'quote_action' => $this->context->link->getModuleLink($this->module->name, 'quote'),
            'quote_destination' => $input,
            'quote_is_pin' => $isPin,
            'quote_lat' => $validPin ? (string)$lat : '',
            'quote_lng' => $validPin ? (string)$lng : '',
            'quote_note' => $note,
            'quote_submitted' => $submitted,
            'quote_route' => $quote,
            'quote_far_required' => $quote && !empty($quote['extended']),
            // Public check is a preview, not an order. Require two explicit
            // POST inputs before showing the far-zone option as accepted.
            'quote_far_confirmed' => $quote && !empty($quote['extended'])
                && Tools::getValue('far_accept','') === '1'
                && Tools::getValue('far_ack','') === '1',
            'quote_carrier' => $result['carrier'] ?? '',
            'quote_price' => $quote ? (!empty($quote['estimate'])
                ? $this->context->currentLocale->formatPrice((float)$quote['estimate_min'], $this->context->currency->iso_code)
                    . ' – ' . $this->context->currentLocale->formatPrice((float)$quote['estimate_max'], $this->context->currency->iso_code)
                : $this->context->currentLocale->formatPrice(
                    GrTransportOrderCharge::expectedShipping($quote), $this->context->currency->iso_code
                )) : '',
            'quote_notice' => $quote ? $this->module->customerQuoteNotice($quote) : '',
            'quote_message' => $result ? $this->module->customerQuoteMessage($result['status']) : '',
            'quote_map' => json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
        $this->setTemplate('module:grtransportshipping/views/templates/front/quote.tpl');
    }
}
