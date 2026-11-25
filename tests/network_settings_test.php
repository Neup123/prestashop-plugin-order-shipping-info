<?php
require_once __DIR__ . '/bootstrap.php';
class Configuration {
    public static $values=[];
    public static $failOnceKey=null;
    public static $throwOnceKey=null;
    public static $failRestoreKey=null;
    public static $failRestoreValue=null;
    public static function get($key) { return self::$values[$key] ?? false; }
    public static function updateValue($key,$value) {
        if (self::$failRestoreKey===$key && self::$failRestoreValue===(string)$value) return false;
        // Inject failure after the underlying configuration has changed.
        self::$values[$key]=$value;
        if (self::$failOnceKey===$key) {
            self::$failOnceKey=null;
            return false;
        }
        if (self::$throwOnceKey===$key) {
            self::$throwOnceKey=null;
            throw new RuntimeException('Injected configuration I/O exception');
        }
        return true;
    }
}
require_once __DIR__ . '/../grtransportshipping/classes/NetworkSettings.php';
require_once __DIR__ . '/../grtransportshipping/classes/QuoteCheck.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkFields.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkGraph.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkTariff.php';
require_once __DIR__ . '/../grtransportshipping/classes/CartQuote.php';

$settings=[];
foreach (GrTransportNetworkSettings::DEFINITIONS as $name=>$definition) {
    testAssert(GrTransportNetworkSettings::get($name)===(float)$definition['default'],
        'All network values must be usable before first configuration: '.$name);
    $settings[$name]=$definition['default'];
}
testAssert(GrTransportQuoteCheck::pinRadiusKm()===25.0,'Default public map radius must be 25 km');
testAssert(GrTransportCartQuote::ttlSeconds()===1800,'Existing 30-minute cart hold preserved');

$routes=require __DIR__.'/../grtransportshipping/demo/routes.php';
foreach ($routes as $id=>&$route) $route['id_route']=$id+1;
unset($route);
$stations=GrTransportQuoteCheck::stations();
$naxos=$stations[3];
$north=static function (float $km) use ($naxos): float {
    return $naxos['lat']+rad2deg($km/6371.0088);
};
testAssert(GrTransportQuoteCheck::pin($routes,$north(24.999),$naxos['lng'])['quote']!==null,
    'Original inclusive 25 km default still matches');
testAssert(GrTransportQuoteCheck::pin($routes,$north(25.001),$naxos['lng'])['quote']===null,
    'Default radius excludes slightly-outside point');

$settings['pin_radius_km']=10;
$settings['station_inner_km']=2;
$settings['station_outer_km']=6;
$settings['estimate_low_factor']=0.75;
$settings['estimate_high_factor']=1.25;
$settings['quote_ttl_minutes']=45;
$settings['nearby_gap_km']=1.5;
$settings['weight_to_kg']=0.001;
$settings['dimension_to_cm']=2.54;
GrTransportNetworkSettings::save($settings);
testAssert(GrTransportQuoteCheck::pinRadiusKm()===10.0,'Changed radius read from configuration');
testAssert(GrTransportQuoteCheck::pin($routes,$north(9.999),$naxos['lng'])['quote']!==null,
    'Configured radius accepts just-inside point');
testAssert(GrTransportQuoteCheck::pin($routes,$north(10.001),$naxos['lng'])['quote']===null,
    'Configured radius rejects outside point');
testAssert(GrTransportNetworkSettings::get('nearby_gap_km')===1.5,'Nearby gap persisted');
testAssert(GrTransportNetworkFields::defaults('stations')['inner_radius_km']===2.0
    && GrTransportNetworkFields::defaults('stations')['outer_radius_km']===null,
    'New station stores no FAR override and inherits global');
testAssert(GrTransportNetworkSettings::effectiveOuterKm(null,2)===6.0,
    'Runtime default governs new station FAR boundary');
testAssert(GrTransportNetworkFields::defaults('legs')['estimate_low_factor']===0.75
    && GrTransportNetworkFields::defaults('legs')['estimate_high_factor']===1.25,
    'New estimate factor defaults are configurable');
