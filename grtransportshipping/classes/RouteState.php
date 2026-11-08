<?php
require_once __DIR__ . '/RouteValidationException.php';
/**
 * The route lifecycle has a single persisted state: draft, available or suspended.
 *
 * Transition table (all changes made by the route editor):
 *               -> draft      available       suspended
 * draft             yes          yes              yes
 * available         yes          yes              yes
 * suspended         yes          yes              yes
 *
 * draft: never quoted and NEVER shadows other routes.
 * available: quoted, unless another same-priority rule is ambiguous.
 * suspended: intentionally blocks more general available routes, with a
 * visible warning in the editor and a distinct "suspended" quote result.
 * No state is terminal; the operator may resume or edit every route.
 * demo: 1=unverified, 0=confirmed by the logged-in employee at a timestamp.
 * pickup_only: 1=station collection, 0=arrangements must be confirmed.
 * Each composite state is validated by transition(), the only admin writer.
 */
final class GrTransportRouteState
{
    public const DRAFT = 'draft';
    public const AVAILABLE = 'available';
    public const SUSPENDED = 'suspended';
    public const STATES = [self::DRAFT, self::AVAILABLE, self::SUSPENDED];
    public const STATE_LABELS = [
        self::DRAFT => 'Draft',
        self::AVAILABLE => 'Available',
        self::SUSPENDED => 'Suspended',
    ];
    public const TRANSITIONS = [
        self::DRAFT => self::STATES,
        self::AVAILABLE => self::STATES,
        self::SUSPENDED => self::STATES,
    ];

    public static function status(array $route): string
    {
        $state = (string) ($route['route_status'] ?? '');
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Invalid persisted route status');
        }
        return $state;
    }

    public static function isAvailable(array $route): bool
    {
        return self::status($route) === self::AVAILABLE;
    }

    public static function isSuspended(array $route): bool
    {
        return self::status($route) === self::SUSPENDED;
    }

    /**
     * The sole transition guard for state, verification and pickup flags.
     * A changed confirmed tariff must be re-confirmed by an employee.
     * Returns only the columns owned by this state machine.
     */
    public static function transition(?array $old, array $requested, int $employeeId, bool $priceChanged = false): array
    {
        $status = (string) ($requested['route_status'] ?? '');
        if (!in_array($status, self::STATES, true)) {
            throw new GrTransportRouteValidationException(['route_status' => 'Route state must be Draft, Available or Suspended.']);
        }
        if ($old && !in_array($status, self::TRANSITIONS[self::status($old)], true)) {
            throw new GrTransportRouteValidationException(['route_status' => 'This route state change is not allowed.']);
        }
        foreach (['demo', 'pickup_only'] as $flag) {
            if (!isset($requested[$flag]) || !in_array((string) $requested[$flag], ['0', '1'], true)) {
                throw new GrTransportRouteValidationException([$flag => $flag === 'demo'
                    ? 'Choose whether this is an unverified estimate.'
                    : 'Choose whether station pickup is required.']);
            }
        }
        $demo = (int) $requested['demo'];
        $verificationChanged = !$old || (int) $old['demo'] !== $demo || $priceChanged;
        $verifiedAt = $old['verified_at'] ?? null;
        $verifiedBy = $old['verified_by_employee'] ?? null;
        if ($demo === 1) {
            $verifiedAt = null;
            $verifiedBy = null;
        } elseif ($verificationChanged || !$verifiedAt || !$verifiedBy) {
            if ($employeeId <= 0) {
                throw new GrTransportRouteValidationException(['demo' => 'Confirming a live tariff requires a signed-in employee.']);
            }
            $verifiedAt = gmdate('Y-m-d H:i:s');
            $verifiedBy = $employeeId;
        }
        return [
            'route_status' => $status,
            'demo' => $demo,
            'pickup_only' => (int) $requested['pickup_only'],
            'verified_at' => $verifiedAt,
            'verified_by_employee' => $verifiedBy === null ? null : (int) $verifiedBy,
        ];
    }
}
