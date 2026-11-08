<?php
require_once __DIR__ . '/RouteState.php';

/**
 * Pure, deterministic route resolution. No DB queries, no duplicated
 * availability predicates. Distinct outcomes are propagated to the module:
 * quoted, unmatched, draft_only, suspended, ambiguous.
 */
final class GrTransportQuoteEngine
{
    public static function select(array $routes, string $postalCode, string $city): ?array
    {
        return self::resolve($routes, $postalCode, $city)['quote'];
    }

    public static function resolve(array $routes, string $postalCode, string $city): array
    {
        $postalCode = preg_replace('/\s+/', '', $postalCode);
        $city = self::normalize($city);
        $matches = [];
        foreach ($routes as $route) {
            if (!self::matches($route, $postalCode, $city)) {
                continue;
            }
            $state = GrTransportRouteState::status($route);
            if ($state === GrTransportRouteState::DRAFT) {
                continue; // Drafts must not veto a broader available route.
            }
            $route['_specificity'] = self::specificity($route);
            $matches[] = $route;
        }
        usort($matches, static function ($a, $b) {
            $priority = $b['_specificity'] <=> $a['_specificity'];
            return $priority ?: ((int) $a['id_route'] <=> (int) $b['id_route']);
        });
        if (!$matches) {
            foreach ($routes as $route) {
                if (GrTransportRouteState::status($route) === GrTransportRouteState::DRAFT
                    && self::matches($route, $postalCode, $city)) {
                    return self::result('draft_only');
                }
            }
            return self::result('unmatched');
        }
        $winner = $matches[0];
        if (GrTransportRouteState::isSuspended($winner)) {
            return self::result('suspended', (int) ($winner['id_route'] ?? 0));
        }
        foreach (array_slice($matches, 1) as $candidate) {
            if ($candidate['_specificity'] !== $winner['_specificity']) {
                break;
            }
            if (GrTransportRouteState::isSuspended($candidate)) {
                return self::result('suspended', (int) ($candidate['id_route'] ?? 0));
            }
            // Two equivalent destinations with different warnings or provenance
            // must NOT silently pick the earlier database row.
            foreach (['station_name', 'mode', 'price', 'pickup_only', 'demo', 'notice'] as $field) {
                $equal = $field === 'price'
                    ? round((float) ($candidate[$field] ?? 0), 2) === round((float) ($winner[$field] ?? 0), 2)
                    : (string) ($candidate[$field] ?? '') === (string) ($winner[$field] ?? '');
                if (!$equal) {
                    return self::result('ambiguous');
                }
            }
        }
        unset($winner['_specificity']);
        return ['status' => 'quoted', 'quote' => $winner, 'route_id' => (int) ($winner['id_route'] ?? 0)];
    }

    /**
     * Warn only where an actual suspended rule can overshadow an available,
     * less-specific region rule. Reuses the same route matcher and resolver.
     */
    public static function shadowedSuspensions(array $routes): array
    {
        $blocked = [];
        foreach ($routes as $specific) {
            if (!GrTransportRouteState::isSuspended($specific)) {
                continue;
            }
            $postal = (string) ($specific['postal_prefix'] ?? '');
            $city = (string) ($specific['city'] ?? '');
            if ($postal === '' && $city === '') {
                continue;
            }
            foreach ($routes as $broader) {
                if (!GrTransportRouteState::isAvailable($broader)
                    || self::specificity($broader) >= self::specificity($specific)
                    || !self::matches($broader, $postal, self::normalize($city))) {
                    continue;
                }
                $resolution = self::resolve([$specific, $broader], $postal, $city);
                if ($resolution['status'] === 'suspended') {
                    $blocked[] = ['suspended' => (int) $specific['id_route'], 'fallback' => (int) $broader['id_route']];
                }
            }
        }
        return $blocked;
    }

    private static function result(string $status, int $id = 0): array
    {
        return ['status' => $status, 'quote' => null, 'route_id' => $id];
    }

    private static function matches(array $route, string $postal, string $city): bool
    {
        $prefix = (string) ($route['postal_prefix'] ?? '');
        $ruleCity = self::normalize((string) ($route['city'] ?? ''));
        return !($prefix === '' && $ruleCity === '')
            && ($prefix === '' || strpos($postal, $prefix) === 0)
            && ($ruleCity === '' || $ruleCity === $city);
    }

    private static function specificity(array $route): int
    {
        return strlen((string) ($route['postal_prefix'] ?? '')) * 2
            + (self::normalize((string) ($route['city'] ?? '')) !== '' ? 1 : 0);
    }

    private static function normalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
