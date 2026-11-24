<?php
/**
 * Persisted freight settlement state; snapshot repair is an audited event,
 * not an additional persisted state. Values are compatible with existing rows.
 *
 * Creation: absent -> not_estimate (fixed quote) or pending (estimated quote).
 * Payment: pending -> settled (verified external receipt); matching repeat
 * receipt is idempotent, a conflicting receipt is rejected. not_estimate and
 * settled are terminal for payment.
 * Repair: an unsettled snapshot may be reconstructed only from the original
 * cart quote, which determines its initial state; settled is never repaired.
 */
final class GrTransportEstimateState
{
    public const NOT_ESTIMATE = 'not_estimate';
    public const PENDING = 'pending';
    public const SETTLED = 'settled';
    public const STATES = [self::NOT_ESTIMATE, self::PENDING, self::SETTLED];
    public const TERMINAL_PAYMENT_STATES = [self::NOT_ESTIMATE, self::SETTLED];

    public static function initial(array $quote): string
    {
        return !empty($quote['estimate']) ? self::PENDING : self::NOT_ESTIMATE;
    }

    public static function status(array $snapshot): string
    {
        $state = $snapshot['estimate_state'] ?? '';
        if (!in_array($state, self::STATES, true)) {
            throw new RuntimeException('Invalid persisted freight estimate state');
        }
        return $state;
    }

    public static function maySettle(array $snapshot): bool
    {
        return !in_array(self::status($snapshot), self::TERMINAL_PAYMENT_STATES, true);
    }

    public static function mayRepair(array $snapshot): bool
    {
        return self::status($snapshot) !== self::SETTLED;
    }

    public static function columnSql(): string
    {
        return "VARCHAR(16) NOT NULL DEFAULT '".self::NOT_ESTIMATE."'";
    }
}
