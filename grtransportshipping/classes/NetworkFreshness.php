<?php
require_once __DIR__ . '/NetworkSettings.php';

/** UTC calendar-month verification freshness, used only by the admin map. */
final class GrTransportNetworkFreshness
{
    public static function stale($verifiedAt, ?DateTimeImmutable $now=null): bool
    {
        if (!is_string($verifiedAt) || trim($verifiedAt)==='') return true;
        try {
            $checked=new DateTimeImmutable(trim($verifiedAt).' UTC');
        } catch (Throwable $e) {
            return true;
        }
        $now=$now ?? new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $months=(int)GrTransportNetworkSettings::get('stale_after_months');
        $cutoff=$now->setTimezone(new DateTimeZone('UTC'))->modify('-'.$months.' months');
        return $checked<$cutoff;
    }

    public static function mapRecords(array $records): array
    {
        if (GrTransportNetworkSettings::get('show_stale_map')<0.5) return $records;
        foreach ($records as &$record) {
            $record['_stale']=self::stale($record['verified_at'] ?? null) ? 1 : 0;
        }
        unset($record);
        return $records;
    }
}
