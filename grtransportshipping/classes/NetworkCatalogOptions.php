<?php
/** Read-only, bounded catalog suggestions for the merchant Configure editor. */
final class GrTransportNetworkCatalogOptions
{
    public static function fromRows(array $products, array $variants): array
    {
        $names=[];
        foreach ($products as $row) {
            $id=(int)($row['id_product'] ?? 0);
            if ($id<=0) continue;
            $names[$id]=['id'=>$id,'name'=>(string)($row['name'] ?? 'Product #'.$id)];
        }
        $options=['products'=>array_values($names),'variants'=>[]];
        foreach ($variants as $row) {
            $productId=(int)($row['id_product'] ?? 0);
            $id=(int)($row['id_product_attribute'] ?? 0);
            if ($productId<=0 || $id<=0 || !isset($names[$productId])) continue;
            $label=trim((string)($row['name'] ?? ''));
            $options['variants'][]=['product_id'=>$productId,'id'=>$id,
                'name'=>$label!==''?$label:'Variant #'.$id];
        }
        return $options;
    }

    public static function read(int $currentProduct=0): array
    {
        $ctx=Context::getContext();
        $shop=(int)($ctx->shop->id ?? 0);
        $lang=(int)($ctx->language->id ?? 0);
        if ($shop<=0 || $lang<=0) return ['products'=>[],'variants'=>[]];
        $db=Db::getInstance();
        $p=_DB_PREFIX_;
        // The suggestions are deliberately bounded. A manual product-ID fallback
        // remains available for catalog entries outside the loaded choices.
        $products=$db->executeS('SELECT p.id_product, pl.name FROM `'.$p.'product` p '
            .'JOIN `'.$p.'product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop='.$shop.' '
            .'JOIN `'.$p.'product_lang` pl ON pl.id_product=p.id_product AND pl.id_shop='.$shop
            .' AND pl.id_lang='.$lang.' ORDER BY pl.name, p.id_product LIMIT 1500');
        if (!is_array($products)) $products=[];
        $ids=array_values(array_unique(array_map('intval',array_column($products,'id_product'))));
        if ($currentProduct>0 && !in_array($currentProduct,$ids,true)) {
            $extra=$db->getRow('SELECT p.id_product, pl.name FROM `'.$p.'product` p '
                .'JOIN `'.$p.'product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop='.$shop.' '
                .'JOIN `'.$p.'product_lang` pl ON pl.id_product=p.id_product AND pl.id_shop='.$shop
                .' AND pl.id_lang='.$lang.' WHERE p.id_product='.$currentProduct);
            if (is_array($extra)) {$products[]=$extra;$ids[]=$currentProduct;}
        }
        if (!$ids) return ['products'=>[],'variants'=>[]];
        $variants=$db->executeS('SELECT pa.id_product, pa.id_product_attribute, '
            ."GROUP_CONCAT(DISTINCT al.name ORDER BY al.name SEPARATOR ' / ') AS name "
            .'FROM `'.$p.'product_attribute` pa '
            .'LEFT JOIN `'.$p.'product_attribute_combination` pac ON pac.id_product_attribute=pa.id_product_attribute '
            .'LEFT JOIN `'.$p.'attribute_lang` al ON al.id_attribute=pac.id_attribute AND al.id_lang='.$lang.' '
            .'WHERE pa.id_product IN ('.implode(',',$ids).') '
            .'GROUP BY pa.id_product, pa.id_product_attribute ORDER BY pa.id_product_attribute LIMIT 8000');
        return self::fromRows($products,is_array($variants)?$variants:[]);
    }
}
