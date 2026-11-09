<?php
/**
 * Merchant-operated network defaults. No setting requires a first-run setup:
 * the same fallback is used before install, during upgrade, and if the DB
 * configuration row is missing. Explicit "0" is a valid configured value.
 *
 * API byte quotas, SQL field lengths and the Haversine Earth radius are NOT
 * merchant knobs; they remain bounded implementation/safety constants.
 */
final class GrTransportNetworkSettings
{
    public const DEFINITIONS = [
        'pin_radius_km'=>['key'=>'GRTRANS_PIN_RADIUS_KM','default'=>25.0,'min'=>0.1,'max'=>1000,'label'=>'Customer map-pin matching radius (km)'],
        'station_inner_km'=>['key'=>'GRTRANS_STATION_INNER_KM','default'=>1.0,'min'=>0,'max'=>5000,'label'=>'New station inner radius default (km)'],
        'station_outer_km'=>['key'=>'GRTRANS_STATION_OUTER_KM','default'=>5.0,'min'=>0.1,'max'=>5000,'label'=>'New station outer radius default (km)'],
        'max_coverage_km'=>['key'=>'GRTRANS_MAX_COVERAGE_KM','default'=>500.0,'min'=>1,'max'=>5000,'label'=>'Maximum allowed coverage radius (km)'],
        'nearby_gap_km'=>['key'=>'GRTRANS_NEARBY_GAP_KM','default'=>0.0,'min'=>0,'max'=>100,'label'=>'Extra gap permitted for automatic free walk links (km)'],
        'estimate_low_factor'=>['key'=>'GRTRANS_DEFAULT_ESTIMATE_LOW','default'=>0.9,'min'=>0,'max'=>10,'label'=>'New leg estimate lower factor'],
        'estimate_high_factor'=>['key'=>'GRTRANS_DEFAULT_ESTIMATE_HIGH','default'=>1.1,'min'=>0.01,'max'=>10,'label'=>'New leg estimate upper factor'],
        'quote_ttl_minutes'=>['key'=>'GRTRANS_QUOTE_TTL_MIN','default'=>30.0,'min'=>1,'max'=>1440,'label'=>'Cart shipping quote hold (minutes)'],
        'dimension_to_cm'=>['key'=>'GRTRANS_DIMENSION_TO_CM','default'=>1.0,'min'=>0.0001,'max'=>1000,'label'=>'Product dimension unit multiplier to centimetres'],
        'weight_to_kg'=>['key'=>'GRTRANS_WEIGHT_TO_KG','default'=>1.0,'min'=>0.0001,'max'=>1000,'label'=>'Product weight unit multiplier to kilograms'],
        'show_stale_map'=>['key'=>'GRTRANS_SHOW_STALE_MAP','default'=>1,'min'=>0,'max'=>1,'label'=>'Show stale leg/transfer markings on map (1=on, 0=off)'],
        'stale_after_months'=>['key'=>'GRTRANS_STALE_AFTER_MONTHS','default'=>6,'min'=>1,'max'=>120,'label'=>'Mark leg/transfer data stale after months'],
        'show_delivery_time'=>['key'=>'GRTRANS_SHOW_DELIVERY_TIME','default'=>1,'min'=>0,'max'=>1,'label'=>'Show delivery-time windows (1=on, 0=off)'],
        'max_leg_hours'=>['key'=>'GRTRANS_MAX_LEG_HOURS','default'=>168,'min'=>1,'max'=>2160,'label'=>'Maximum entered hours per leg component'],
        'max_freight_eur'=>['key'=>'GRTRANS_MAX_FREIGHT_EUR','default'=>100000.0,'min'=>1,'max'=>9999999,'label'=>'Maximum single leg/receipt amount (EUR)'],
    ];

