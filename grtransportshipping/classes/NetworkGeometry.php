<?php
/**
 * Validated WGS84 GeoJSON coverage and route shapes. Coordinate order is [lng, lat].
 * Persist polygons verbatim (not decorative approximations), and use the SAME
 * polygon membership for network quotes and map display.
 */
final class GrTransportNetworkGeometry
{
    public static function normalize($value, string $category): string
    {
        if ($value === null || $value === '') return '';
        if (is_array($value)) {
            $source = $value;
        } elseif (is_string($value) && strlen($value) <= 60000) {
            try {
                $source = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Invalid GeoJSON: ' . $e->getMessage());
            }
        } else {
            throw new InvalidArgumentException('GeoJSON must be a JSON object (maximum 60 KB)');
        }
        if (!is_array($source)) throw new InvalidArgumentException('GeoJSON must be an object');
        if (($source['type'] ?? '') === 'Feature') $source = $source['geometry'] ?? null;
        if (!is_array($source)) throw new InvalidArgumentException('Invalid GeoJSON geometry');
        $type = $source['type'] ?? null;
        $types = $category === 'polygon' ? ['Polygon', 'MultiPolygon'] : ['LineString', 'MultiLineString'];
        if (!in_array($type, $types, true)) throw new InvalidArgumentException(
            'Expected GeoJSON ' . implode(' or ', $types)
        );
        $coords = $source['coordinates'] ?? null;
        if (!is_array($coords) || !$coords) throw new InvalidArgumentException('GeoJSON has no coordinates');
        $count = 0;
        if ($category === 'polygon') {
            $polygons = $type === 'Polygon' ? [$coords] : $coords;
            if (count($polygons) > 12) throw new InvalidArgumentException('Too many coverage islands');
            foreach ($polygons as $polygon) {
                if (!is_array($polygon) || !$polygon || count($polygon) > 20)
                    throw new InvalidArgumentException('Invalid coverage rings');
                foreach ($polygon as $ring) {
                    self::validatePoints($ring, true, $count);
                }
            }
        } else {
            $lines = $type === 'LineString' ? [$coords] : $coords;
            if (count($lines) > 12) throw new InvalidArgumentException('Too many route segments');
            foreach ($lines as $line) self::validatePoints($line, false, $count);
        }
        $encoded = json_encode(['type' => $type, 'coordinates' => $coords],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 60000) throw new InvalidArgumentException('GeoJSON exceeds 60 KB');
        return $encoded;
    }

    private static function validatePoints($points, bool $ring, int &$count): void
    {
        if (!is_array($points) || count($points) < ($ring ? 4 : 2))
            throw new InvalidArgumentException('GeoJSON ring/line has too few vertices');
        foreach ($points as $point) {
            if (!is_array($point) || count($point) !== 2 ||
                !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null) ||
                !is_finite((float)$point[0]) || !is_finite((float)$point[1]) ||
                abs((float)$point[0]) > 180 || abs((float)$point[1]) > 90)
                throw new InvalidArgumentException('Invalid WGS84 [longitude, latitude] vertex');
            if (++$count > 1500) throw new InvalidArgumentException('GeoJSON geometry is too detailed');
        }
        if ($ring && ($points[0][0] != $points[count($points) - 1][0] ||
            $points[0][1] != $points[count($points) - 1][1]))
            throw new InvalidArgumentException('GeoJSON polygon rings must be closed');
    }

    public static function contains($json, float $lat, float $lng): bool
    {
        if (!is_string($json) || $json === '') return false;
        $geometry = json_decode($json, true);
        if (!is_array($geometry)) return false;
        $polygons = ($geometry['type'] ?? '') === 'Polygon' ?
            [$geometry['coordinates']] : (($geometry['type'] ?? '') === 'MultiPolygon' ?
            $geometry['coordinates'] : []);
        foreach ($polygons as $rings) {
            if (!is_array($rings) || !$rings || !self::inRing($rings[0], $lat, $lng)) continue;
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                if (self::inRing($hole, $lat, $lng)) {$inHole = true; break;}
            }
            if (!$inHole) return true;
        }
        return false;
    }

    private static function inRing(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            $a = $ring[$i]; $b = $ring[$j];
            $ay = (float)$a[1]; $by = (float)$b[1];
            $ax = (float)$a[0]; $bx = (float)$b[0];
            $cross = ($lng - $ax) * ($by - $ay) - ($lat - $ay) * ($bx - $ax);
            if (abs($cross) < 1e-10 && $lng >= min($ax, $bx) - 1e-10 &&
                $lng <= max($ax, $bx) + 1e-10 && $lat >= min($ay, $by) - 1e-10 &&
                $lat <= max($ay, $by) + 1e-10) return true;
            if (($ay > $lat) !== ($by > $lat) &&
                $lng < ($bx - $ax) * ($lat - $ay) / ($by - $ay) + $ax) $inside = !$inside;
        }
        return $inside;
    }
}
