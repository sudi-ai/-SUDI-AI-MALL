<?php

declare(strict_types=1);

namespace app\services\sudi;

use app\services\product\product\StoreProductServices;

/**
 * Read-only adapter between SUDI AI and the CRMEB product catalogue.
 */
class SudiAiProductSearchService
{
    /** @var StoreProductServices */
    private $products;

    public function __construct(StoreProductServices $products)
    {
        $this->products = $products;
    }

    public function search(string $keyword, int $page = 1, int $limit = 12, array $filters = []): array
    {
        $keyword = trim($keyword);
        $page = max(1, $page);
        $limit = max(1, min(30, $limit));

        if ($keyword === '') {
            return ['list' => [], 'page' => $page, 'limit' => $limit, 'keyword' => ''];
        }

        $where = [
            'store_name' => $keyword,
            'is_show' => 1,
            'is_del' => 0,
            'stock_s' => [1, ''],
        ];

        $minPrice = $filters['price_min'] ?? null;
        $maxPrice = $filters['price_max'] ?? null;
        if ($minPrice !== null || $maxPrice !== null) {
            $where['price_s'] = [
                $minPrice !== null ? max(0, (float)$minPrice) : '',
                $maxPrice !== null ? max(0, (float)$maxPrice) : '',
            ];
        }

        $sort = (string)($filters['sort'] ?? 'relevance');
        if ($sort === 'sales') {
            $where['salesOrder'] = 'desc';
        } elseif ($sort === 'price_asc') {
            $where['priceOrder'] = 'asc';
        }

        $fields = ['id', 'store_name', 'image', 'price', 'ot_price', 'sales', 'stock', 'cate_id'];
        $list = $this->products->getSearchList($where, $page, $limit, $fields);
        $list = is_array($list) ? $list : [];

        foreach ($list as &$item) {
            if (isset($item['image'])) {
                $item['image'] = set_file_url($item['image']);
            }
        }
        unset($item);

        return [
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'keyword' => $keyword,
        ];
    }
}
