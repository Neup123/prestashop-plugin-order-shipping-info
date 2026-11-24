<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grtransportshipping/classes/NetworkRepository.php';

// Isolated PrestaShop DB stub: reproduce a cached positive COUNT after a
// connection is deleted and ensure repository guards query the live tables.
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_', 'ps_');
class Db
{
    public static array $rows = [
        'station' => [
            1 => ['id_station' => 1],
            2 => ['id_station' => 2],
            3 => ['id_station' => 3],
        ],
        'leg' => [],
        'transfer' => [],
    ];
    public static array $cachedCount = [];
    public static int $freshCountQueries = 0;
    public static int $deletedInactiveWithoutRequest = 0;

    public static function getInstance(): self
    {
        static $instance;
        return $instance ??= new self();
    }

    public function getRow(string $sql)
    {
        if (!preg_match('/^SELECT \\* FROM `ps_grtrans_(station|leg|transfer)` WHERE `id_(?:station|leg|transfer)` = ([0-9]+)$/', $sql, $match)) {
            throw new RuntimeException('Unexpected getRow: ' . $sql);
        }
        return self::$rows[$match[1]][(int)$match[2]] ?? false;
    }

    public function getValue(string $sql, bool $use_cache = true)
    {
        if ($use_cache && array_key_exists($sql, self::$cachedCount)) {
            return self::$cachedCount[$sql];
        }
        if (preg_match('/^SELECT COUNT\\(\\*\\) FROM `ps_grtrans_(leg|transfer)` WHERE from_station_id = ([0-9]+) OR to_station_id = ([0-9]+)$/', $sql, $match)) {
            if (!$use_cache) ++self::$freshCountQueries;
            $id = (int)$match[2];
            $count = count(array_filter(self::$rows[$match[1]], static fn($row) =>
                (int)$row['from_station_id'] === $id || (int)$row['to_station_id'] === $id));
        } elseif (preg_match('/^SELECT COUNT\\(\\*\\) FROM `ps_grtrans_branch` WHERE origin_station_id = ([0-9]+)$/', $sql)) {
            $count = 0;
        } else {
            throw new RuntimeException('Unexpected getValue: ' . $sql);
        }
        if ($use_cache) self::$cachedCount[$sql] = $count;
        return $count;
    }

    public function delete(string $table, string $where): bool
    {
        if (!preg_match('/^grtrans_(station|leg|transfer)$/', $table, $name)
            || !preg_match('/^id_(station|leg|transfer) = ([0-9]+)$/', $where, $id)
            || $name[1] !== $id[1]) {
            throw new RuntimeException('Unexpected delete: ' . $table . ' ' . $where);
        }
        $key = (int)$id[2];
        if (isset(self::$rows[$name[1]][$key]['active'])
            && (int)self::$rows[$name[1]][$key]['active'] === 0
            && $name[1] !== 'station') {
            // Inactive links may be explicitly removed, but never implicitly
            // during station removal.
            ++self::$deletedInactiveWithoutRequest;
        }
        unset(self::$rows[$name[1]][$key]);
        return true;
    }
}

Db::$rows['leg'][9] = [
    'id_leg'=>9,'from_station_id'=>1,'to_station_id'=>2,'active'=>1,
];
Db::$rows['transfer'][10] = [
    'id_transfer'=>10,'from_station_id'=>2,'to_station_id'=>1,'active'=>1,
];
$blocked = false;
try {
    GrTransportNetworkRepository::delete('stations', 2);
} catch (InvalidArgumentException $e) {
    $blocked = str_contains($e->getMessage(), 'Delete connecting legs/transfers');
}
testAssert($blocked && isset(Db::$rows['station'][2]), 'Real links must still block station deletion');

$legSql = 'SELECT COUNT(*) FROM `ps_grtrans_leg` WHERE from_station_id = 2 OR to_station_id = 2';
$transferSql = 'SELECT COUNT(*) FROM `ps_grtrans_transfer` WHERE from_station_id = 2 OR to_station_id = 2';
Db::$cachedCount[$legSql] = 1;
Db::$cachedCount[$transferSql] = 1;
GrTransportNetworkRepository::delete('legs', 9);
GrTransportNetworkRepository::delete('transfers', 10);
testAssert(!isset(Db::$rows['leg'][9], Db::$rows['transfer'][10]), 'Deleting links must remove their rows');

GrTransportNetworkRepository::delete('stations', 2);
testAssert(!isset(Db::$rows['station'][2]), 'Station must delete when real link rows are gone');
testAssert(Db::$freshCountQueries >= 3, 'Dependency checks must bypass cached COUNT results');

Db::$rows['leg'][11] = [
    'id_leg'=>11,'from_station_id'=>1,'to_station_id'=>3,'active'=>0,
];
$blocked = false;
try {
    GrTransportNetworkRepository::delete('stations', 3);
} catch (InvalidArgumentException $e) {
    $blocked = true;
}
testAssert($blocked && isset(Db::$rows['leg'][11]), 'Inactive links are still records; do not delete them implicitly');
testAssert(Db::$deletedInactiveWithoutRequest === 0, 'Station delete must not delete inactive links');
GrTransportNetworkRepository::delete('legs', 11);
GrTransportNetworkRepository::delete('stations', 3);
testAssert(!isset(Db::$rows['station'][3]), 'Station must delete after inactive link is explicitly removed');
testAssert(isset(Db::$rows['station'][1]), 'Unrelated stations remain untouched');
echo "Network deletion: fresh dependency counts, explicit link cleanup, and station removal passed\n";
