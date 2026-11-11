<?php
require_once __DIR__.'/../grtransportshipping/classes/NetworkCatalogOptions.php';
function ensure(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$data=GrTransportNetworkCatalogOptions::fromRows(
    [['id_product'=>21,'name'=>'Olive oil'],['id_product'=>42,'name'=>'Tahini']],
    [['id_product'=>21,'id_product_attribute'=>7,'name'=>'1 litre'],
     ['id_product'=>99,'id_product_attribute'=>2,'name'=>'Not in shop'],
     ['id_product'=>42,'id_product_attribute'=>9,'name'=>'']]
);
ensure(count($data['products'])===2,'Product list');
ensure($data['products'][0]['id']===21,'Product ID preserved');
ensure(count($data['variants'])===2,'Foreign variant suppressed');
ensure($data['variants'][0]['name']==='1 litre','Human variant label preserved');
ensure($data['variants'][1]['name']==='Variant #9','Fallback label');
echo "Read-only catalog option projection passed\n";
