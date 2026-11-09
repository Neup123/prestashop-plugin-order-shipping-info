<?php
/**
 * Far-zone acknowledgement bound to the current cart, delivery address and
 * pinned quote, never to a postcode or browser alone. A changed quote or
 * address invalidates the acknowledgement.
 *
 * PrestaShop's encrypted context cookie stores only a signed non-PII digest.
 * Public pages cannot manufacture a consent without the module's secret.
 */
final class GrTransportFarZoneConsent
{
    private static function key(): string
    {
        if (!defined('_COOKIE_KEY_') || _COOKIE_KEY_ === '') {
            throw new RuntimeException('PrestaShop cookie signing key is unavailable');
        }
        return _COOKIE_KEY_;
    }

    private static function material($cart, array $quote): string
    {
        return json_encode([
            'cart'=>(int)($cart->id ?? 0),
            'address'=>(int)($cart->id_address_delivery ?? 0),
            'quote'=>hash('sha256',json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
        ],JSON_UNESCAPED_SLASHES);
    }

    private static function digest($cart, array $quote): string
    {
        return hash_hmac('sha256',self::material($cart,$quote),self::key());
    }

    public static function token($cart): string
    {
        return hash_hmac('sha256','far-zone:'.(int)($cart->id ?? 0).':' .
            (int)($cart->id_address_delivery ?? 0),self::key());
    }

    public static function validToken($cart, $token): bool
    {
        return is_string($token) && $token!=='' &&
            hash_equals(self::token($cart),$token);
    }

    public static function confirmed($cookie, $cart, array $quote): bool
    {
        if (empty($quote['extended'])) return true;
        if (!$cookie || (int)($cart->id ?? 0) <= 0) return false;
        $stored=(string)($cookie->grtrans_far_zone_consent ?? '');
        return $stored!=='' && hash_equals(self::digest($cart,$quote),$stored);
    }

    public static function set($cookie, $cart, array $quote, bool $confirmed): void
    {
        if (!$cookie || (int)($cart->id ?? 0) <= 0 ||
            (int)($cart->id_address_delivery ?? 0) <= 0) {
            throw new InvalidArgumentException('An active checkout cart is required');
        }
        if (empty($quote['extended'])) {
            throw new InvalidArgumentException('This delivery option is not a far-zone quote');
        }
        $cookie->grtrans_far_zone_consent = $confirmed ? self::digest($cart,$quote) : '';
        if (method_exists($cookie,'write')) $cookie->write();
    }
}
