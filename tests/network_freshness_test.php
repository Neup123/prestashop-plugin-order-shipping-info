<?php
require_once __DIR__ . '/bootstrap.php';
class Configuration {
    private static $values=[];
    public static function get($key) { return self::$values[$key] ?? false; }
    public static function updateValue($key,$value) { self::$values[$key]=$value; return true; }
}
class Db {
    public static $row=['id_leg'=>5,'verified_at'=>'2025-01-01 00:00:00'];
    public static function getInstance() { return new self(); }
    public function getRow($sql) { return strpos($sql,'id_leg')!==false && strpos($sql,'= 5')!==false ? self::$row : false; }
    public function update($table,$values,$where) {
        if ($table!=='grtrans_leg' || $where!=='id_leg = 5') return false;
        self::$row=array_replace(self::$row,$values);
        return true;
    }
}
require_once __DIR__.'/../grtransportshipping/classes/NetworkFreshness.php';
require_once __DIR__.'/../grtransportshipping/classes/NetworkRepository.php';

$today=new DateTimeImmutable('2026-11-09 12:00:00',new DateTimeZone('UTC'));
testAssert(GrTransportNetworkSettings::get('show_stale_map')===1.0
    && GrTransportNetworkSettings::get('stale_after_months')===6.0,
    'Stale map enabled by default, with six-month threshold');
testAssert(GrTransportNetworkFreshness::stale(null,$today),'Unverified historic record must be visibly stale');
testAssert(GrTransportNetworkFreshness::stale('2026-05-08 11:59:59',$today),
    'Old date outside six calendar months stale');
testAssert(!GrTransportNetworkFreshness::stale('2026-05-09 12:00:00',$today),
    'Exactly six months old is still within threshold');
testAssert(!GrTransportNetworkFreshness::stale('2026-10-20 00:00:00',$today),
    'Recently confirmed leg remains fresh');
$old=['id_leg'=>1,'verified_at'=>'2025-01-01 00:00:00'];
$records=GrTransportNetworkFreshness::mapRecords([$old]);
testAssert($records[0]['_stale']===1,'Stale annotation shown on map');
Configuration::updateValue('GRTRANS_SHOW_STALE_MAP',0);
$off=GrTransportNetworkFreshness::mapRecords([$old]);
testAssert($off[0]===$old,'Disabled stale mode renders no marker but retains original date');
testAssert(GrTransportNetworkFreshness::stale('2025-01-01 00:00:00',$today),
    'Disabling visualization does not delete or alter verification dates');
Configuration::updateValue('GRTRANS_SHOW_STALE_MAP',1);
Configuration::updateValue('GRTRANS_STALE_AFTER_MONTHS',24);
testAssert(!GrTransportNetworkFreshness::stale('2025-01-01 00:00:00',$today),
    'Configurable longer threshold changes stale result');
Configuration::updateValue('GRTRANS_STALE_AFTER_MONTHS',6);
testAssert(GrTransportNetworkFreshness::stale(Db::$row['verified_at']),
    'Old leg starts stale');
GrTransportNetworkRepository::confirm('legs',5);
testAssert(!GrTransportNetworkFreshness::stale(Db::$row['verified_at']),
    'Explicit confirmation refreshes real persisted timestamp');
testAssert(strlen(Db::$row['verified_at'])===19,'Confirmation writes standard UTC timestamp');
try {
    GrTransportNetworkRepository::confirm('stations',5);
    throw new RuntimeException('Station verification must not be permitted');
} catch (InvalidArgumentException $expected) {}
echo "Leg/link verification dates, configurable stale thresholds and map-disable tests passed\n";
