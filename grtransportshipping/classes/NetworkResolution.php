<?php
require_once __DIR__ . '/NetworkGraph.php';

/**
 * Reachable coverage resolution used by the quote and by the map's seam plan.
 * Explicit link precedence, then patch precedence, then the old cheapest rule.
 * All comparisons are scoped to patches that actually reach the point.
 */
final class GrTransportNetworkResolution
{
    private static function patches(array $network): array
    {
        $patches=[];
        foreach ($network['stations'] ?? [] as $station) {
            $patches['station:'.(int)$station['id_station']]=[
                'precedence'=>$station['patch_precedence'] ?? 'none',
                'radius_km'=>GrTransportNetworkSettings::effectiveOuterKm(
                    $station['outer_radius_km'] ?? null,(float)$station['inner_radius_km']),
            ];
        }
        foreach ($network['legs'] ?? [] as $leg) {
            if (!empty($leg['to_station_id']) || !isset($leg['area_lat'],$leg['area_lng'])) continue;
            $patches['area:'.(int)$leg['id_leg']]=[
                'precedence'=>$leg['patch_precedence'] ?? 'none',
                'radius_km'=>GrTransportNetworkSettings::effectiveOuterKm(
                    $leg['area_outer_radius_km'] ?? null,(float)$leg['area_radius_km']),
            ];
        }
        return $patches;
    }

    /** Connect a link's two actual coverage patches; station legs and direct-area services are both links. */
    private static function links(array $network): array
    {
        $links=[];
        foreach ($network['legs'] ?? [] as $leg) {
            $from='station:'.(int)$leg['from_station_id'];
            $to=!empty($leg['to_station_id'])
                ? 'station:'.(int)$leg['to_station_id'] : 'area:'.(int)$leg['id_leg'];
            $links[]=['kind'=>'leg','id'=>(int)$leg['id_leg'],'from'=>$from,'to'=>$to,
                'override_winner'=>$leg['override_winner'] ?? 'none'];
        }
        foreach ($network['transfers'] ?? [] as $transfer) {
            $links[]=['kind'=>'transfer','id'=>(int)$transfer['id_transfer'],
                'from'=>'station:'.(int)$transfer['from_station_id'],
                'to'=>'station:'.(int)$transfer['to_station_id'],
                'override_winner'=>$transfer['override_winner'] ?? 'none'];
        }
        usort($links,static function ($a,$b) {
            return [$a['kind'],$a['id']] <=> [$b['kind'],$b['id']];
        });
        return $links;
    }

    /**
     * @param array $choices options containing patch_key, comparison_cost, priority and quote.
     * @return array {choice, winner_key, reason, reach_boundary_km, link_kind?, link_id?, link_target?}
     */
    public static function choose(array $choices,array $network): array
    {
        if (!$choices) throw new InvalidArgumentException('Cannot resolve empty coverage');
        $patches=self::patches($network);
        $available=[];
        foreach ($choices as $option) $available[$option['patch_key']]=$option;
        // Link decisions override the generic patch ranking, only when both ends reach.
        // If an admin configures contradictory links, kind then ID is the stable tie-break.
        foreach (self::links($network) as $link) {
            if (!in_array($link['override_winner'],['from','to'],true)
                || !isset($available[$link['from']],$available[$link['to']])) continue;
            $key=$link[$link['override_winner']];
            return ['choice'=>$available[$key],'winner_key'=>$key,'reason'=>'link_override',
                'reach_boundary_km'=>$patches[$key]['radius_km'] ?? 0.0,
                'link_kind'=>$link['kind'],'link_id'=>$link['id'],
                'link_target'=>$link['override_winner']];
        }
        usort($choices,static function ($a,$b) use ($patches) {
            $weight=['yield'=>-1,'none'=>0,'override'=>1];
            $aWeight=$weight[$patches[$a['patch_key']]['precedence'] ?? 'none'] ?? 0;
            $bWeight=$weight[$patches[$b['patch_key']]['precedence'] ?? 'none'] ?? 0;
            if ($aWeight!==$bWeight) return $bWeight <=> $aWeight;
            $price=$a['comparison_cost'] <=> $b['comparison_cost'];
            if ($price!==0) return $price;
            $convenience=$a['priority'] <=> $b['priority'];
            if ($convenience!==0) return $convenience;
            return ((int)($a['quote']['destination_station_id'] ?? 0))
                <=> ((int)($b['quote']['destination_station_id'] ?? 0));
        });
        $first=$choices[0];
        $hasSpecial=false;
        foreach ($choices as $choice) {
            if (($patches[$choice['patch_key']]['precedence'] ?? 'none')!=='none') {
                $hasSpecial=true;
            }
        }
        return ['choice'=>$first,'winner_key'=>$first['patch_key'],
            'reason'=>$hasSpecial ? 'patch_precedence' : 'cheapest',
            'reach_boundary_km'=>$patches[$first['patch_key']]['radius_km'] ?? 0.0];
    }

