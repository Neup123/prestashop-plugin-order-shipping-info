<?php
/**
 * Single order-charge invariant for transport quotes and historical repair.
 *
 * This is a derived outcome, not a persisted state machine:
 *   normal: charged == pinned route price + pinned handling fee;
 *   free_shipping: charged == 0 AND PrestaShop actually attached a live
 *                  free-shipping order_cart_rule to this specific order;
 *   divergent: any other charge (fail closed and log).
 *
 * In PrestaShop 9.2, a shipping module with need_range=true replaces the
 * precomputed $shipping_cost argument, which otherwise includes the
 * merchant's PS_SHIPPING_HANDLING fee. We pin that conditional fee alongside
 * the route quote and explicitly include it once in the module's total.
 * Cart rules are validated by PrestaShop during PaymentModule::validateOrder;
 * only persisted order_cart_rule.free_shipping is accepted as evidence.
 *
 * Does not authorize arbitrary discounts, product surcharges, a guessed
 * voucher, a different carrier, or discrepancies beyond rounding tolerance.
 */
final class GrTransportOrderCharge
{
    public const TOLERANCE = 0.02;
    public const NORMAL = 'normal';
    public const FREE_SHIPPING = 'free_shipping';

    public static function configuredHandlingFee(int $carrierId, int $currencyId): float
    {
        if ($carrierId <= 0 || $currencyId <= 0) {
            return 0.0;
        }
        $carrier = new Carrier($carrierId);
        if (!Validate::isLoadedObject($carrier) || !$carrier->shipping_handling) {
            return 0.0;
        }
        // This is a per-order shop handling charge, not a route tariff.
        $amount = max(0.0, (float) Configuration::get('PS_SHIPPING_HANDLING'));
        return round((float) Tools::convertPrice($amount, Currency::getCurrencyInstance($currencyId)), 2);
    }

    public static function expectedShipping(array $quote): float
    {
        return round((float) $quote['price'] + (float) ($quote['handling_fee'] ?? 0), 2);
    }

    public static function hasAppliedFreeShipping(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        // PrestaShop::Order::getCartRules() is the core-owned reader for
        // this order's persisted, non-deleted order_cart_rule records. Do not
        // infer usage from an unattached CartRule or a checkout request.
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        foreach ($order->getCartRules() ?: [] as $applied) {
            if ((int) ($applied['free_shipping'] ?? 0) === 1
                && (int) ($applied['deleted'] ?? 0) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pure classification for the single server-side charge rule.
     * The appliedFreeShipping flag is always sourced from order_cart_rule
     * by checkOrder(), never from user or checkout request parameters.
     */
    public static function classify(float $charged, array $quote, bool $appliedFreeShipping): ?string
    {
        if (!is_finite($charged) || $charged < 0) {
            return null;
        }
        if (abs($charged - self::expectedShipping($quote)) <= self::TOLERANCE) {
            return self::NORMAL;
        }
        if ($appliedFreeShipping && abs($charged) <= self::TOLERANCE) {
            return self::FREE_SHIPPING;
        }
        return null;
    }

    /**
     * Compare a real, persisted PrestaShop order with the pinned source quote.
     * Synthetic hook fixtures without an orders row are intentionally not
     * checked here; they test hook shape, not genuine payment calculation.
     */
    public static function checkOrder(int $orderId, array $quote): ?string
    {
        $row = Db::getInstance()->getRow('SELECT total_shipping_tax_excl FROM `'
            . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $orderId);
        if (!is_array($row)) {
            return null;
        }
        $charged = (float) $row['total_shipping_tax_excl'];
        $reason = self::classify($charged, $quote, self::hasAppliedFreeShipping($orderId));
        if ($reason !== null) {
            return $reason;
        }
        $expected = self::expectedShipping($quote);
        $message = sprintf(
            'Transport order #%d shipping charge divergence: charged %.2f EUR, pinned quote plus eligible handling %.2f EUR; no applied free-shipping rule explains the difference',
            $orderId, $charged, $expected
        );
        PrestaShopLogger::addLog($message, 3, null, 'Order', $orderId);
        throw new RuntimeException($message);
    }
}
