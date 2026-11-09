<?php
require_once __DIR__ . '/NetworkSettings.php';

/**
 * Estimated journey window in hours. Never fabricate transit duration when an
 * operator hasn't entered it; a missing leg transit leaves the window unknown.
 * Transfers have no time field in #73, so they are disclosed as unmodelled.
 */
final class GrTransportNetworkTiming
{
    public static function window(array $edges): ?array
    {
        if (GrTransportNetworkSettings::get('show_delivery_time')<0.5) return null;
        $min=0.0; $max=0.0; $legs=0; $unmodelledTransfer=false;
        foreach ($edges as $edge) {
            if (($edge['kind'] ?? '')==='transfer') {
                $unmodelledTransfer=true;
                continue;
            }
            if (($edge['kind'] ?? '')!=='leg') continue;
            $legs++;
            $low=$edge['transit_min_hours'] ?? null;
            $high=$edge['transit_max_hours'] ?? null;
            if (!is_numeric($low) || !is_numeric($high) || (float)$low<=0
                || (float)$high<(float)$low) return null;
            $lead=(float)($edge['lead_hours'] ?? 0);
            $prep=(float)($edge['preparation_hours'] ?? 0);
            if (!is_finite($lead) || !is_finite($prep) || $lead<0 || $prep<0) return null;
            $min+=$prep+$lead+(float)$low;
            $max+=$prep+$lead+(float)$high;
        }
        if (!$legs) return null;
        return ['min_hours'=>round($min,2),'max_hours'=>round($max,2),
            'transfer_time_unknown'=>$unmodelledTransfer ? 1 : 0];
    }
}
