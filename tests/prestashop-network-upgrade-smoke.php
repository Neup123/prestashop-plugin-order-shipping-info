<?php
/**
 * Disposable PrestaShop 9.2 + MariaDB network migration acceptance (#145).
 * Danger: PREPARE intentionally downgrades schema and registered module version.
 * It is guarded by an explicit CI-only environment variable. Never run on demo.
 * Covers versions 0.11, 0.12, 0.13, 0.15, 0.16 and preserved order data.
 * Also covers preserved geometry and precedence from a representative 0.12 schema.
 * Earlier legacy installations and other DB engines need separate acceptance.
 */
if (getenv('GRTRANS_DISPOSABLE_UPGRADE_TEST') !== '1') {
    throw new RuntimeException('Refusing destructive fixture outside disposable CI');
}
require_once __DIR__ . '/bootstrap.php';
bootPrestaShop();
$db=Db::getInstance();
$module=Module::getInstanceByName('grtransportshipping');
testAssert($module instanceof Grtransportshipping,'Installed transport module required');
$phase=$argv[1] ?? '';
$stateFile='/tmp/grtrans-network-upgrade-fixture.json';
$tables=[
    'station'=>_DB_PREFIX_.'grtrans_station',
    'leg'=>_DB_PREFIX_.'grtrans_leg',
    'transfer'=>_DB_PREFIX_.'grtrans_transfer',
    'branch'=>_DB_PREFIX_.'grtrans_branch',
    'branch_stock'=>_DB_PREFIX_.'grtrans_branch_stock',
];
$columns=static function (string $table) use ($db): array {
    $rows=$db->executeS('SHOW COLUMNS FROM '.$table);
    testAssert(is_array($rows),'Cannot inspect table '.$table);
    return array_column($rows,'Field');
};
$row=static function (string $kind,string $pk,int $id) use ($db,$tables): array {
    $result=$db->getRow('SELECT * FROM '.$tables[$kind].' WHERE '.$pk.'='.(int)$id);
    testAssert(is_array($result),'Missing original merchant '.$kind.' #'.$id);
    return $result;
};
$drop=static function (string $kind,array $names) use ($db,$tables,$columns): void {
    foreach ($names as $name) {
        testAssert(in_array($name,$columns($tables[$kind]),true),
            'Missing current column before rewind: '.$kind.'.'.$name);
        testAssert($db->execute('ALTER TABLE '.$tables[$kind].' DROP COLUMN '.$name),
            'Cannot rewind column '.$kind.'.'.$name);
    }
};
if ($phase==='diagnose') {
    // CI-only diagnostic after an unsuccessful genuine PrestaShop upgrade.
    // Probe individual idempotent migrations in the already failed disposable
    // shop to locate a hidden error (never used as a success substitute).
    $record=$db->getRow("SELECT version,active FROM "._DB_PREFIX_
        ."module WHERE name='grtransportshipping'");
    echo 'FAILED CLI state: '.json_encode($record,JSON_THROW_ON_ERROR)."\n";
    foreach (['station','leg','transfer','branch','branch_stock'] as $entity) {
        echo $entity.' columns: '.implode(',',$columns($tables[$entity]))."\n";
    }
    foreach (['0.11.0','0.12.0','0.13.0','0.15.0','0.16.0'] as $version) {
        require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-'.$version.'.php';
        $handler='upgrade_module_'.str_replace('.','_',$version);
        try {
            $ok=$handler($module);
            echo 'DIRECT DIAGNOSTIC '.$version.': '.($ok?'PASS':'FAILED')."\n";
            if (!$ok) break;
        } catch (Throwable $error) {
            echo 'DIRECT DIAGNOSTIC '.$version.': exception '
                .get_class($error).' - '.$error->getMessage()."\n";
            break;
        }
    }
    exit;
}
if ($phase==='prepare') {
    $hub=GrTransportNetworkRepository::save('stations',[
        'name'=>'CI migration source','type'=>'bus',
        'address_text'=>'Synthetic merchant depot','lat'=>38.0,'lng'=>23.7,
        'inner_radius_km'=>2,'outer_radius_km'=>8,'is_origin_hub'=>1,
    ]);
    $dest=GrTransportNetworkRepository::save('stations',[
        'name'=>'CI migration destination','type'=>'bus',
        'address_text'=>'Synthetic merchant destination','lat'=>38.02,'lng'=>23.75,
        'inner_radius_km'=>1,'outer_radius_km'=>6,'is_origin_hub'=>0,
    ]);
    $leg=GrTransportNetworkRepository::save('legs',[
        'from_station_id'=>$hub,'to_station_id'=>$dest,'mode'=>'bus',
        'operator_label'=>'CI migration tariff','price'=>17.75,'bidirectional'=>0,
    ]);
    $transfer=GrTransportNetworkRepository::save('transfers',[
        'from_station_id'=>$dest,'to_station_id'=>$hub,
        'connection_type'=>'taxi','price'=>2.25,
    ]);
    $branch=GrTransportNetworkRepository::save('branches',[
        'name'=>'CI migration dispatch','origin_station_id'=>$hub,
    ]);
    // Create our own product through the same PrestaShop entity API as the
    // real-order smoke. Earlier tests may remove their own fixtures.
    $idLang=(int)Configuration::get('PS_LANG_DEFAULT');
    $productRecord=new Product();
    $productRecord->name=[$idLang=>'CI migration parcel'];
    $productRecord->link_rewrite=[$idLang=>'ci-migration-parcel'];
    $productRecord->description_short=[$idLang=>'Disposable upgrade acceptance item'];
    $productRecord->id_shop_default=(int)Context::getContext()->shop->id;
    $productRecord->id_category_default=(int)Configuration::get('PS_HOME_CATEGORY');
    $productRecord->id_tax_rules_group=0;
    $productRecord->price=5;
    $productRecord->active=1;
    $productRecord->available_for_order=1;
    $productRecord->visibility='both';
    testAssert($productRecord->add(),'Could not create a disposable product');
    $product=(int)$productRecord->id;
    testAssert($product>0,'Could not resolve new disposable product ID');
    $stock=GrTransportNetworkRepository::save('branch_stock',[
        'id_branch'=>$branch,'id_product'=>$product,
        'id_product_attribute'=>0,'quantity'=>7,
    ]);
    // The order smoke may clean its own purchase records. Seed one isolated
    // historical snapshot in the real module table to test byte-exact
    // preservation without relying on the lifetime of another test's order.
    // This is a synthetic historical record, not a second paid checkout.
    $legacyOrderId=900000001;
    $historical=json_encode([
        'origin'=>'CI original origin','destination'=>'CI original pickup',
        'mode'=>'ferry','price'=>12.75,'legs'=>[
            ['id'=>17,'from'=>'Original hub','to'=>'Original port','price'=>12.75],
        ],
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    testAssert($db->insert('grtrans_order',[
        'id_order'=>$legacyOrderId,
        'mode'=>'ferry','station_name'=>'CI original pickup',
        'price'=>12.75,'pickup_only'=>1,'notice'=>'CI synthetic order snapshot',
        'demo'=>0,'route_snapshot'=>pSQL($historical,true),
        'estimate_state'=>'not_estimate',
    ]),'Cannot create isolated historical snapshot fixture');
    $order=$db->getRow('SELECT id_order,route_snapshot FROM '
        ._DB_PREFIX_.'grtrans_order WHERE id_order='.$legacyOrderId);
    testAssert(is_array($order) && (int)$order['id_order']===$legacyOrderId,
        'Synthetic historical record was not persisted');
    $state=[
        'hub'=>$hub,'dest'=>$dest,'leg'=>$leg,'transfer'=>$transfer,
        'branch'=>$branch,'stock'=>$stock,'product'=>$product,
        'order_id'=>(int)$order['id_order'],
        'snapshot'=>base64_encode((string)$order['route_snapshot']),
    ];
    testAssert(file_put_contents($stateFile,json_encode($state,JSON_THROW_ON_ERROR))!==false,
        'Cannot save disposable fixture metadata');
    // Pre-0.13 station FAR radii were mandatory, so normalize only this
    // disposable copy. Preserve explicit 8km and 6km merchant overrides.
    testAssert($db->execute('UPDATE '.$tables['station']
        .' SET outer_radius_km=5 WHERE outer_radius_km IS NULL'),
        'Cannot simulate old mandatory radius values');
    testAssert($db->execute('ALTER TABLE '.$tables['station']
        .' MODIFY COLUMN outer_radius_km DECIMAL(8,2) NOT NULL DEFAULT 5'),
        'Cannot simulate pre-0.13 non-nullable radius');
    $drop('station',['coverage_kind','coverage_geojson','patch_precedence','active']);
    $drop('leg',['area_coverage_kind','area_coverage_geojson','route_geojson',
        'patch_precedence','override_winner','area_outer_radius_km','active','stop_order']);
    $drop('transfer',['override_winner','active']);
    $drop('branch',['active']);
    $drop('branch_stock',['active']);
    testAssert($db->execute("UPDATE "._DB_PREFIX_."module SET version='0.10.0'"
        ." WHERE name='grtransportshipping'"),'Cannot stage earlier registered version');
    testAssert((string)$db->getValue("SELECT version FROM "._DB_PREFIX_
        ."module WHERE name='grtransportshipping'")==='0.10.0',
        'Stored module version rewind was not applied');
    echo "DISPOSABLE NETWORK UPGRADE prepared: 0.10.0 schema and merchant rows\n";
    exit;
}
if ($phase==='prepare_geometry') {
    // Rewind a second representative historical installation, this time
    // with operator-authored geometry and precedence already present (0.12).
    $state=json_decode((string)file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
    $polygon=json_encode(['type'=>'Polygon','coordinates'=>[[[23.69,37.99],[23.71,37.99],[23.71,38.01],[23.69,38.01],[23.69,37.99]]]],JSON_THROW_ON_ERROR);
    $line=json_encode(['type'=>'LineString','coordinates'=>[[23.7,38.0],[23.725,38.01],[23.75,38.02]]],JSON_THROW_ON_ERROR);
    testAssert($db->update('grtrans_station',['coverage_kind'=>'postal_polygon',
        'coverage_geojson'=>pSQL($polygon,true),'patch_precedence'=>'override'],
        'id_station='.(int)$state['hub']),'Cannot stage historical station polygon');
    testAssert($db->update('grtrans_leg',['route_geojson'=>pSQL($line,true),
        'override_winner'=>'to'],'id_leg='.(int)$state['leg']),'Cannot stage historical route geometry');
    $state['polygon']=$row('station','id_station',(int)$state['hub'])['coverage_geojson'];
    $state['line']=$row('leg','id_leg',(int)$state['leg'])['route_geojson'];
    testAssert($state['polygon']===$polygon && $state['line']===$line,'Historical geometry must be persisted before rewind');
    testAssert(file_put_contents($stateFile,json_encode($state,JSON_THROW_ON_ERROR))!==false,'Cannot save geometry fixture');
    testAssert($db->execute('UPDATE '.$tables['station'].' SET outer_radius_km=5 WHERE outer_radius_km IS NULL'),'Cannot stage old radii');
    testAssert($db->execute('ALTER TABLE '.$tables['station'].' MODIFY COLUMN outer_radius_km DECIMAL(8,2) NOT NULL DEFAULT 5'),'Cannot stage pre-0.13 radius');
    $drop('station',['active']);
    $drop('leg',['area_outer_radius_km','active','stop_order']);
    $drop('transfer',['active']);
    $drop('branch',['active']);
    $drop('branch_stock',['active']);
    testAssert($db->execute("UPDATE "._DB_PREFIX_."module SET version='0.12.0' WHERE name='grtransportshipping'"),'Cannot stage version 0.12.0');
    echo "DISPOSABLE GEOMETRY UPGRADE prepared: 0.12.0 schema and operator geometry\n";
    exit;
}
if ($phase==='verify_geometry') {
    $state=json_decode((string)file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
    testAssert((string)$db->getValue("SELECT version FROM "._DB_PREFIX_."module WHERE name='grtransportshipping'")==='0.16.0','Geometry upgrade must reach current version');
    $hub=$row('station','id_station',(int)$state['hub']);
    $leg=$row('leg','id_leg',(int)$state['leg']);
    testAssert($hub['coverage_kind']==='postal_polygon' && $hub['coverage_geojson']===$state['polygon']
        && $hub['patch_precedence']==='override' && (float)$hub['outer_radius_km']===8.0,
        'Original polygon, precedence and explicit radius survived');
    testAssert($leg['route_geojson']===$state['line'] && $leg['override_winner']==='to'
        && (float)$leg['price']===17.75 && $leg['area_outer_radius_km']===null,
        'Original route line, operator override and tariff survived; new area FAR inherits');
    $new=GrTransportNetworkRepository::save('stations',[
        'name'=>'CI inherited FAR','type'=>'bus','address_text'=>'Synthetic new station',
        'lat'=>38.3,'lng'=>23.9,'inner_radius_km'=>1,'outer_radius_km'=>'','is_origin_hub'=>0,
    ]);
    testAssert($row('station','id_station',$new)['outer_radius_km']===null,'New station inherits global FAR via NULL');
    $order=$db->getRow('SELECT id_order,route_snapshot FROM '._DB_PREFIX_.'grtrans_order WHERE id_order='.(int)$state['order_id']);
    testAssert(base64_encode((string)$order['route_snapshot'])===$state['snapshot'],'Historical order remains byte-identical');
    foreach (['0.13.0','0.15.0','0.16.0'] as $version) {
        require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-'.$version.'.php';
        $handler='upgrade_module_'.str_replace('.','_',$version);
        testAssert($handler($module),'Geometry migration replay failed: '.$version);
    }
    testAssert($row('station','id_station',(int)$state['hub'])['coverage_geojson']===$state['polygon']
        && $row('leg','id_leg',(int)$state['leg'])['route_geojson']===$state['line']
        && $row('station','id_station',$new)['outer_radius_km']===null,'Replays preserve geometry and NULL inheritance');
    echo "REAL GEOMETRY UPGRADE PASS: PrestaShop CLI 0.12-to-0.16, geometry/override/NULL preserved\n";
    exit;
}
if ($phase==='verify') {
    $state=json_decode((string)file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
    testAssert((string)$db->getValue("SELECT version FROM "._DB_PREFIX_
        ."module WHERE name='grtransportshipping'")==='0.16.0',
        'Real PrestaShop module upgrade did not reach 0.16.0');
    $needed=[
        'station'=>['coverage_kind','coverage_geojson','patch_precedence','active'],
        'leg'=>['area_coverage_kind','area_coverage_geojson','route_geojson',
            'patch_precedence','override_winner','area_outer_radius_km','active','stop_order'],
        'transfer'=>['override_winner','active'],
        'branch'=>['active'],'branch_stock'=>['active'],
    ];
    foreach ($needed as $kind=>$names) foreach ($names as $name) {
        testAssert(in_array($name,$columns($tables[$kind]),true),
            'Module upgrade did not add '.$kind.'.'.$name);
    }
    $stationMeta=$db->executeS('SHOW COLUMNS FROM '.$tables['station']);
    $outer=array_values(array_filter($stationMeta,
        static fn($field)=>$field['Field']==='outer_radius_km'));
    testAssert(count($outer)===1 && $outer[0]['Null']==='YES',
        '0.13 FAR override must allow SQL NULL');
    $hub=$row('station','id_station',(int)$state['hub']);
    $dest=$row('station','id_station',(int)$state['dest']);
    testAssert($hub['name']==='CI migration source' && (float)$hub['outer_radius_km']===8.0
        && $dest['name']==='CI migration destination'
        && (float)$dest['outer_radius_km']===6.0,
        'Existing stations and explicit outer radii survived upgrades');
    testAssert($hub['coverage_kind']==='radius' && $hub['patch_precedence']==='none',
        'Legacy station gains safe source geometry and precedence defaults');
    $leg=$row('leg','id_leg',(int)$state['leg']);
    testAssert($leg['operator_label']==='CI migration tariff' && (float)$leg['price']===17.75
        && (int)$leg['from_station_id']===(int)$state['hub']
        && (int)$leg['to_station_id']===(int)$state['dest'],
        'Merchant price and directed link survived');
    testAssert($leg['area_outer_radius_km']===null && $leg['override_winner']==='none'
        && (int)$leg['stop_order']===0,
        'New area FAR, precedence and ordered-leg fields have safe defaults');
    $transfer=$row('transfer','id_transfer',(int)$state['transfer']);
    testAssert((float)$transfer['price']===2.25 && $transfer['override_winner']==='none',
        'Transfer price and safe override default survived');
    $branch=$row('branch','id_branch',(int)$state['branch']);
    $stock=$row('branch_stock','id_branch_stock',(int)$state['stock']);
    testAssert($branch['name']==='CI migration dispatch'
        && (int)$branch['origin_station_id']===(int)$state['hub']
        && (int)$stock['quantity']===7
        && (int)$stock['id_product']===(int)$state['product'],
        'Original branch and stock records survived');
    $order=$db->getRow('SELECT id_order,route_snapshot FROM '
        ._DB_PREFIX_.'grtrans_order WHERE id_order='.(int)$state['order_id']);
    testAssert(is_array($order) && base64_encode((string)$order['route_snapshot'])===$state['snapshot'],
        'Earlier frozen checkout/order snapshot changed during schema migration');
    foreach (['0.11.0','0.12.0','0.13.0','0.15.0','0.16.0'] as $version) {
        require_once '/var/www/html/modules/grtransportshipping/upgrade/upgrade-'.$version.'.php';
        $handler='upgrade_module_'.str_replace('.','_',$version);
        testAssert($handler($module),'Versioned migration replay failed: '.$version);
    }
    testAssert((float)$row('leg','id_leg',(int)$state['leg'])['price']===17.75,
        'Idempotent replays must not reset original merchant tariffs');
    echo "REAL NETWORK UPGRADE PASS: PrestaShop CLI 0.10-to-0.16, merchant/branch/order preserved\n";
    exit;
}
throw new InvalidArgumentException('Usage: upgrade-smoke.php prepare|verify');
