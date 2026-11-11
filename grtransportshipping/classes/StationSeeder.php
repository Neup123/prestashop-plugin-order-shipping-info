<?php
require_once __DIR__ . '/NetworkSettings.php';

/** Install-only station fixtures; existing merchant stations are never modified. */
final class GrTransportStationSeeder
{
    private const SEED_CONFIG = 'GRTRANS_STATIONS_SEED_INITIALIZED';

    public static function seedOnce(): void
    {
        if ((bool) Configuration::get(self::SEED_CONFIG)) {
            return;
        }

        $db = Db::getInstance();
        $existing = (int) $db->getValue('SELECT COUNT(*) FROM `'
            . _DB_PREFIX_ . 'grtrans_station`');
        if ($existing) {
            self::markInitialized();
            return;
        }

        $stations = [];
        foreach (require __DIR__ . '/../data/stations.php' as $destination) {
            $stations[$destination['name']] = ['data' => $destination, 'hub' => 0];
            $origin = $destination['origin'];
            $stations[$origin['name']] = ['data' => $origin, 'hub' => 1];
        }
        $innerRadius = GrTransportNetworkSettings::get('station_inner_km');

        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException('Could not begin initial station seed transaction');
        }
        try {
            foreach ($stations as $entry) {
                $station = $entry['data'];
                $name = (string) $station['name'];
                $type = str_contains($name, 'Airport') ? 'airport'
                    : (str_contains($name, 'Port') ? 'ferry' : 'bus');
                if (!$db->insert('grtrans_station', [
                    'name' => $name,
                    'type' => $type,
                    'address_text' => (string) ($station['cities'][0] ?? ''),
                    'lat' => $station['lat'],
                    'lng' => $station['lng'],
                    'inner_radius_km' => $innerRadius,
                    'is_origin_hub' => $entry['hub'],
                ])) {
                    throw new RuntimeException('Could not seed station: ' . $name);
                }
            }
            if (!$db->execute('COMMIT')) {
                throw new RuntimeException('Could not commit initial station dataset');
            }
        } catch (Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
        self::markInitialized();
    }

    private static function markInitialized(): void
    {
        if (!Configuration::updateValue(self::SEED_CONFIG, 1)) {
            throw new RuntimeException('Could not mark station data seed as initialized');
        }
    }
}