    /** Pre-resolved winner and reach boundary for each configured join. The renderer consumes
     * this result; it must not contain a second, independent cheapest-wins decision.
     */
    public static function mapPlan(array $network,array $cost): array
    {
        $options=[];
        foreach ($network['stations'] ?? [] as $station) {
            $id=(int)$station['id_station'];
            if (!isset($cost[$id]) || !is_finite((float)$cost[$id])) continue;
            $options['station:'.$id]=[
                'patch_key'=>'station:'.$id, 'comparison_cost'=>(float)$cost[$id],
                'priority'=>1,'quote'=>['destination_station_id'=>$id]
            ];
        }
        foreach ($network['legs'] ?? [] as $leg) {
            if (!empty($leg['to_station_id']) || !isset($leg['area_lat'],$leg['area_lng'])) continue;
            $from=(int)$leg['from_station_id'];
            if (!isset($cost[$from]) || !is_finite((float)$cost[$from])) continue;
            $id=(int)$leg['id_leg'];
            $options['area:'.$id]=[
                'patch_key'=>'area:'.$id,
                'comparison_cost'=>(float)$cost[$from]+(float)$leg['price'],
                'priority'=>0,'quote'=>['destination_station_id'=>null],
            ];
        }
        $result=[];
        foreach (self::links($network) as $link) {
            $key=$link['kind'].':'.$link['id'];
            if (!isset($options[$link['from']],$options[$link['to']])) continue;
            $resolved=self::choose([$options[$link['from']],$options[$link['to']]],$network);
            // Neutral equal-cost linked patches are #106's merged service, not a new seam.
            if ($resolved['reason']==='cheapest' &&
                abs($options[$link['from']]['comparison_cost'] - $options[$link['to']]['comparison_cost'])<=0.005) continue;
            $result[$key]=[
                'winner_key'=>$resolved['winner_key'],
                'reach_boundary_km'=>$resolved['reach_boundary_km'],
                'reason'=>$resolved['reason'],
            ];
        }
        // Order all painted patches from yielding/high-cost to winning/low-cost.
        // Explicit linked winners move after their yielding partner. This is a
        // server resolution result, not a JS renderer pricing heuristic.
        $weights=['yield'=>-1,'none'=>0,'override'=>1];
        $patches=self::patches($network);
        $paint=array_values($options);
        // Preserve #106's stable source order for equal-cost neutral patches.
        $sourceOrder=array_flip(array_keys($options));
        usort($paint,static function ($a,$b) use ($patches,$weights,$sourceOrder) {
            $wa=$weights[$patches[$a['patch_key']]['precedence'] ?? 'none'] ?? 0;
            $wb=$weights[$patches[$b['patch_key']]['precedence'] ?? 'none'] ?? 0;
            if ($wa!==$wb) return $wa <=> $wb;
            $price=$b['comparison_cost'] <=> $a['comparison_cost'];
            if ($price!==0) return $price;
            return $sourceOrder[$a['patch_key']] <=> $sourceOrder[$b['patch_key']];
        });
        $order=array_column($paint,'patch_key');
        foreach (self::links($network) as $link) {
            if (!in_array($link['override_winner'],['from','to'],true)) continue;
            $winner=$link[$link['override_winner']];
            $loser=$link[$link['override_winner']==='from' ? 'to' : 'from'];
            $a=array_search($winner,$order,true);
            $b=array_search($loser,$order,true);
            if ($a!==false && $b!==false && $a<$b) {
                array_splice($order,$a,1);
                $b=array_search($loser,$order,true);
                array_splice($order,$b+1,0,[$winner]);
            }
        }
        return ['links'=>$result,'paint_order'=>$order];
    }
}
