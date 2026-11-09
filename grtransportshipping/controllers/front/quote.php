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
        $stations = $quote ? GrTransportQuoteCheck::routeStations($quote) : [];
        $map = [
            'stations' => $stations,
            'zoomIn' => $this->module->l('Zoom in', 'quote'),
            'zoomOut' => $this->module->l('Zoom out', 'quote'),
            'marker' => $this->module->l('Destination pin', 'quote'),
            'attribution' => $this->module->l('© OpenStreetMap contributors', 'quote'),
        ];
        $station = $result['station'] ?? null;
        if ($station) {
            $map['pin'] = [$station['lat'], $station['lng']];
        }
        $this->context->smarty->assign([
            'quote_action' => $this->context->link->getModuleLink($this->module->name, 'quote'),
            'quote_destination' => $input,
            'quote_submitted' => $submitted,
            'quote_route' => $quote,
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
