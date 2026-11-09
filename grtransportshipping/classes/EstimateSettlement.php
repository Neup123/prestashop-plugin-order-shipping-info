<?php
require_once __DIR__ . '/OrderSnapshot.php';
require_once __DIR__ . '/NetworkSettings.php';

/**
 * Records an already completed, independently verified payment transaction.
 * This module is not a payment gateway and MUST NOT claim to capture funds.
 */
final class GrTransportEstimateSettlement
{
    public const PENDING='pending';
    public const SETTLED='settled';

    public static function validate(float $amount,string $receipt,int $employeeId,
        float $estimateLow,float $estimateHigh): array
    {
        if ($employeeId<=0) throw new InvalidArgumentException('A signed-in employee is required');
        if (!is_finite($amount) || $amount<=0 || $amount>GrTransportNetworkSettings::get('max_freight_eur')) {
            throw new InvalidArgumentException('Actual shipping receipt must be a positive finite EUR amount');
        }
        $receipt=trim($receipt);
        if ($receipt==='' || strlen($receipt)>190 || !preg_match('/^[a-zA-Z0-9 _:\/.-]+$/D',$receipt)) {
            throw new InvalidArgumentException('Valid external payment receipt/reference is required');
        }
        if ($estimateHigh>0 && $amount>$estimateHigh+GrTransportOrderCharge::TOLERANCE) {
            throw new InvalidArgumentException('Actual freight exceeds approved estimate ceiling; obtain a revised customer approval first');
        }
        return ['amount'=>round($amount,2),'reference'=>$receipt];
    }

    /**
     * Acknowledges external capture after actual funds are collected outside
     * this carrier module. The original route snapshot and checkout charge
     * remain untouched, while an auditable separate settlement record is made.
     */
    public static function recordExternalReceipt(int $orderId,float $amount,string $receipt,int $employeeId): array
    {
        if ($orderId<=0) throw new InvalidArgumentException('Valid order ID required');
        $order=new Order($orderId);
        if (!Validate::isLoadedObject($order)
            || !GrTransportOrderSnapshot::belongsToCarrier((int)$order->id_carrier)) {
            throw new InvalidArgumentException('Order is not owned by transport carrier');
        }
        $info=GrTransportOrderSnapshot::find($orderId);
        if (!$info) throw new RuntimeException('Original transport snapshot is missing');
        $checked=self::validate($amount,$receipt,$employeeId,
            (float)($info['estimate_min'] ?? 0),(float)($info['estimate_max'] ?? 0));
        if ($info['estimate_state']===self::SETTLED) {
            if ((float)$info['actual_shipping_charge']===$checked['amount']
                && (string)$info['external_receipt']===$checked['reference']) return $info;
            throw new RuntimeException('An estimate payment is already recorded; duplicate charge rejected');
        }
        if ($info['estimate_state']!==self::PENDING) {
            throw new RuntimeException('This order has no pending estimated shipping balance');
        }
        $updated=Db::getInstance()->update('grtrans_order',[
            'estimate_state'=>self::SETTLED,
            'actual_shipping_charge'=>$checked['amount'],
            'external_receipt'=>pSQL($checked['reference']),
            'settled_at'=>gmdate('Y-m-d H:i:s'),
        ],"id_order = ".$orderId." AND estimate_state = '".self::PENDING."'");
        if (!$updated) throw new RuntimeException('Could not persist confirmed freight payment');
        $saved=GrTransportOrderSnapshot::find($orderId);
        if (!$saved || $saved['estimate_state']!==self::SETTLED) {
            throw new RuntimeException('Payment receipt was not persisted');
        }
        PrestaShopLogger::addLog('External freight receipt recorded for order #'.$orderId
            .' by employee #'.$employeeId.'; amount EUR '.number_format($checked['amount'],2),1,null,'Order',$orderId);
        return $saved;
    }
}
