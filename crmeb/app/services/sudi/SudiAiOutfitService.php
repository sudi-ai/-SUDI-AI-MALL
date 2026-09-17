<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Builds outfit suggestions only from products that actually exist in CRMEB.
 */
class SudiAiOutfitService
{
    /** @var SudiAiProductSearchService */
    private $search;

    public function __construct(SudiAiProductSearchService $search)
    {
        $this->search = $search;
    }

    public function recommend(string $anchor, array $categories = []): array
    {
        $anchor = trim($anchor);
        $categories = $categories ?: ['上衣', '裤子', '外套', '鞋'];
        $categories = array_values(array_unique(array_filter(array_map('strval', $categories))));
        $groups = [];

        foreach (array_slice($categories, 0, 4) as $category) {
            $category = trim($category);
            if ($category === '') {
                continue;
            }

            $keyword = trim($anchor . ' ' . $category);
            $result = $this->search->search($keyword, 1, 4);
            if (!$result['list']) {
                $result = $this->search->search($category, 1, 4);
            }

            $groups[] = [
                'category' => $category,
                'products' => $result['list'],
            ];
        }

        return [
            'anchor' => $anchor,
            'groups' => $groups,
            'note' => '搭配仅从商城真实在售商品中生成，最终价格和库存以CRMEB结算页为准',
        ];
    }
}
