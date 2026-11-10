<?php
/** Merchant-operated defaults. Existing validation and persisted configuration keys are unchanged. */
final class GrTransportNetworkSettings
{
    public const DEFINITIONS = [
        'pin_radius_km'=>['key'=>'GRTRANS_PIN_RADIUS_KM','default'=>25.0,'min'=>0.1,'max'=>1000,'label'=>'Customer map-pin matching radius (km)'],
        'station_inner_km'=>['key'=>'GRTRANS_STATION_INNER_KM','default'=>1.0,'min'=>0,'max'=>5000,'label'=>'New station inner radius default (km)'],
        'station_outer_km'=>['key'=>'GRTRANS_STATION_OUTER_KM','default'=>5.0,'min'=>0.1,'max'=>5000,'label'=>'Global FAR outer boundary default (km; stations and direct areas)'],
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

    /** A NULL/empty stored outer radius inherits the live global FAR default. */
    public static function effectiveOuterKm($override, float $innerKm): float
    {
        $outer=($override===null || $override==='')
            ? self::get('station_outer_km') : (float)$override;
        return max($innerKm,$outer);
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

    /** UI grouping and controls only: the existing 15-key validation contract is unchanged. */
    public static function render(string $action,string $csrf,$module): string
    {
        $esc=static fn($value): string => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $tr=static fn(string $label): string => $esc($module->l($label));
        $labels=[
            'pin_radius_km'=>['Maximum distance for matching a customer pin','km'],
            'station_inner_km'=>['Normal reach for new stations','km'],
            'station_outer_km'=>['Default extended reach','km'],
            'max_coverage_km'=>['Largest allowed service area','km'],
            'nearby_gap_km'=>['Extra distance allowed for a free walking connection','km'],
            'estimate_low_factor'=>['Default minimum estimate','×'],
            'estimate_high_factor'=>['Default maximum estimate','×'],
            'quote_ttl_minutes'=>['How long a cart price stays reserved','min'],
            'dimension_to_cm'=>['Convert product dimensions to centimetres','×'],
            'weight_to_kg'=>['Convert product weights to kilograms','×'],
            'show_stale_map'=>['Mark connections that need review',''],
            'stale_after_months'=>['Review connections after','months'],
            'show_delivery_time'=>['Show estimated delivery time',''],
            'max_leg_hours'=>['Maximum time for a transport step','hours'],
            'max_freight_eur'=>['Maximum allowed transport amount','EUR'],
        ];
        $help=[
            'pin_radius_km'=>'Maximum distance between a customer pin and a catalogue terminal for matching.',
            'station_inner_km'=>'Used when a new station is created; sets its normal service reach.',
            'station_outer_km'=>'Applies wherever a station or direct area inherits extended reach.',
            'max_coverage_km'=>'Limits the service reach values allowed in station and area editors.',
            'nearby_gap_km'=>'Extra distance allowed when suggesting a free walking transfer.',
            'estimate_low_factor'=>'Multiplies a new estimated service base price to set its lower amount.',
            'estimate_high_factor'=>'Multiplies a new estimated service base price to set its upper amount.',
            'quote_ttl_minutes'=>'Keeps a cart quote valid for this number of minutes.',
            'dimension_to_cm'=>'Converts stored product dimensions for volume-based pricing.',
            'weight_to_kg'=>'Converts stored product weights for weight-based pricing.',
            'show_stale_map'=>'Marks connections that have not been checked recently on the admin map.',
            'stale_after_months'=>'A connection needs review when last checked more than this many months ago.',
            'show_delivery_time'=>'Displays available delivery-time estimates in customer offers.',
            'max_leg_hours'=>'Limits the duration allowed for one transport step component.',
            'max_freight_eur'=>'Upper validation limit for freight prices and recorded payment receipts.',
        ];
        $groups=[
            'Coverage'=>['pin_radius_km','station_inner_km','station_outer_km','max_coverage_km','nearby_gap_km'],
            'Estimated prices'=>['estimate_low_factor','estimate_high_factor','max_freight_eur'],
            'Measurements'=>['dimension_to_cm','weight_to_kg'],
            'Price and time limits'=>['quote_ttl_minutes','max_leg_hours'],
            'Review and display'=>['show_stale_map','stale_after_months','show_delivery_time'],
        ];
        $out='<section class="grtrans-settings-panel" id="grtrans-network-settings">'
            .'<div class="grtrans-section-heading"><h3>'.$tr('Transport settings').'</h3></div>'
            .'<p class="grtrans-context-note">'.$tr('Default reach applies only where a station or area has no custom extended reach. Existing orders are unchanged.').'</p>'
            .'<form method="post" class="grtrans-autosave" data-grtrans-autosave'
            .' data-state-saving="'.$tr('Saving…').'" data-state-saved="'.$tr('Saved').'"'
            .' data-state-unsaved="'.$tr('Unsaved changes').'"'
            .' data-state-failed="'.$tr('Could not save. Correct the settings and try again.').'"'
            .' action="'.$action.'#grtrans-network-settings">'
            .'<input type="hidden" name="token" value="'.$csrf.'">'
            .'<input type="hidden" name="grtrans_network_action" value="save_operational_settings">'
            .'<div class="grtrans-settings-grid">';
        foreach ($groups as $group=>$names) {
            $out.='<fieldset class="grtrans-control-group"><legend>'.$tr($group).'</legend>';
            foreach ($names as $name) {
                $spec=self::DEFINITIONS[$name];
                [$label,$unit]=$labels[$name];
                $value=self::get($name);
                $id='grtrans-setting-'.$esc($name);
                $description=$tr($help[$name]);
                $out.='<div class="grtrans-setting-row"><div class="grtrans-setting-label">'
                    .'<label for="'.$id.'">'.$tr($label).'</label>'
                    .'<button type="button" class="grtrans-help-button" title="'.$description.'"'
                    .' aria-label="'.$description.'">?</button></div><div class="grtrans-value-control">';
                if ($name==='show_stale_map' || $name==='show_delivery_time') {
                    $out.='<input type="hidden" name="'.$esc($name).'" value="0">'
                        .'<input type="checkbox" role="switch" class="grtrans-switch" id="'.$id.'" name="'.$esc($name).'" value="1"'
                        .($value===1.0?' checked':'').'>';
                } else {
                    $integer=in_array($name,['quote_ttl_minutes','stale_after_months','max_leg_hours'],true);
                    $step=$integer?'1':'any';
                    $out.='<input type="number" class="form-control grtrans-size-s" id="'.$id.'" name="'.$esc($name).'" step="'.$step.'"'
                        .' min="'.$esc($spec['min']).'" max="'.$esc($spec['max']).'" value="'.$esc($value).'" required>';
                    if ($unit!=='') $out.='<span class="grtrans-unit" aria-hidden="true">'.$esc($unit).'</span>';
                }
                $out.='</div></div>';
            }
            $out.='</fieldset>';
        }
        $out.='</div><div class="grtrans-form-footer"><output class="grtrans-save-state" aria-live="polite" data-grtrans-save-status>'
            .$tr('Saved').'</output><button type="submit" class="btn btn-default grtrans-fallback-submit">'.$tr('Save settings').'</button>'
            .'</div></form></section>';
        return $out;
    }
}
