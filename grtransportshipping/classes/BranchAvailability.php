<?php
/**
 * Optional physical-branch eligibility inside ONE PrestaShop shop.
 * Never edits stock_available, Cart, or PrestaShop's normal stock checks.
 *
 * No branches => null (old availability-blind hub model).
 * Branches but no single branch with every exact cart product/combination =>
 * [] eligible origins (network refused; flat Routes/other carriers unaffected).
 */
final class GrTransportBranchAvailability
{
    public static function eligible($cart,array $branches,array $stock): ?array
    {
        if (!$branches) return null;
        if (!is_object($cart) || !method_exists($cart,'getProducts')) {
            return ['origin_ids'=>[],'by_origin'=>[],'branch_ids'=>[]];
        }
        $lines=$cart->getProducts();
        if (!is_array($lines) || !$lines) {
            return ['origin_ids'=>[],'by_origin'=>[],'branch_ids'=>[]];
        }
        $needed=[];
        foreach ($lines as $line) {
            if (!is_array($line)) return ['origin_ids'=>[],'by_origin'=>[],'branch_ids'=>[]];
            $product=$line['id_product'] ?? null;
            $attribute=$line['id_product_attribute'] ?? 0;
            $qty=$line['cart_quantity'] ?? $line['quantity'] ?? null;
            if (!is_numeric($product) || (int)$product<=0
                || !is_numeric($attribute) || (int)$attribute<0
                || !is_numeric($qty) || (int)$qty<=0 || (float)(int)$qty!==(float)$qty
                || (float)(int)$product!==(float)$product
                || (float)(int)$attribute!==(float)$attribute) {
                return ['origin_ids'=>[],'by_origin'=>[],'branch_ids'=>[]];
            }
            $key=(int)$product.':'.(int)$attribute;
            $needed[$key]=($needed[$key] ?? 0)+(int)$qty;
        }
        $byBranch=[];
        foreach ($stock as $entry) {
            $branch=(int)($entry['id_branch'] ?? 0);
            $product=(int)($entry['id_product'] ?? 0);
            $attribute=(int)($entry['id_product_attribute'] ?? 0);
            $quantity=(int)($entry['quantity'] ?? 0);
            if ($branch<=0 || $product<=0 || $attribute<0 || $quantity<0) continue;
            $byBranch[$branch][$product.':'.$attribute]=$quantity;
        }
        $sorted=$branches;
        usort($sorted,static function ($a,$b) {
            return (int)$a['id_branch'] <=>(int)$b['id_branch'];
        });
        $origins=[];$byOrigin=[];$ids=[];
        foreach ($sorted as $branch) {
            $branchId=(int)($branch['id_branch'] ?? 0);
            $origin=(int)($branch['origin_station_id'] ?? 0);
            if ($branchId<=0 || $origin<=0) continue;
            foreach ($needed as $key=>$required) {
                if (($byBranch[$branchId][$key] ?? 0)<$required) continue 2;
            }
            $ids[]=$branchId;
            if (!isset($byOrigin[$origin])) {
                $origins[]=$origin;
                $byOrigin[$origin]=['id_branch'=>$branchId,
                    'name'=>(string)($branch['name'] ?? '')];
            }
        }
        return ['origin_ids'=>$origins,'by_origin'=>$byOrigin,'branch_ids'=>$ids];
    }
}
