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
require_once __DIR__.'/../grtransportshipping/classes/NetworkFields.php';
$module=new class { public function l(string $label): string { return $label; } };
$defs=GrTransportNetworkSettings::DEFINITIONS;
check(count($defs)===15);
foreach ($defs as $key=>$spec) check(GrTransportNetworkSettings::get($key)===(float)$spec['default']);
$html=GrTransportNetworkSettings::render('/admin/index.php?configure=grtransportshipping','test-token',$module);
check(str_contains($html,'data-grtrans-autosave'));
check(str_contains($html,'data-state-saving="Saving…"'));
check(str_contains($html,'grtrans-fallback-submit'));
check(substr_count($html,'<fieldset class="grtrans-control-group')===5);
check(str_contains($html,'grtrans-page-advanced'));
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
$networkAdmin=file_get_contents(__DIR__.'/../grtransportshipping/classes/NetworkAdmin.php');
check(is_string($networkAdmin),'NetworkAdmin source must be readable');
check(str_contains($networkAdmin,'$collection=$entity;'),'Active dataset only in server HTML');
check(!str_contains($networkAdmin,'foreach (GrTransportNetworkFields::ENTITIES as $collection)'));
check(str_contains($networkAdmin,'<section id="grtrans-network-list" class="grtrans-network-list"'));
check(str_contains($networkAdmin,'data-grtrans-select-record'));
check(str_contains($networkAdmin,'data-grtrans-row-action="delete"'));
check(str_contains($networkAdmin,'data-grtrans-network-state'));
check(str_contains($networkAdmin,'aria-current="page"'));
check(str_contains($networkAdmin,'data-grtrans-server-groups'));
check(str_contains($networkAdmin,'grtrans-editor-fields'));
check(str_contains($networkAdmin,'data-grtrans-product-picker'));
check(str_contains($networkAdmin,'grtrans-product-catalog'));
check(str_contains($networkAdmin,'grtrans-clear-far'));
check(str_contains($networkAdmin,'grtrans-map-help'));
check(!str_contains($networkAdmin,'<details class="grtrans-network-advanced"'));
$transferFields=GrTransportNetworkFields::fields('transfers');
check($transferFields['connection_type'][0]==='choice');
check(in_array('walk',$transferFields['connection_type'][1],true));
check(in_array('taxi',$transferFields['connection_type'][1],true));
$transfer=GrTransportNetworkFields::defaults('transfers');
$transfer['from_station_id']=1;$transfer['to_station_id']=2;
check(GrTransportNetworkFields::normalize('transfers',$transfer)['connection_type']==='walk');
$transfer['connection_type']='taxi';
check(GrTransportNetworkFields::normalize('transfers',$transfer)['connection_type']==='taxi');
try {
    $transfer['connection_type']='unsupported-transport';
    GrTransportNetworkFields::normalize('transfers',$transfer);
    throw new RuntimeException('Unknown transfer type must be rejected');
} catch (InvalidArgumentException $error) { check(str_contains($error->getMessage(),'connection_type')); }
$routeSource=file_get_contents(__DIR__.'/../grtransportshipping/grtransportshipping.php');
check(str_contains($routeSource,'grtrans-page-advanced-toggle'));
$simulator=file_get_contents(__DIR__.'/../grtransportshipping/classes/NetworkSimulator.php');
check(str_contains($simulator,'data-sim-method="address"') && str_contains($simulator,'location_on'));
check(str_contains($simulator,'data-sim-method="station"') && str_contains($simulator,'place'));
echo "Configure UI settings validation and markup passed\n";
