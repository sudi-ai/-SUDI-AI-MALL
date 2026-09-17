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

        $normalized = [];
        foreach ($categories as $category) {
            if (!is_scalar($category)) {
                continue;
            }
            $category = trim((string)$category);
            if ($category === '') {
                continue;
            }
            $category = mb_substr($category, 0, 30);
            $normalized[$category] = $category;
            if (count($normalized) >= 4) {
                break;
            }
        }

        $groups = [];
        foreach (array_values($normalized) as $category) {
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