testAssert(GrTransportCartQuote::ttlSeconds()===2700,'Configured quote hold changes expiration');
$cart=new class { public function getProducts(){return [[
    'weight'=>1500,'width'=>10,'height'=>10,'depth'=>10,'cart_quantity'=>1,
]];} };
$parcel=GrTransportNetworkTariff::cartMeasurements($cart);
testAssert($parcel['weight_kg']===1.5 && abs($parcel['volume_m3']-0.0163871)<0.0000001,
    'Shop weight/dimension unit multipliers must affect measured tariffs');
$old=Configuration::$values['GRTRANS_PIN_RADIUS_KM'];
testAssert(GrTransportNetworkSettings::ensureDefaults(),'Idempotent configuration initialization');
testAssert(Configuration::$values['GRTRANS_PIN_RADIUS_KM']===$old,
    'Upgrades must never overwrite an operator-configured radius');

$reject=function ($patch) use ($settings) {
    try { GrTransportNetworkSettings::save(array_replace($settings,$patch)); }
    catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Unsafe cross-field/default configuration unexpectedly accepted');
};
$reject(['pin_radius_km'=>0]);
$reject(['station_inner_km'=>7,'station_outer_km'=>6]);
$reject(['estimate_low_factor'=>1.5,'estimate_high_factor'=>1.4]);
$reject(['quote_ttl_minutes'=>1.5]);
$reject(['max_coverage_km'=>4]);

// #112: inherited live defaults and explicit overrides on both patch kinds.
$station=['id_station'=>77,'lat'=>38,'lng'=>23.7,'inner_radius_km'=>2,'outer_radius_km'=>null];
$area=['id_leg'=>99,'from_station_id'=>77,'to_station_id'=>null,
    'area_lat'=>38,'area_lng'=>23.7,'area_radius_km'=>2,'area_outer_radius_km'=>null];
$north=static fn(float $km) => 38+rad2deg($km/6371.0088);
testAssert(GrTransportNetworkGraph::candidates([$station],$north(4),23.7)[0]['ring']==='outer',
    'Station inherits configured global 6 km FAR boundary');
testAssert(GrTransportNetworkGraph::directAreas([$area],$north(4),23.7)[0]['_ring']==='outer',
    'Area inherits same global FAR boundary');
$settings['station_outer_km']=3;
GrTransportNetworkSettings::save($settings);
testAssert(GrTransportNetworkGraph::candidates([$station],$north(4),23.7)===[]
    && GrTransportNetworkGraph::directAreas([$area],$north(4),23.7)===[],
    'Global FAR change updates inherited station and area reach at lookup time');
$station['outer_radius_km']=8;
$area['area_outer_radius_km']=8;
testAssert(GrTransportNetworkGraph::candidates([$station],$north(7),23.7)[0]['ring']==='outer'
    && GrTransportNetworkGraph::directAreas([$area],$north(7),23.7)[0]['_ring']==='outer',
    'Per-place explicit overrides beat global setting');
testAssert(GrTransportNetworkSettings::effectiveOuterKm(null,4)===4.0,
    'Inherited FAR extent cannot remove normal reach');

