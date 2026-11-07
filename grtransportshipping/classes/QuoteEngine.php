<?php
/**
 * Pure quote-selection logic, independent of PrestaShop globals.
 * Destination rules are sorted by longest postal prefix, then by specificity.
 */
class GrTransportQuoteEngine
{
    public static function select(array $routes, string $postalCode, string $city): ?array
    {
        $postalCode = preg_replace('/\\s+/', '', $postalCode);
        $city = self::normalize($city);
        $matches = [];
        foreach ($routes as $route) {
            if (empty($route['active'])) {
                continue;
            }
            $prefix = (string) ($route['postal_prefix'] ?? '');
            $ruleCity = self::normalize((string) ($route['city'] ?? ''));
            if ($prefix !== '' && strpos($postalCode, $prefix) !== 0) {
                continue;
            }
            if ($ruleCity !== '' && $ruleCity !== $city) {
                continue;
            }
            if ($prefix === '' && $ruleCity === '') {
                continue;
            }
            $route['_specificity'] = strlen($prefix) * 2 + ($ruleCity !== '' ? 1 : 0);
            $matches[] = $route;
        }
        usort($matches, static function ($a, $b) {
            $priority = $b['_specificity'] <=> $a['_specificity'];
            return $priority ?: ((int) $a['id_route'] <=> (int) $b['id_route']);
        });
        if (!$matches) {
            return null;
        }
        $winner = $matches[0];
        // Equal-priority rules pointing to different destinations cannot be
        // resolved safely by database insertion order.
        foreach (array_slice($matches, 1) as $candidate) {
            if ($candidate['_specificity'] !== $winner['_specificity']) {
                break;
            }
            if ((string) ($candidate['station_name'] ?? '') !== (string) ($winner['station_name'] ?? '')
                || (string) ($candidate['mode'] ?? '') !== (string) ($winner['mode'] ?? '')
                || (float) ($candidate['price'] ?? 0) !== (float) ($winner['price'] ?? 0)
                || (int) ($candidate['pickup_only'] ?? 1) !== (int) ($winner['pickup_only'] ?? 1)) {
                return null;
            }
        }
        unset($winner['_specificity']);
        return $winner;
    }

    private static function normalize(string $text): string
    {
        $text = trim(preg_replace('/\\s+/u', ' ', $text));
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
