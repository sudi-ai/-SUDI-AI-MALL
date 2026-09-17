<?php

declare(strict_types=1);
namespace app\services\sudi;

/** Produces deterministic store diagnostics from supplied CRMEB metrics. */
class SudiAiStoreManagerService
{
    public function diagnose(array $metrics): array
    {
        $orders=max(0,(int)($metrics['orders']??0));
        $visitors=max(0,(int)($metrics['visitors']??0));
        $refunds=max(0,(int)($metrics['refunds']??0));
        $revenue=max(0,(float)($metrics['revenue']??0));
        $conversion=$visitors>0?$orders/$visitors:0.0;
        $refundRate=$orders>0?$refunds/$orders:0.0;
        $aov=$orders>0?$revenue/$orders:0.0;
        $alerts=[];
        if($visitors>=100&&$conversion<0.01)$alerts[]=['type'=>'conversion','level'=>'warning','text'=>'流量已有规模但成交偏低，优先检查商品匹配、价格和详情页'];
        if($orders>=20&&$refundRate>0.20)$alerts[]=['type'=>'refund','level'=>'warning','text'=>'退款/退货比例偏高，优先检查尺码、描述一致性和商品质量'];
        return ['kpi'=>['conversion_rate'=>round($conversion,4),'refund_rate'=>round($refundRate,4),'aov'=>round($aov,2)],'alerts'=>$alerts,'source'=>'CRMEB metrics','automatic_transaction_changes'=>false];
    }
}