    public static function get(string $name): float
    {
        $spec=self::DEFINITIONS[$name] ?? null;
        if (!$spec) throw new InvalidArgumentException('Unknown network setting');
        $default=(float)$spec['default'];
        if (!class_exists('Configuration',false)) return $default;
        $stored=Configuration::get($spec['key']);
        if ($stored===false || $stored===null || $stored==='') return $default;
        if (!is_scalar($stored) || !is_numeric((string)$stored)) return $default;
        $value=(float)$stored;
        return is_finite($value) && $value >= $spec['min'] && $value <= $spec['max']
            ? $value : $default;
    }

    public static function validate(array $raw): array
    {
        $checked=[];
        foreach (self::DEFINITIONS as $name=>$spec) {
            if (!array_key_exists($name,$raw) || !is_scalar($raw[$name])
                || trim((string)$raw[$name])==='' || !is_numeric(str_replace(',','.',(string)$raw[$name]))) {
                throw new InvalidArgumentException('Invalid setting: '.$name);
            }
            $n=(float)str_replace(',','.',trim((string)$raw[$name]));
            if (!is_finite($n) || $n<$spec['min'] || $n>$spec['max']) {
                throw new InvalidArgumentException('Setting out of range: '.$name);
            }
            $checked[$name]=$n;
        }
        if ($checked['station_inner_km'] > $checked['station_outer_km']
            || $checked['station_outer_km'] > $checked['max_coverage_km']) {
            throw new InvalidArgumentException('Station radius defaults must fit within coverage limit');
        }
        if ($checked['estimate_low_factor'] >= $checked['estimate_high_factor']) {
            throw new InvalidArgumentException('Default estimate lower factor must be smaller than upper');
        }
        foreach (['show_delivery_time','show_stale_map'] as $toggle) {
            if (!in_array($checked[$toggle],[0.0,1.0],true)) {
                throw new InvalidArgumentException('Toggle requires 0 or 1: '.$toggle);
            }
        }
        if (floor($checked['stale_after_months']) !== $checked['stale_after_months']) {
            throw new InvalidArgumentException('Stale threshold must be whole months');
        }
        if (floor($checked['quote_ttl_minutes']) !== $checked['quote_ttl_minutes']) {
            throw new InvalidArgumentException('Quote hold requires whole minutes');
        }
        return $checked;
    }

    public static function save(array $raw): void
    {
        $values=self::validate($raw);
        foreach ($values as $name=>$value) {
            if (!Configuration::updateValue(self::DEFINITIONS[$name]['key'],(string)$value)) {
                throw new RuntimeException('Could not save network setting '.$name);
            }
        }
    }

    public static function ensureDefaults(): bool
    {
        foreach (self::DEFINITIONS as $def) {
            $stored=Configuration::get($def['key']);
            if ($stored===false || $stored===null || $stored==='') {
                if (!Configuration::updateValue($def['key'],(string)$def['default'])) return false;
            }
        }
        return true;
    }

    public static function render(string $action,string $csrf): string
    {
        $esc=static function ($value): string {
            return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        };
        $out='<section class="panel" id="grtrans-network-settings"><h3>Transport network operating defaults</h3>'
            . '<p>All fields already have safe, working defaults. Changes apply to new lookups and new records; '
            . 'previously saved stations, quoted carts and placed orders are not silently rewritten.</p>'
            . '<form method="post" action="'.$action.'#grtrans-network-settings">'
            . '<input type="hidden" name="token" value="'.$csrf.'">'
            . '<input type="hidden" name="grtrans_network_action" value="save_operational_settings">';
        foreach (self::DEFINITIONS as $name=>$spec) {
            $out.='<div class="form-group"><label for="grtrans-setting-'.$esc($name).'">'
                . $esc($spec['label']).'</label>'
                . '<input type="number" step="any" class="form-control" id="grtrans-setting-'.$esc($name).'"'
                . ' name="'.$esc($name).'" min="'.$esc($spec['min']).'" max="'.$esc($spec['max']).'"'
                . ' value="'.$esc(self::get($name)).'" required></div>';
        }
        return $out.'<button class="btn btn-primary" type="submit">Save operating defaults</button></form></section>';
    }
}
