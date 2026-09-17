<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Lightweight deterministic intent parser.
 * LLM providers can enrich this later without changing the commerce contract.
 */
class SudiAiIntentService
{
    public function parse(string $query): array
    {
        $query = trim($query);
        $intent = [
            'query' => $query,
            'keyword' => $this->extractKeyword($query),
            'price_min' => null,
            'price_max' => null,
            'sort' => 'relevance',
        ];

        if (preg_match('/(\d+(?:\.\d+)?)\s*[-~到至]\s*(\d+(?:\.\d+)?)/u', $query, $m)) {
            $intent['price_min'] = min((float)$m[1], (float)$m[2]);
            $intent['price_max'] = max((float)$m[1], (float)$m[2]);
        } elseif (preg_match('/(?:不超过|以内|以下|低于)\s*(\d+(?:\.\d+)?)/u', $query, $m)) {
            $intent['price_max'] = (float)$m[1];
        } elseif (preg_match('/(?:至少|以上|高于)\s*(\d+(?:\.\d+)?)/u', $query, $m)) {
            $intent['price_min'] = (float)$m[1];
        }

        if (preg_match('/热销|销量|卖得好/u', $query)) {
            $intent['sort'] = 'sales';
        } elseif (preg_match('/便宜|低价|价格低/u', $query)) {
            $intent['sort'] = 'price_asc';
        }

        return $intent;
    }

    private function extractKeyword(string $query): string
    {
        $clean = preg_replace('/\d+(?:\.\d+)?\s*[-~到至]\s*\d+(?:\.\d+)?|(?:不超过|以内|以下|低于|至少|以上|高于)\s*\d+(?:\.\d+)?/u', ' ', $query);
        $clean = preg_replace('/帮我|推荐|找|看看|想要|我要|热销|销量|卖得好|便宜|低价|价格低/u', ' ', (string)$clean);
        $clean = trim((string)preg_replace('/\s+/u', ' ', (string)$clean));
        return $clean !== '' ? $clean : $query;
    }
}
