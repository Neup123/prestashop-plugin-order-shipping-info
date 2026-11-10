<?php
/** Pure PHP regression for the Configure settings presentation and unchanged validation. */
function check(bool $ok,string $message='UI assertion failed'): void { if (!$ok) throw new RuntimeException($message); }
class Configuration
{
    private static array $values=[];
    public static function get(string $key) { return self::$values[$key] ?? false; }
    public static function updateValue(string $key,string $value): bool { self::$values[$key]=$value; return true; }
}
require_once __DIR__.'/../grtransportshipping/classes/NetworkSettings.php';
$module=new class { public function l(string $label): string { return $label; } };
$defs=GrTransportNetworkSettings::DEFINITIONS;
check(count($defs)===15);
foreach ($defs as $key=>$spec) check(GrTransportNetworkSettings::get($key)===(float)$spec['default']);
$html=GrTransportNetworkSettings::render('/admin/index.php?configure=grtransportshipping','test-token',$module);
check(str_contains($html,'data-grtrans-autosave'));
check(str_contains($html,'data-state-saving="Saving…"'));
check(str_contains($html,'grtrans-fallback-submit'));
check(substr_count($html,'<fieldset class="grtrans-control-group"')===5);
foreach (array_keys($defs) as $key) check(str_contains($html,'name="'.$key.'"'),$key);
foreach (['show_stale_map','show_delivery_time'] as $key) {
    check(str_contains($html,'role="switch" class="grtrans-switch" id="grtrans-setting-'.$key.'"'));
}
foreach (['quote_ttl_minutes','stale_after_months','max_leg_hours'] as $key) {
    check(str_contains($html,'name="'.$key.'" step="1"'));
}
$values=array_map(static fn($spec)=>$spec['default'],$defs);
GrTransportNetworkSettings::save($values);
check(GrTransportNetworkSettings::effectiveOuterKm(null,1)===5.0);
try { GrTransportNetworkSettings::validate(array_replace($values,['station_inner_km'=>10,'station_outer_km'=>5])); throw new RuntimeException('Expected rejection'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(),'radius')); }
try { GrTransportNetworkSettings::validate(array_replace($values,['show_stale_map'=>0.5])); throw new RuntimeException('Expected rejection'); }
catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(),'Toggle')); }
echo "Configure UI settings validation and markup passed\n";
