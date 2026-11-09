<?php
require_once __DIR__ . '/CartQuote.php';
require_once __DIR__ . '/CarrierLifecycle.php';
require_once __DIR__ . '/OrderCharge.php';

/**
 * Durable order pickup state machine (one write path):
 *
 * absent --capture()--> stored (immutable, idempotent on duplicate hooks)
 * absent --repair()---> stored (from original pinned cart quote ONLY)
 * stored --repair()---> regenerated (operator-requested, source verified)
 * no other transitions; a failed write throws and is logged. The record
 * remains in the DB on module uninstall by design, for order history.
 */
final class GrTransportOrderSnapshot
{
    public const ABSENT = 'absent';
    public const STORED = 'stored';
    public const REGENERATED = 'regenerated';

    public static function find(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_order` WHERE id_order = ' . $orderId
        );
        return is_array($row) ? $row : null;
    }

    /** Read-only history for dispatch/admin/customer display. */
    public static function originalQuote(array $row): ?array
    {
        $json=$row['route_snapshot'] ?? null;
        if (!is_string($json) || $json==='') return null;
        $decoded=json_decode($json,true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function status(int $orderId): string
    {
        return self::find($orderId) ? self::STORED : self::ABSENT;
    }

    public static function belongsToCarrier(int $carrierId): bool
    {
        if ($carrierId <= 0) {
            return false;
        }
        return GrTransportCarrierLifecycle::isOwned($carrierId);
    }

    public static function capture(int $orderId, array $quote): array
    {
        return self::write($orderId, $quote, false);
    }

    /**
     * Repair only from the original persisted cart quote and the actual
     * order charge. Never infer a historical station from today's route
     * table, which could contain later tariff changes or deleted routes.
     */
    public static function repair(int $orderId): array
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || !self::belongsToCarrier((int) $order->id_carrier)) {
            throw new InvalidArgumentException('Order does not belong to this transport carrier');
        }
        $quote = GrTransportCartQuote::forOrder((int) $order->id_cart, (int) $order->id_address_delivery);
        if (!$quote) {
            self::fail($orderId, 'No historical cart quote remains; manual investigation required');
        }
        // Use the SAME charge invariant as actionValidateOrder, including
        // historically pinned handling and only genuinely applied vouchers.
        // Current shop handling settings may have changed since this order.
        GrTransportOrderCharge::checkOrder($orderId, $quote);
        $saved = self::write($orderId, $quote, true);
        PrestaShopLogger::addLog('Transport pickup snapshot regenerated from original cart quote for order #'
            . $orderId, 1, null, 'Order', $orderId);
        return $saved;
    }

    private static function write(int $orderId, array $quote, bool $regenerate): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('An actual order ID is required');
        }
        $existing = self::find($orderId);
        if ($existing && !$regenerate) {
            return $existing; // Repeated order hook is not a second transition.
        }
        foreach (['mode', 'station_name', 'price', 'pickup_only', 'notice', 'demo'] as $field) {
            if (!array_key_exists($field, $quote)) {
                self::fail($orderId, 'Cannot persist incomplete pickup quote (' . $field . ')');
            }
        }
        // Preserve the complete selected path and per-edge quoted data as an
        // immutable order-time JSON snapshot, independent of mutable network tables.
        $encoded=json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded===false) self::fail($orderId,'Cannot encode transport dispatch snapshot');
        if ($existing && $regenerate && ($existing['estimate_state'] ?? '')==='settled') {
            self::fail($orderId,'A settled freight estimate must not be regenerated');
        }
        $isEstimate=!empty($quote['estimate']);
        $payload = [
            'route_snapshot'=>pSQL($encoded,true),
            'estimate_min'=>$isEstimate ? round((float)($quote['estimate_min'] ?? 0),2) : null,
            'estimate_max'=>$isEstimate ? round((float)($quote['estimate_max'] ?? 0),2) : null,
            'estimate_state'=>$isEstimate ? 'pending' : 'not_estimate',
            'mode' => pSQL((string) $quote['mode']),
            'station_name' => pSQL((string) $quote['station_name']),
            'price' => round((float) $quote['price'], 2),
            'pickup_only' => (int) $quote['pickup_only'],
            'notice' => pSQL((string) $quote['notice']),
            'demo' => (int) $quote['demo'],
        ];
        $db = Db::getInstance();
        $ok = $existing
            ? $db->update('grtrans_order', $payload, 'id_order = ' . $orderId)
            : $db->insert('grtrans_order', ['id_order' => $orderId] + $payload);
        if (!$ok) {
            // A concurrent duplicate callback can have successfully inserted
            // the same immutable snapshot; check before failing.
            $now = self::find($orderId);
            if (!$regenerate && $now) {
                return $now;
            }
            self::fail($orderId, 'Database rejected transport pickup snapshot write');
        }
        $saved = self::find($orderId);
        if (!$saved) {
            self::fail($orderId, 'Pickup snapshot not readable after successful write');
        }
        return $saved;
    }

    private static function fail(int $orderId, string $message): void
    {
        PrestaShopLogger::addLog('Transport order #' . $orderId . ': ' . $message, 3, null, 'Order', $orderId);
        throw new RuntimeException($message);
    }
}
