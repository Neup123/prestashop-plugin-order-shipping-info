<?php
require_once __DIR__ . '/QuoteEngine.php';
require_once __DIR__ . '/NetworkSettings.php';

/** Input adapter only. All eligibility, precedence and pricing remain in QuoteEngine. */
final class GrTransportQuoteCheck
{
    public static function pinRadiusKm(): float
    {
        return GrTransportNetworkSettings::get('pin_radius_km');
    }

    public static function stations(): array
    {
        return require __DIR__ . '/../data/stations.php';
    }

    public static function text(array $routes, string $input): array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 512) {
            return GrTransportQuoteEngine::resolve([], '', '');
        }
        $postal = preg_match('/^\d[\d\s]*$/D', $input) ? preg_replace('/\s+/', '', $input) : '';
        if ($postal !== '') {
            foreach (self::stations() as $station) {
                if ($postal === $station['postcode']) {
                    return GrTransportQuoteEngine::resolve($routes, $postal, $station['cities'][1]);
                }
            }
            return self::partial($routes, $postal, '');
        }
        // Catalogue aliases supply the missing postcode for the seeded routes.
        // Use the engine itself for alias comparison, including Greek normalisation.
        foreach (self::stations() as $station) {
            foreach ($station['cities'] as $city) {
                if (GrTransportQuoteEngine::select([[
                    'id_route' => 1, 'city' => $city, 'postal_prefix' => '',
                    'route_status' => GrTransportRouteState::AVAILABLE, 'price' => 1,
                ]], '', $input) !== null) {
                    return GrTransportQuoteEngine::resolve($routes, $station['postcode'], $station['cities'][1]);
                }
            }
        }
        return self::partial($routes, '', $input);
    }

    /** Infer only an unambiguous missing address component from stored rules.
     * Run every completion through the full engine, so suspension/precedence
     * cannot be bypassed by supplying less address information.
     */
    private static function partial(array $routes, string $postal, string $city): array
    {
        $results = [GrTransportQuoteEngine::resolve($routes, $postal, $city)];
        foreach ($routes as $route) {
            $results[] = GrTransportQuoteEngine::resolve($routes,
                $postal !== '' ? $postal : (string) ($route['postal_prefix'] ?? ''),
                $city !== '' ? $city : (string) ($route['city'] ?? ''));
        }
        $quotes = [];
        foreach ($results as $result) {
            if (in_array($result['status'], ['suspended', 'ambiguous'], true)) {
                return $result;
            }
            if ($result['quote']) {
                $quotes[$result['route_id']] = $result;
            }
        }
        if (count($quotes) > 1) {
            return ['status' => 'ambiguous', 'quote' => null, 'route_id' => 0];
        }
        return $quotes ? reset($quotes) : $results[0];
    }

    /** Nearest catalogued destination, inclusive configured great-circle radius.
     * Equal distances use catalogue order. Never skip a nearer unpriced or
     * suspended station to offer a more distant route. No geocoding service.
     */
    public static function pin(array $routes, $latitude, $longitude): array
    {
        $nearest = null;
        $distance = INF;
        $radius = self::pinRadiusKm();
        if (!is_scalar($latitude) || !is_scalar($longitude)
            || !is_numeric($latitude) || !is_numeric($longitude)
            || !is_finite((float) $latitude) || !is_finite((float) $longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return GrTransportQuoteEngine::resolve([], '', '');
        }
        foreach (self::stations() as $station) {
            $km = self::distance((float) $latitude, (float) $longitude, $station['lat'], $station['lng']);
            if ($km <= $radius && $km < $distance) {
                $nearest = $station;
                $distance = $km;
            }
        }
        $result = $nearest
            ? self::text($routes, $nearest['postcode'])
            : GrTransportQuoteEngine::resolve([], '', '');
        $result['station'] = $nearest;
        return $result;
    }

    public static function distance(float $lat, float $lng, float $otherLat, float $otherLng): float
    {
        $a = sin(deg2rad($otherLat - $lat) / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad($otherLat)) * sin(deg2rad($otherLng - $lng) / 2) ** 2;
        return 6371.0088 * 2 * asin(sqrt(min(1.0, $a)));
    }

    /** Only known named terminals are mapped; merchant edits never reuse stale coordinates. */
    public static function routeStations(array $route): array
    {
        foreach (self::stations() as $station) {
            if ($station['name'] === $route['station_name'] && $station['origin']['name'] === $route['origin_name']) {
                return [$station['origin'], ['name' => $station['name'], 'lat' => $station['lat'], 'lng' => $station['lng']]];
            }
        }
        return [];
    }
}
