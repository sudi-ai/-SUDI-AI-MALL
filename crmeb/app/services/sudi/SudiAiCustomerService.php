<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Read-only customer-service router; sensitive order actions stay in CRMEB.
 */
class SudiAiCustomerService
{
    public function route(string $message): array
    {
        $message = trim($message);
        $intent = 'general';
        $handoff = false;

        if (preg_match('/退款|退货|售后|换货/u', $message)) {
            $intent = 'after_sale';
            $handoff = true;
        } elseif (preg_match('/付款|支付|扣款|支付失败/u', $message)) {
            $intent = 'payment';
            $handoff = true;
        } elseif (preg_match('/取消订单|修改订单|改地址|订单异常/u', $message)) {
            $intent = 'order_action';
            $handoff = true;
        } elseif (preg_match('/物流|快递|发货|到哪/u', $message)) {
            $intent = 'shipping';
        } elseif (preg_match('/尺码|多大|大小/u', $message)) {
            $intent = 'size';
        } elseif (preg_match('/库存|有货|缺货/u', $message)) {
            $intent = 'stock';
        } elseif (preg_match('/优惠|优惠券|券|便宜/u', $message)) {
            $intent = 'promotion';
        }

        return [
            'intent' => $intent,
            'handoff_to_crmeb' => $handoff,
            'message' => $message,
            'policy' => 'AI仅解释和分流；订单、退款、支付等操作由CRMEB执行',
        ];
    }
}
