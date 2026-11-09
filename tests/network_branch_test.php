<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/BranchAvailability.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkQuote.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkSimulator.php';

class BranchCart
{
    private $lines;
    public function __construct(array $lines) { $this->lines=$lines; }
    public function getProducts(): array { return $this->lines; }
}
$branches=[
 ['id_branch'=>11,'name'=>'Athens branch','origin_station_id'=>1],
 ['id_branch'=>22,'name'=>'Thessaloniki branch','origin_station_id'=>2],
];
$stock=[
 ['id_branch'=>11,'id_product'=>100,'id_product_attribute'=>0,'quantity'=>5],
 ['id_branch'=>11,'id_product'=>200,'id_product_attribute'=>5,'quantity'=>0],
 ['id_branch'=>22,'id_product'=>100,'id_product_attribute'=>0,'quantity'=>0],
 ['id_branch'=>22,'id_product'=>200,'id_product_attribute'=>5,'quantity'=>4],
];
$a=new BranchCart([['id_product'=>100,'id_product_attribute'=>0,'cart_quantity'=>1]]);
$b=new BranchCart([['id_product'=>200,'id_product_attribute'=>5,'cart_quantity'=>2]]);
$both=new BranchCart([
 ['id_product'=>100,'id_product_attribute'=>0,'cart_quantity'=>1],
 ['id_product'=>200,'id_product_attribute'=>5,'cart_quantity'=>1],
]);
testAssert(GrTransportBranchAvailability::eligible($a,[],[])===null,
    'No branches must preserve old availability-blind hub behavior');
$eligA=GrTransportBranchAvailability::eligible($a,$branches,$stock);
$eligB=GrTransportBranchAvailability::eligible($b,$branches,$stock);
$eligBoth=GrTransportBranchAvailability::eligible($both,$branches,$stock);
testAssert($eligA['origin_ids']===[1] && $eligA['branch_ids']===[11],
    'Product A must originate only at Athens branch');
testAssert($eligB['origin_ids']===[2] && $eligB['branch_ids']===[22],
    'Specific product combination must originate only at Thessaloniki branch');
testAssert($eligBoth['origin_ids']===[],'No split shipments when no single branch has whole cart');
testAssert(GrTransportBranchAvailability::eligible(new BranchCart([
 ['id_product'=>100,'id_product_attribute'=>0,'cart_quantity'=>3],
 ['id_product'=>100,'id_product_attribute'=>0,'cart_quantity'=>3],
]),$branches,$stock)['origin_ids']===[],'Repeated same cart item quantities must aggregate');
testAssert(GrTransportBranchAvailability::eligible(new BranchCart([
 ['id_product'=>200,'id_product_attribute'=>0,'cart_quantity'=>1],
]),$branches,$stock)['origin_ids']===[],'Wrong combination must not inherit stock');
testAssert(GrTransportBranchAvailability::eligible(new BranchCart([
 ['id_product'=>200,'id_product_attribute'=>5,'cart_quantity'=>5],
]),$branches,$stock)['origin_ids']===[],'Insufficient branch quantity must fail closed');

$stations=[
 ['id_station'=>1,'name'=>'Athens hub','type'=>'bus','lat'=>38,'lng'=>23.7,
    'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>1],
 ['id_station'=>2,'name'=>'Thessaloniki hub','type'=>'bus','lat'=>40.64,'lng'=>22.9,
    'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>1],
 ['id_station'=>3,'name'=>'Port','type'=>'ferry','lat'=>37.95,'lng'=>23.64,
    'inner_radius_km'=>1,'outer_radius_km'=>5,'is_origin_hub'=>0],
];
$legs=[
 ['id_leg'=>1,'from_station_id'=>1,'to_station_id'=>3,'mode'=>'bus',
  'operator_label'=>'Athens ferry freight','price'=>20,'bidirectional'=>0],
 ['id_leg'=>2,'from_station_id'=>2,'to_station_id'=>3,'mode'=>'bus',
  'operator_label'=>'Thessaloniki freight','price'=>10,'bidirectional'=>0],
];
$network=['stations'=>$stations,'legs'=>$legs,'transfers'=>[],
    'branches'=>$branches,'branch_stock'=>$stock];
$dest=[37.95,23.64];
$quoteFor=static function ($elig) use ($network,$dest) {
    return GrTransportNetworkQuote::resolve([],$network,'','',$dest,
        $elig['origin_ids'],'',[],$elig['by_origin']);
};
$qa=$quoteFor($eligA)['quote'];
$qb=$quoteFor($eligB)['quote'];
testAssert($qa['price']===20.0 && $qa['branch_id']===11
    && $qa['origin_hub_id']===1 && $qa['branch_name']==='Athens branch',
    'Cart A must use its eligible Athens hub even when a cheaper stockless route exists');
testAssert($qb['price']===10.0 && $qb['branch_id']===22
    && $qb['origin_hub_id']===2,'Cart B chooses its actual origin hub');
testAssert($quoteFor($eligBoth)['quote']===null,
    'No single fully stocked branch must not produce network quote');
$blind=GrTransportNetworkQuote::resolve([],$network,'','',$dest);
testAssert($blind['quote']['price']===10.0 && !isset($blind['quote']['branch_id']),
    'No branch restriction parameter preserves original graph behavior');
$sim=GrTransportNetworkSimulator::simulate([],$network,['station_id'=>3,'cart'=>$a]);
testAssert($sim['quote']['price']===$qa['price'] && $sim['quote']['branch_id']===11,
    'Cart-aware simulator and checkout resolver have identical origin selection');
$emptySim=GrTransportNetworkSimulator::simulate([],$network,['station_id'=>3]);
testAssert($emptySim['quote']===null,'Branch-specific simulator cannot fake a stock-blind quote');
$legacy=[[
 'id_route'=>9,'route_status'=>'available','postal_prefix'=>'185',
 'city'=>'','mode'=>'ferry','origin_name'=>'Manual','station_name'=>'Manual pickup',
 'price'=>19,'pickup_only'=>1,'notice'=>'','demo'=>0,
]];
$fallback=GrTransportNetworkQuote::resolve($legacy,$network,'18500','Piraeus',$dest,[], '',[],[]);
testAssert($fallback['quote']['price']===19,
    'Unavailable branch network must not suppress an explicit flat route fallback');
$branch=GrTransportNetworkFields::normalize('branches',['name'=>'Store A','origin_station_id'=>1]);
$one=GrTransportNetworkFields::normalize('branch_stock',[
    'id_branch'=>11,'id_product'=>100,'id_product_attribute'=>0,'quantity'=>0,
]);
testAssert($branch['name']==='Store A' && $one['quantity']===0,
    'Unfilled branch stock quantity zero is supported but never eligible');
try {
    GrTransportNetworkFields::normalize('branch_stock',[
        'id_branch'=>11,'id_product'=>100,'id_product_attribute'=>0,'quantity'=>-1,
    ]);
    throw new RuntimeException('Negative stock accepted');
} catch (InvalidArgumentException $expected) {}
echo "Branch origins, product-combination stock, no splits and quote/simulator parity passed\n";
