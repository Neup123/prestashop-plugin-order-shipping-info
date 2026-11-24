<?php
require_once __DIR__ . '/bootstrap.php';
define('_DB_PREFIX_', 'ps_');
function pSQL($value, $html = false) { return $value; }
class Configuration { public static function get($key) { return false; } }
class Carrier { public $external_module_name='grtransportshipping'; public $id; public function __construct($id){$this->id=$id;} }
class Order { public $id; public $id_carrier=2; public $id_cart=7; public $id_address_delivery=8; public function __construct($id){$this->id=$id;} public function getCartRules(){return [];} }
class Validate { public static function isLoadedObject($o){return $o->id>0;} }
class PrestaShopLogger { public static $logs=[]; public static function addLog($message,...$args){self::$logs[]=$message;} }
class Db {
    public static $rows=[];
    public static $pin=[];
    public static $race=null;
    public static function getInstance(){return new self();}
    public function getRow($sql,$cache=true){
        if (str_contains($sql,'ps_grtrans_cart_quote')) return self::$pin;
        if (str_contains($sql,'ps_grtrans_order') && preg_match('/id_order = (\d+)/',$sql,$m)) return self::$rows[(int)$m[1]] ?? false;
        if (str_contains($sql,'ps_orders')) return false; // synthetic, no order charge row
        throw new RuntimeException('Unexpected query: '.$sql);
    }
    public function insert($table,$row){if($table!=='grtrans_order')throw new RuntimeException('Unexpected insert');self::$rows[$row['id_order']]=$row;return true;}
    public function update($table,$payload,$where){
        if($table!=='grtrans_order'||!preg_match("/^id_order = (\d+) AND estimate_state = '([^']+)'$/",$where,$m)) throw new RuntimeException('Missing guarded state write');
        $id=(int)$m[1];
        if(self::$race!==null){self::$rows[$id]=array_replace(self::$rows[$id],self::$race);self::$race=null;}
        if(self::$rows[$id]['estimate_state']===$m[2])self::$rows[$id]=array_replace(self::$rows[$id],$payload);
        return true; // platform may return true even for zero affected rows
    }
}
require_once __DIR__ . '/../grtransportshipping/classes/EstimateSettlement.php';
function rejected(callable $f,string $message){try{$f();}catch(RuntimeException $e){testAssert(str_contains($e->getMessage(),$message),'Expected '.$message.'; got '.$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$message);}
$fixed=['mode'=>'bus','station_name'=>'Patras','price'=>12,'pickup_only'=>1,'notice'=>'','demo'=>0];
$estimated=$fixed+['estimate'=>1,'estimate_min'=>8,'estimate_max'=>16];
testAssert(GrTransportEstimateState::columnSql()==="VARCHAR(16) NOT NULL DEFAULT 'not_estimate'",'Schema compatibility');
testAssert(GrTransportOrderSnapshot::status(1)===GrTransportOrderSnapshot::ABSENT,'Initial snapshot absent');
$one=GrTransportOrderSnapshot::capture(1,$fixed);
testAssert($one['estimate_state']===GrTransportEstimateState::NOT_ESTIMATE,'Fixed quote state');
rejected(fn()=>GrTransportEstimateSettlement::recordExternalReceipt(1,12,'REF-1',9),'no pending');
$two=GrTransportOrderSnapshot::capture(2,$estimated);
testAssert($two['estimate_state']===GrTransportEstimateState::PENDING,'Estimated quote state');
testAssert(GrTransportOrderSnapshot::capture(2,$fixed)===$two,'Duplicate order capture immutable');
Db::$pin=['id_address'=>8,'quote_data'=>json_encode($estimated)];
$repair=GrTransportOrderSnapshot::repair(2);
testAssert($repair['estimate_state']===GrTransportEstimateState::PENDING,'Pending original snapshot repair');
testAssert(GrTransportOrderSnapshot::status(2)===GrTransportOrderSnapshot::STORED,'Repair is event, not distinguishable persisted state');
testAssert(str_contains(end(PrestaShopLogger::$logs),'regenerated'),'Repair audit event');
$paid=GrTransportEstimateSettlement::recordExternalReceipt(2,12,'REF-2',9);
testAssert($paid['estimate_state']===GrTransportEstimateState::SETTLED && $paid['route_snapshot']===$two['route_snapshot'],'Settlement preserves frozen snapshot');
testAssert(GrTransportEstimateSettlement::recordExternalReceipt(2,12,'REF-2',9)===$paid,'Matching receipt idempotent');
rejected(fn()=>GrTransportEstimateSettlement::recordExternalReceipt(2,13,'REF-3',9),'duplicate charge rejected');
rejected(fn()=>GrTransportOrderSnapshot::repair(2),'settled freight estimate');
GrTransportOrderSnapshot::capture(3,$estimated);
Db::$race=['estimate_state'=>GrTransportEstimateState::SETTLED,'actual_shipping_charge'=>11,'external_receipt'=>'OTHER'];
rejected(fn()=>GrTransportEstimateSettlement::recordExternalReceipt(3,12,'REF-4',9),'not persisted');
testAssert(Db::$rows[3]['external_receipt']==='OTHER','Concurrent receipt cannot be overwritten or reported as caller success');
GrTransportOrderSnapshot::capture(4,$estimated);
Db::$race=['estimate_state'=>GrTransportEstimateState::SETTLED,'actual_shipping_charge'=>10,'external_receipt'=>'CONCURRENT'];
rejected(fn()=>GrTransportOrderSnapshot::repair(4),'changed concurrently');
testAssert(Db::$rows[4]['estimate_state']===GrTransportEstimateState::SETTLED,'Concurrent settlement blocks snapshot regeneration');
GrTransportOrderSnapshot::capture(5,$fixed);
Db::$rows[5]['estimate_state']='unknown';
rejected(fn()=>GrTransportEstimateSettlement::recordExternalReceipt(5,12,'REF-5',9),'Invalid persisted');
echo "Estimate state contract, persisted transition, duplicate receipt and repair guards passed\n";