// #138: a failure on any of the 15 independent writes must restore the
// previously saved group, including when a failing call already mutated.
$baseline=Configuration::$values;
$changed=$settings;
$changed['pin_radius_km']=15;
$changed['station_outer_km']=7;
$changed['max_coverage_km']=800;
$changed['max_freight_eur']=88000;
$assertRestored=static function (string $label) use ($baseline): void {
    testAssert(Configuration::$values===$baseline,$label.' restores all original settings');
};
foreach (['GRTRANS_PIN_RADIUS_KM','GRTRANS_STATION_OUTER_KM','GRTRANS_MAX_FREIGHT_EUR'] as $failureKey) {
    Configuration::$failOnceKey=$failureKey;
    try {
        GrTransportNetworkSettings::save($changed);
        throw new RuntimeException('Expected injected failure did not reach save');
    } catch (RuntimeException $e) {
        testAssert(str_contains($e->getMessage(),'previous values restored'),
            'Failed write acknowledges rollback: '.$failureKey);
    }
    $assertRestored('Failed '.$failureKey);
}
Configuration::$throwOnceKey='GRTRANS_STATION_OUTER_KM';
try {
    GrTransportNetworkSettings::save($changed);
    throw new RuntimeException('Expected injected exception');
} catch (RuntimeException $e) {
    testAssert(str_contains($e->getMessage(),'previous values restored'),
        'Thrown write must compensate previous successful writes');
}
$assertRestored('Thrown write');
// A compensation failure is an explicitly reported partial state, not success.
Configuration::$failOnceKey='GRTRANS_STATION_OUTER_KM';
Configuration::$failRestoreKey='GRTRANS_PIN_RADIUS_KM';
Configuration::$failRestoreValue=(string)$baseline['GRTRANS_PIN_RADIUS_KM'];
try {
    GrTransportNetworkSettings::save($changed);
    throw new RuntimeException('Expected injected rollback failure');
} catch (RuntimeException $e) {
    testAssert(str_contains($e->getMessage(),'rollback incomplete')
        && str_contains($e->getMessage(),'pin_radius_km')
        && str_contains($e->getMessage(),'Reload Configure'),
        'Partial rollback surfaces exact field and safe recovery');
}
Configuration::$failRestoreKey=null;
Configuration::$failRestoreValue=null;
Configuration::$values=$baseline;
unset(Configuration::$values['GRTRANS_PIN_RADIUS_KM']);
Configuration::$failOnceKey='GRTRANS_STATION_OUTER_KM';
try {
    GrTransportNetworkSettings::save($changed);
    throw new RuntimeException('Expected failure with missing legacy key');
} catch (RuntimeException $e) {
    testAssert(str_contains($e->getMessage(),'previous values restored'),
        'Absent key is restored to the effective default without global delete');
}
testAssert(GrTransportNetworkSettings::get('pin_radius_km')===25.0,
    'Absent setting still has effective safe default after failed update');
Configuration::$values=$baseline;
echo "Operational defaults, checked rollback and runtime FAR radius tests passed\n";


 // #141: exercise persisted rows, not only the configuration accessor.
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_', 'ps_');
class Db {
    public static $row;
    public static $where;
    public static function getInstance() { return new self(); }
    public function getRow($sql) { return self::$row; }
    public function delete($table, $where, $limit, $cache) {
        self::$where = $where;
        return true;
    }
}
foreach ([10, 30, 45] as $minutes) {
    $settings['quote_ttl_minutes'] = $minutes;
    GrTransportNetworkSettings::save($settings);
    $now = time();
    Db::$row = ['id_address'=>7, 'address_fingerprint'=>'fixture',
        'quote_status'=>GrTransportCartQuote::PINNED, 'quote_data'=>'{"price":12}',
        'created_at'=>gmdate('Y-m-d H:i:s', $now - $minutes * 60 + 10)];
    testAssert(GrTransportCartQuote::pinned(1, 7, 'fixture') === ['price'=>12],
        'Fresh persisted quote must respect configured TTL: '.$minutes);
    Db::$row['created_at'] = gmdate('Y-m-d H:i:s', $now - $minutes * 60 - 10);
    testAssert(GrTransportCartQuote::pinned(1, 7, 'fixture') === null,
        'Expired persisted quote must respect configured TTL: '.$minutes);
    GrTransportCartQuote::pruneExpired();
    testAssert(preg_match("/^quote_status = 'pinned' AND created_at < '([^']+)'$/", Db::$where, $match) === 1,
        'Pruning must only target unconsumed quotes');
    $cutoff = strtotime($match[1].' UTC');
    testAssert($cutoff >= $now - $minutes * 60 && $cutoff <= time() - $minutes * 60,
        'Pruning cutoff must respect configured TTL: '.$minutes);
    Db::$row['quote_status'] = GrTransportCartQuote::CONSUMED;
    testAssert(GrTransportCartQuote::pinned(1, 7, 'fixture') === ['price'=>12],
        'Consumed quote must remain readable after expiry');
}
