<?php
require_once __DIR__ . '/NetworkFields.php';

/** One checked persistence path for administration, API and bulk imports. */
final class GrTransportNetworkRepository
{
    public static function installSchema(): bool
    {
        $db=Db::getInstance();
        $engine=_MYSQL_ENGINE_;
        $prefix=_DB_PREFIX_;
        return $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_station` (
            `id_station` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(190) NOT NULL, `type` VARCHAR(16) NOT NULL,
            `address_text` VARCHAR(500) NOT NULL,
            `lat` DECIMAL(10,7) NOT NULL, `lng` DECIMAL(10,7) NOT NULL,
            `inner_radius_km` DECIMAL(8,2) NOT NULL,
            `outer_radius_km` DECIMAL(8,2) NULL DEFAULT NULL,
            `is_origin_hub` TINYINT(1) NOT NULL DEFAULT 0,
            `coverage_kind` VARCHAR(24) NOT NULL DEFAULT 'radius',
            `coverage_geojson` TEXT NULL,
            `patch_precedence` VARCHAR(12) NOT NULL DEFAULT 'none',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            INDEX `station_hub` (`is_origin_hub`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_leg` (
            `id_leg` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `from_station_id` INT UNSIGNED NOT NULL,
            `to_station_id` INT UNSIGNED NULL,
            `mode` VARCHAR(16) NOT NULL, `operator_label` VARCHAR(190) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0, `bidirectional` TINYINT(1) NOT NULL DEFAULT 0,
            `verified_at` DATETIME NULL,
            `lead_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `preparation_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `transit_min_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `transit_max_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `tariff_type` VARCHAR(16) NOT NULL DEFAULT 'flat',
            `rate_per_unit` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `estimate` TINYINT(1) NOT NULL DEFAULT 0,
            `estimate_low_factor` DECIMAL(5,3) NOT NULL DEFAULT 0.9,
            `estimate_high_factor` DECIMAL(5,3) NOT NULL DEFAULT 1.1,
            `area_name` VARCHAR(190) NOT NULL DEFAULT '',
            `area_lat` DECIMAL(10,7) NULL, `area_lng` DECIMAL(10,7) NULL,
            `area_radius_km` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `area_outer_radius_km` DECIMAL(8,2) NULL DEFAULT NULL,
            `area_coverage_kind` VARCHAR(24) NOT NULL DEFAULT 'radius',
            `area_coverage_geojson` TEXT NULL,
            `route_geojson` TEXT NULL,
            `patch_precedence` VARCHAR(12) NOT NULL DEFAULT 'none',
            `override_winner` VARCHAR(8) NOT NULL DEFAULT 'none',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            INDEX `leg_from` (`from_station_id`), INDEX `leg_to` (`to_station_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_transfer` (
            `id_transfer` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `from_station_id` INT UNSIGNED NOT NULL, `to_station_id` INT UNSIGNED NOT NULL,
            `connection_type` VARCHAR(64) NOT NULL DEFAULT 'walk',
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `override_winner` VARCHAR(8) NOT NULL DEFAULT 'none',
            `verified_at` DATETIME NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            INDEX `transfer_from` (`from_station_id`), INDEX `transfer_to` (`to_station_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_branch` (
            `id_branch` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `id_shop` INT UNSIGNED NOT NULL,
            `name` VARCHAR(190) NOT NULL,
            `origin_station_id` INT UNSIGNED NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            INDEX `branch_shop` (`id_shop`),
            INDEX `branch_origin` (`origin_station_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_branch_stock` (
            `id_branch_stock` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_branch` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY `branch_product_combination` (`id_branch`, `id_product`, `id_product_attribute`),
            INDEX `stock_shop` (`id_shop`, `id_product`, `id_product_attribute`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
    }

    /** Idempotent migration for the five pre-existing network tables. */
    public static function migrateActiveColumns(): bool
    {
        $db=Db::getInstance();
        foreach (GrTransportNetworkFields::TABLES as $table) {
            $name=_DB_PREFIX_.$table;
            $columns=$db->executeS('SHOW COLUMNS FROM `'.bqSQL($name).'`');
            if (!is_array($columns)) return false;
            if (!in_array('active',array_column($columns,'Field'),true)
                && !$db->execute('ALTER TABLE `'.bqSQL($name)
                    .'` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1')) {
                return false;
            }
        }
        return true;
    }

    /** Separate physical branches are scoped to the current PrestaShop shop. */
    public static function shopId(): int
    {
        if (!class_exists('Context',false)) return 1;
        $id=(int)(Context::getContext()->shop->id ?? 0);
        if ($id<=0) throw new RuntimeException('A shop context is required for branch inventory');
        return $id;
    }

    private static function isShopScoped(string $entity): bool
    {
        return in_array($entity,['branches','branch_stock'],true);
    }

    public static function all(string $entity): array
    {
        $table=self::table($entity);
        $id=GrTransportNetworkFields::IDS[$entity];
        $where=self::isShopScoped($entity) ? ' WHERE id_shop = '.self::shopId() : '';
        $rows=Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`'
            . $where . ' ORDER BY `' . $id . '` ASC');
        if (!is_array($rows)) throw new RuntimeException('Network read failed: ' . $entity);
        return $rows;
    }

    public static function find(string $entity,int $id): ?array
    {
        if ($id<=0) return null;
        $table=self::table($entity);
        $pk=GrTransportNetworkFields::IDS[$entity];
        $where=self::isShopScoped($entity) ? ' AND id_shop = '.self::shopId() : '';
        $row=Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . $table
            . '` WHERE `' . $pk . '` = ' . $id . $where);
        return is_array($row) ? $row : null;
    }

    public static function save(string $entity,array $input,int $id=0): int
    {
        $old=$id ? self::find($entity,$id) : null;
        if ($id && !$old) throw new InvalidArgumentException('Network record not found');
        $fields=GrTransportNetworkFields::fields($entity);
        // Partial API patches and full visual-editor updates share the same validation.
        $merged=$old ? array_intersect_key($old,$fields) : GrTransportNetworkFields::defaults($entity);
        $normalized=GrTransportNetworkFields::normalize($entity,array_replace($merged,$input));
        if ($entity==='legs' || $entity==='transfers') {
            foreach (['from_station_id','to_station_id'] as $ref) {
                if ($normalized[$ref]!==null && !self::find('stations',(int)$normalized[$ref])) {
                    throw new InvalidArgumentException('Unknown referenced station: ' . $ref);
                }
            }
        }
        if ($entity==='branches') {
            $origin=self::find('stations',(int)$normalized['origin_station_id']);
            if (!$origin || empty($origin['is_origin_hub'])) {
                throw new InvalidArgumentException('Branch origin must be a marked origin hub');
            }
        }
        if ($entity==='branch_stock') {
            if (!self::find('branches',(int)$normalized['id_branch'])) {
                throw new InvalidArgumentException('Unknown branch in this shop');
            }
            if (class_exists('Product')) {
                $product=new Product((int)$normalized['id_product']);
                if (!Validate::isLoadedObject($product)) throw new InvalidArgumentException('Unknown product');
                if ($normalized['id_product_attribute']>0) {
                    $combination=new Combination((int)$normalized['id_product_attribute']);
                    if (!Validate::isLoadedObject($combination)
                        || (int)$combination->id_product!==(int)$normalized['id_product']) {
                        throw new InvalidArgumentException('Combination does not belong to product');
                    }
                }
            }
        }
        $row=[];
        foreach ($normalized as $name=>$value) {
            // Db::insert/update stringify PHP null unless encoded as an SQL
            // literal. Use typed SQL ONLY for null; keep '' as an empty string
            // (null_values=true would also turn non-null empty text into NULL).
            $row[$name]=$value===null
                ? ['type'=>'sql','value'=>'NULL']
                : (is_string($value) ? pSQL($value) : $value);
        }
        if ($entity==='legs' || $entity==='transfers') {
            $row['verified_at']=gmdate('Y-m-d H:i:s');
        }
        if (self::isShopScoped($entity)) $row['id_shop']=self::shopId();
        $db=Db::getInstance();
        $where=GrTransportNetworkFields::IDS[$entity].' = '.$id
            . (self::isShopScoped($entity) ? ' AND id_shop = '.self::shopId() : '');
        $ok=$id
            ? $db->update(self::table($entity),$row,$where)
            : $db->insert(self::table($entity),$row);
        if (!$ok) throw new RuntimeException('Network write failed: ' . $entity);
        return $id ?: (int)$db->Insert_ID();
    }

    public static function confirm(string $entity,int $id): void
    {
        if (!in_array($entity,['legs','transfers'],true)) {
            throw new InvalidArgumentException('Only service legs and transfers can be verified');
        }
        if (!self::find($entity,$id)) throw new InvalidArgumentException('Network record not found');
        if (!Db::getInstance()->update(self::table($entity),
            ['verified_at'=>gmdate('Y-m-d H:i:s')],GrTransportNetworkFields::IDS[$entity].' = '.$id)) {
            throw new RuntimeException('Could not confirm network verification date');
        }
    }

    public static function delete(string $entity,int $id): void
    {
        if (!self::find($entity,$id)) throw new InvalidArgumentException('Network record not found');
        if ($entity==='stations') {
            foreach (['legs'=>'grtrans_leg','transfers'=>'grtrans_transfer'] as $target=>$table) {
                $count=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table
                    . '` WHERE from_station_id = ' . $id . ' OR to_station_id = ' . $id);
                if ($count) throw new InvalidArgumentException('Delete connecting legs/transfers before deleting this station');
            }
        }
        if ($entity==='stations') {
            $used=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'
                . _DB_PREFIX_ . 'grtrans_branch` WHERE origin_station_id = '.$id);
            if ($used) throw new InvalidArgumentException('Remove dependent branches before deleting origin station');
        }
        if ($entity==='branches') {
            $used=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'
                . _DB_PREFIX_ . 'grtrans_branch_stock` WHERE id_branch = '.$id
                . ' AND id_shop = '.self::shopId());
            if ($used) throw new InvalidArgumentException('Remove branch stock before deleting branch');
        }
        $where=GrTransportNetworkFields::IDS[$entity].' = '.$id
            . (self::isShopScoped($entity) ? ' AND id_shop = '.self::shopId() : '');
        if (!Db::getInstance()->delete(self::table($entity),$where)) {
            throw new RuntimeException('Network delete failed');
        }
    }

    /** Bulk import uses the same writers in a single transaction, never an unchecked SQL loader.
     * JSON stations may have a client_ref used by subsequent leg/transfer from_ref/to_ref.
     * CSV accepts numeric station IDs already stored in the module.
     */
    public static function bulk(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key,GrTransportNetworkFields::ENTITIES,true)) throw new InvalidArgumentException('Unknown bulk collection');
        }
        $count=0;
        foreach ($payload as $records) {
            if (!is_array($records)) throw new InvalidArgumentException('Bulk collection must be an array');
            $count+=count($records);
        }
        if ($count===0 || $count>1000) throw new InvalidArgumentException('Bulk size must be 1–1000 records');
        $db=Db::getInstance();
        if (!$db->execute('START TRANSACTION')) throw new RuntimeException('Cannot start network import');
        $refs=[];
        $branchRefs=[];
        $created=array_fill_keys(GrTransportNetworkFields::ENTITIES,[]);
        try {
            foreach (GrTransportNetworkFields::ENTITIES as $entity) {
                foreach ($payload[$entity] ?? [] as $record) {
                    if (!is_array($record)) throw new InvalidArgumentException('Bulk entry must be an object');
                    $ref=$record['client_ref'] ?? null;
                    unset($record['client_ref']);
                    foreach (['from','to'] as $side) {
                        $alias=$side . '_ref';
                        if (array_key_exists($alias,$record)) {
                            if (!is_string($record[$alias]) || !isset($refs[$record[$alias]])) {
                                throw new InvalidArgumentException('Unknown station import reference');
                            }
                            $record[$side . '_station_id']=$refs[$record[$alias]];
                            unset($record[$alias]);
                        }
                    }
                    if ($entity==='branches' && isset($record['origin_ref'])) {
                        $name=$record['origin_ref'];
                        if (!is_string($name) || !isset($refs[$name])) {
                            throw new InvalidArgumentException('Unknown origin-station import reference');
                        }
                        $record['origin_station_id']=$refs[$name];
                        unset($record['origin_ref']);
                    }
                    if ($entity==='branch_stock' && isset($record['branch_ref'])) {
                        $name=$record['branch_ref'];
                        if (!is_string($name) || !isset($branchRefs[$name])) {
                            throw new InvalidArgumentException('Unknown branch import reference');
                        }
                        $record['id_branch']=$branchRefs[$name];
                        unset($record['branch_ref']);
                    }
                    $id=self::save($entity,$record);
                    $created[$entity][]=$id;
                    if ($entity==='branches' && $ref!==null) {
                        if (!is_string($ref) || $ref==='' || isset($branchRefs[$ref])) {
                            throw new InvalidArgumentException('Duplicate/invalid branch import reference');
                        }
                        $branchRefs[$ref]=$id;
                    }
                    if ($entity==='stations' && $ref!==null) {
                        if (!is_string($ref) || $ref==='' || isset($refs[$ref])) {
                            throw new InvalidArgumentException('Duplicate/invalid station import reference');
                        }
                        $refs[$ref]=$id;
                    }
                }
            }
            if (!$db->execute('COMMIT')) throw new RuntimeException('Network import commit failed');
            return $created;
        } catch (Throwable $error) {
            $db->execute('ROLLBACK');
            throw $error;
        }
    }

    public static function snapshot(): array
    {
        return [
            'stations'=>self::all('stations'),
            'legs'=>self::all('legs'),
            'transfers'=>self::all('transfers'),
            'branches'=>self::all('branches'),
            'branch_stock'=>self::all('branch_stock'),
        ];
    }

    /** One eligibility boundary for quotes, simulator and map painting.
     * The unfiltered snapshot remains available to admin tables and the API.
     */
    public static function activeSnapshot(?array $source=null): array
    {
        $data=$source ?? self::snapshot();
        $enabled=static fn(array $record): bool => !array_key_exists('active',$record)
            || (int)$record['active']===1;
        $filter=static fn(array $records): array => array_values(array_filter($records,$enabled));
        $stations=$filter($data['stations'] ?? []);
        $stationIds=array_fill_keys(array_map(static fn($r)=>(int)$r['id_station'],$stations),true);
        $links=static function (array $records) use ($filter,$stationIds): array {
            return array_values(array_filter($filter($records),static function ($row) use ($stationIds): bool {
                return isset($stationIds[(int)$row['from_station_id']])
                    && (empty($row['to_station_id']) || isset($stationIds[(int)$row['to_station_id']]));
            }));
        };
        $branches=array_values(array_filter($filter($data['branches'] ?? []),
            static fn($r): bool => isset($stationIds[(int)$r['origin_station_id']])));
        $branchIds=array_fill_keys(array_map(static fn($r)=>(int)$r['id_branch'],$branches),true);
        $stock=array_values(array_filter($filter($data['branch_stock'] ?? []),
            static fn($r): bool => isset($branchIds[(int)$r['id_branch']])));
        return array_replace($data,[
            'stations'=>$stations,'legs'=>$links($data['legs'] ?? []),
            'transfers'=>$links($data['transfers'] ?? []),
            'branches'=>$branches,'branch_stock'=>$stock,
            'branch_inventory_configured'=>!empty($data['branches']),
        ]);
    }

    /** A pinned network quote must not bypass a disabled edge or station. */
    public static function quotePathActive(array $quote,array $eligible): bool
    {
        $ids=[];
        foreach (['stations'=>'id_station','legs'=>'id_leg','transfers'=>'id_transfer',
            'branches'=>'id_branch'] as $entity=>$field) {
            $ids[$entity]=array_fill_keys(array_map(
                static fn($r)=>(int)$r[$field],$eligible[$entity] ?? []),true);
        }
        foreach (['origin_hub_id','destination_station_id'] as $key) {
            $station=(int)($quote[$key] ?? 0);
            if ($station>0 && !isset($ids['stations'][$station])) return false;
        }
        foreach ($quote['network_path']['edges'] ?? [] as $edge) {
            $entity=($edge['kind'] ?? '')==='leg' ? 'legs'
                : (($edge['kind'] ?? '')==='transfer' ? 'transfers' : null);
            if ($entity===null || !isset($ids[$entity][(int)($edge['id'] ?? 0)])) return false;
        }
        $branch=(int)($quote['branch_id'] ?? 0);
        return $branch===0 || isset($ids['branches'][$branch]);
    }

    private static function table(string $entity): string
    {
        if (!isset(GrTransportNetworkFields::TABLES[$entity])) throw new InvalidArgumentException('Unknown network entity');
        return GrTransportNetworkFields::TABLES[$entity];
    }
}
