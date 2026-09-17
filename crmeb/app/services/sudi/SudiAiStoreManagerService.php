<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Produces deterministic store diagnostics from CRMEB metrics.
 * This service is not exposed to the customer-facing API.
 */
class SudiAiStoreManagerService
{
    public function diagnose(array $metrics): array
    {
        $orders = (int)$this->number($metrics, 'orders');
        $visitors = (int)$this->number($metrics, 'visitors');
        $refunds = (int)$this->number($metrics, 'refunds');
        $revenue = $this->number($metrics, 'revenue');

        $conversion = $visitors > 0 ? $orders / $visitors : 0.0;
        $refundRate = $orders > 0 ? $refunds / $orders : 0.0;
        $aov = $orders > 0 ? $revenue / $orders : 0.0;

        $alerts = [];
        if ($visitors >= 100 && $conversion < 0.01) {
            $alerts[] = [
                'type' => 'conversion',
                'level' => 'warning',
                'text' => '流量已有规模但成交偏低，优先检查商品匹配、价格和详情页',
            ];
        }
        if ($orders >= 20 && $refundRate > 0.20) {
            $alerts[] = [
                'type' => 'refund',
                'level' => 'warning',
                'text' => '退款/退货比例偏高，优先检查尺码、描述一致性和商品质量',
            ];
        }

        $dataQuality = [];
        if ($refunds > $orders && $orders > 0) {
            $dataQuality[] = 'refunds_exceed_orders';
        }

        return [
            'kpi' => [
                'conversion_rate' => round($conversion, 4),
                'refund_rate' => round($refundRate, 4),
                'aov' => round($aov, 2),
            ],
            'alerts' => $alerts,
            'data_quality' => $dataQuality,
            'source' => 'CRMEB metrics',
            'automatic_transaction_changes' => false,
        ];
    }

    private function number(array $metrics, string $key): float
    {
        if (!isset($metrics[$key]) || !is_numeric($metrics[$key])) {
            return 0.0;
        }
        return max(0.0, (float)$metrics[$key]);
    }
}
