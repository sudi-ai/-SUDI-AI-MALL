<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Orchestrates deterministic shopping intent and read-only catalogue search.
 */
class SudiAiShoppingAgentService
{
    /** @var SudiAiIntentService */
    private $intent;

    /** @var SudiAiProductSearchService */
    private $search;

    public function __construct(SudiAiIntentService $intent, SudiAiProductSearchService $search)
    {
        $this->intent = $intent;
        $this->search = $search;
    }

    public function recommend(string $query, int $page = 1, int $limit = 12): array
    {
        $intent = $this->intent->parse($query);
        $result = $this->search->search($intent['keyword'], $page, $limit, [
            'price_min' => $intent['price_min'],
            'price_max' => $intent['price_max'],
            'sort' => $intent['sort'],
        ]);

        return [
            'intent' => $intent,
            'products' => $result['list'],
            'count' => count($result['list']),
            'page' => $result['page'],
            'limit' => $result['limit'],
            'transaction_owner' => 'CRMEB',
        ];
    }
}
