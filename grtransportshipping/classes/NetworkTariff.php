<?php
/** Pricing and cart parcel measurements for network legs. */
final class GrTransportNetworkTariff
{
    public static function cartMeasurements($cart): array
    {
        $parcel=['weight_kg'=>null,'volume_m3'=>null,'missing_weight'=>false,'missing_volume'=>false];
        if (!is_object($cart) || !method_exists($cart,'getProducts')) return $parcel;
        $items=$cart->getProducts() ?: [];
        if (!$items) return $parcel;
        $weight=0.0; $volume=0.0;
        foreach ($items as $item) {
            $quantity=max(1,(int)($item['cart_quantity'] ?? $item['quantity'] ?? 1));
            $w=$item['weight'] ?? null;
            if (!is_numeric($w) || (float)$w<=0) $parcel['missing_weight']=true;
            else $weight+=(float)$w*$quantity;
            $dimensions=[$item['width'] ?? null,$item['height'] ?? null,$item['depth'] ?? null];
            if (count(array_filter($dimensions,static function ($d) {
                return is_numeric($d) && (float)$d>0;
            }))!==3) {
                $parcel['missing_volume']=true;
            } else {
                // PrestaShop product dimensions are in shop-defined dimension units;
                // target shop must use cm for this conversion; see operator guide.
                $volume+=(float)$dimensions[0]*(float)$dimensions[1]*(float)$dimensions[2]*$quantity/1000000;
            }
        }
        $parcel['weight_kg']=$parcel['missing_weight'] ? null : round($weight,4);
        $parcel['volume_m3']=$parcel['missing_volume'] ? null : round($volume,7);
        return $parcel;
    }

    public static function leg(array $leg,array $parcel=[]): ?array
    {
        $type=(string)($leg['tariff_type'] ?? 'flat');
        if (!in_array($type,['flat','weight','volume'],true)) return null;
        $base=(float)($leg['price'] ?? 0);
        $rate=(float)($leg['rate_per_unit'] ?? 0);
        if (!is_finite($base) || !is_finite($rate) || $base<0 || $rate<0) return null;
        $missing=false;
        $quantity=null;
        $measure=$type==='weight' ? 'weight_kg' : 'volume_m3';
        if ($type!=='flat') {
            $quantity=$parcel[$measure] ?? null;
            if (!is_numeric($quantity) || !is_finite((float)$quantity) || (float)$quantity<=0
                || !empty($parcel[$type==='weight' ? 'missing_weight' : 'missing_volume'])) {
                $missing=true;
                if ($base<=0) return null; // Never turn missing measurements into free freight.
                $cost=$base;
            } else $cost=$base + $rate*(float)$quantity;
        } else $cost=$base;
        $estimate=!empty($leg['estimate']);
        $min=round($cost,2); $max=$min;
        if ($estimate) {
            $low=(float)($leg['estimate_low_factor'] ?? 0.9);
            $high=(float)($leg['estimate_high_factor'] ?? 1.1);
            if ($cost<=0 || !is_finite($low) || !is_finite($high) || $low<0 || $high<=$low) {
                return null;
            }
            $min=round($cost*$low,2);
            $max=round($cost*$high,2);
            if ($min >= $max) return null;
        }
        $effective=$leg;
        $effective['price']=$estimate ? $max : round($cost,2);
        $effective['tariff_min']=$min;
        $effective['tariff_max']=$max;
        $effective['tariff_estimate']=$estimate ? 1 : 0;
        $effective['tariff_missing_data']=$missing ? 1 : 0;
        $effective['tariff_basis']=$type;
        return $effective;
    }

    public static function apply(array $legs,array $parcel=[]): array
    {
        $effective=[];
        foreach ($legs as $leg) {
            $tariff=self::leg($leg,$parcel);
            if ($tariff!==null) $effective[]=$tariff;
        }
        return $effective;
    }

    public static function totals(array $edges): array
    {
        $min=0.0; $max=0.0; $estimate=false; $missing=false;
        foreach ($edges as $edge) {
            $min+=(float)($edge['tariff_min'] ?? $edge['price']);
            $max+=(float)($edge['tariff_max'] ?? $edge['price']);
            $estimate=$estimate || !empty($edge['tariff_estimate']);
            $missing=$missing || !empty($edge['tariff_missing_data']);
        }
        return ['min'=>round($min,2),'max'=>round($max,2),
            'estimate'=>$estimate,'missing_data'=>$missing];
    }
}
