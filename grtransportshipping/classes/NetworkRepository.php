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
            `outer_radius_km` DECIMAL(8,2) NOT NULL,
            `is_origin_hub` TINYINT(1) NOT NULL DEFAULT 0,
            INDEX `station_hub` (`is_origin_hub`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_leg` (
            `id_leg` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `from_station_id` INT UNSIGNED NOT NULL,
            `to_station_id` INT UNSIGNED NULL,
            `mode` VARCHAR(16) NOT NULL, `operator_label` VARCHAR(190) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0, `bidirectional` TINYINT(1) NOT NULL DEFAULT 0,
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
            INDEX `leg_from` (`from_station_id`), INDEX `leg_to` (`to_station_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
        && $db->execute("CREATE TABLE IF NOT EXISTS `{$prefix}grtrans_transfer` (
            `id_transfer` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `from_station_id` INT UNSIGNED NOT NULL, `to_station_id` INT UNSIGNED NOT NULL,
            `connection_type` VARCHAR(64) NOT NULL DEFAULT 'walk',
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            INDEX `transfer_from` (`from_station_id`), INDEX `transfer_to` (`to_station_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
    }

    public static function all(string $entity): array
    {
        $table=self::table($entity);
        $id=GrTransportNetworkFields::IDS[$entity];
        $rows=Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '` ORDER BY `' . $id . '` ASC');
        if (!is_array($rows)) throw new RuntimeException('Network read failed: ' . $entity);
        return $rows;
    }

    public static function find(string $entity,int $id): ?array
    {
        if ($id<=0) return null;
        $table=self::table($entity);
        $pk=GrTransportNetworkFields::IDS[$entity];
        $row=Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . $table . '` WHERE `' . $pk . '` = ' . $id);
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
        $row=[];
        foreach ($normalized as $name=>$value) {
            $row[$name]=is_string($value) ? pSQL($value) : $value;
        }
        $db=Db::getInstance();
        $ok=$id
            ? $db->update(self::table($entity),$row,GrTransportNetworkFields::IDS[$entity] . ' = ' . $id)
            : $db->insert(self::table($entity),$row);
        if (!$ok) throw new RuntimeException('Network write failed: ' . $entity);
        return $id ?: (int)$db->Insert_ID();
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
        if (!Db::getInstance()->delete(self::table($entity),GrTransportNetworkFields::IDS[$entity] . ' = ' . $id)) {
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
        $created=['stations'=>[],'legs'=>[],'transfers'=>[]];
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
                    $id=self::save($entity,$record);
                    $created[$entity][]=$id;
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
        ];
    }

    private static function table(string $entity): string
    {
        if (!isset(GrTransportNetworkFields::TABLES[$entity])) throw new InvalidArgumentException('Unknown network entity');
        return GrTransportNetworkFields::TABLES[$entity];
    }
}
