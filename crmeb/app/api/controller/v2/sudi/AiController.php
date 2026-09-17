<?php

declare(strict_types=1);

namespace app\api\controller\v2\sudi;

use app\Request;
use app\services\sudi\SudiAiCapabilityService;
use app\services\sudi\SudiAiCustomerService;
use app\services\sudi\SudiAiOutfitService;
use app\services\sudi\SudiAiProductSearchService;
use app\services\sudi\SudiAiShoppingAgentService;
use app\services\sudi\SudiAiSizeAdvisorService;

class AiController
{
    public function capabilities()
    {
        $service = app()->make(SudiAiCapabilityService::class);
        return app('json')->success([
            'version' => '1.0.0',
            'capabilities' => $service->all(),
        ]);
    }

    public function capability(string $name)
    {
        $capability = app()->make(SudiAiCapabilityService::class)->get($name);
        return $capability
            ? app('json')->success($capability)
            : app('json')->fail('AI能力不存在');
    }

    public function productSearch(Request $request)
    {
        [$keyword, $page, $limit] = $request->getMore([
            ['keyword', ''],
            ['page', 1],
            ['limit', 12],
        ], true);

        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return app('json')->fail('请输入商品关键词');
        }
        if (mb_strlen($keyword) > 100) {
            return app('json')->fail('商品关键词过长');
        }

        $service = app()->make(SudiAiProductSearchService::class);
        return app('json')->success($service->search($keyword, (int)$page, (int)$limit));
    }

    public function shopping(Request $request)
    {
        [$query, $page, $limit] = $request->postMore([
            ['query', ''],
            ['page', 1],
            ['limit', 12],
        ], true);

        $query = trim((string)$query);
        if ($query === '') {
            return app('json')->fail('请描述你想买什么');
        }
        if (mb_strlen($query) > 200) {
            return app('json')->fail('购物需求描述过长');
        }

        $service = app()->make(SudiAiShoppingAgentService::class);
        return app('json')->success($service->recommend($query, (int)$page, (int)$limit));
    }

    public function sizeAdvice(Request $request)
    {
        [$profile, $sizeChart] = $request->postMore([
            ['profile', []],
            ['size_chart', []],
        ], true);

        if (!is_array($profile) || !is_array($sizeChart)) {
            return app('json')->fail('尺码参数格式错误');
        }
        if (count($sizeChart) > 30) {
            return app('json')->fail('尺码表数据过多');
        }

        $service = app()->make(SudiAiSizeAdvisorService::class);
        return app('json')->success($service->advise($profile, $sizeChart));
    }

    public function outfit(Request $request)
    {
        [$anchor, $categories] = $request->postMore([
            ['anchor', ''],
            ['categories', []],
        ], true);

        $anchor = trim((string)$anchor);
        if ($anchor === '') {
            return app('json')->fail('请输入搭配需求');
        }
        if (mb_strlen($anchor) > 100) {
            return app('json')->fail('搭配需求过长');
        }
        if (!is_array($categories)) {
            $categories = [];
        }

        $service = app()->make(SudiAiOutfitService::class);
        return app('json')->success($service->recommend($anchor, array_slice($categories, 0, 4)));
    }

    public function customer(Request $request)
    {
        [$message] = $request->postMore([['message', '']], true);
        $message = trim((string)$message);
        if ($message === '') {
            return app('json')->fail('请输入咨询内容');
        }
        if (mb_strlen($message) > 500) {
            return app('json')->fail('咨询内容过长');
        }

        $service = app()->make(SudiAiCustomerService::class);
        return app('json')->success($service->route($message));
    }
}
