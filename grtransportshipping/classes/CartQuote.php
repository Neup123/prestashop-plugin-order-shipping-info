<?php
/**
 * Persisted checkout quote lifecycle. A cart's price and pickup text are
 * pinned together, never recomputed separately between render, charge and
 * order snapshot; a new delivery address or a 30-minute expiry re-quotes.
 *
 * absent -> pinned (first successful quote) -> consumed (order validation).
 * A consumed quote is immutable; it can be read for auditing but never reset.
 * The delivery address change/expiry is the only allowed replacement of an
 * unconsumed quote. No secret/customer data are included.
 */
final class GrTransportCartQuote
{
    public const PINNED = 'pinned';
    public const CONSUMED = 'consumed';
    public const TTL_SECONDS = 1800;

    private static function row(int $cartId): ?array
    {
        if ($cartId <= 0) {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'grtrans_cart_quote` WHERE id_cart = ' . $cartId
        );
        return is_array($row) ? $row : null;
    }

    private static function decode(array $row): ?array
    {
        $quote = json_decode((string) $row['quote_data'], true);
        return is_array($quote) ? $quote : null;
    }

    /**
     * Historical repair source, even when the customer has since edited
     * their address or the 30-minute quote hold has expired. Only a quote
     * actually pinned to this order's cart/address is trustworthy.
     */
    public static function forOrder(int $cartId, int $addressId): ?array
    {
        $row = self::row($cartId);
        if (!$row || (int) $row['id_address'] !== $addressId) {
            return null;
        }
        return self::decode($row);
    }

    public static function pinned(int $cartId, int $addressId, string $fingerprint): ?array
    {
        if ($cartId <= 0 || $addressId <= 0) {
            return null;
        }
        $row = self::row($cartId);
        if (!$row || (int) $row['id_address'] !== $addressId
            || (string) $row['address_fingerprint'] !== $fingerprint) {
            return null;
        }
        // Consumed quotes remain immutable for audit and duplicate callbacks.
        if ($row['quote_status'] !== self::CONSUMED
            && strtotime((string) $row['created_at'] . ' UTC') < time() - self::TTL_SECONDS) {
            return null;
        }
        return self::decode($row);
    }

    public static function pin(int $cartId, int $addressId, string $fingerprint, array $quote): array
    {
        if ($cartId <= 0 || $addressId <= 0) {
            return $quote;
        }
        $db = Db::getInstance();
        $existing = self::pinned($cartId, $addressId, $fingerprint);
        if ($existing !== null) {
            return $existing; // First successfully pinned quote wins any race.
        }
        $old = $db->getRow('SELECT id_address, quote_status FROM `' . _DB_PREFIX_ . 'grtrans_cart_quote` WHERE id_cart = ' . $cartId);
        if ($old && $old['quote_status'] === self::CONSUMED) {
            throw new RuntimeException('Cannot overwrite a consumed shipping quote');
        }
        if ($old && !$db->delete('grtrans_cart_quote', 'id_cart = ' . $cartId)) {
            throw new RuntimeException('Cannot replace stale shipping quote');
        }
        $serialized = json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($serialized === false || !$db->insert('grtrans_cart_quote', [
            'id_cart' => $cartId,
            'id_address' => $addressId,
            'address_fingerprint' => $fingerprint,
            'quote_data' => pSQL($serialized, true),
            'quote_status' => self::PINNED,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE)) {
            throw new RuntimeException('Cannot pin shipping quote before checkout');
        }
        $pinned = self::pinned($cartId, $addressId, $fingerprint);
        if ($pinned === null) {
            throw new RuntimeException('Pinned shipping quote is unreadable');
        }
        return $pinned;
    }

    public static function consume(int $cartId): void
    {
        if ($cartId > 0 && !Db::getInstance()->update('grtrans_cart_quote', [
            'quote_status' => self::CONSUMED,
        ], 'id_cart = ' . $cartId . " AND quote_status = '" . self::PINNED . "'")) {
            throw new RuntimeException('Failed to mark shipping quote consumed');
        }
    }
}
