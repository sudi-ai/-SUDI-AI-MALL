<?php

declare(strict_types=1);

namespace app\api\controller\v2\sudi;

use app\Request;
use app\services\sudi\SudiAiCapabilityService;
use app\services\sudi\SudiAiGuardService;
use app\services\sudi\SudiAiProductSearchService;
use app\services\sudi\SudiAiShoppingAgentService;

class AiController
{
    public function capabilities()
    {
        $service = app()->make(SudiAiCapabilityService::class);
        return app('json')->success(['version' => '1.0.0', 'capabilities' => $service->all()]);
    }

    public function capability(string $name)
    {
        $service = app()->make(SudiAiCapabilityService::class);
        $capability = $service->get($name);
        return $capability ? app('json')->success($capability) : app('json')->fail('AI能力不存在');
    }

    public function productSearch(Request $request)
    {
        [$keyword, $page, $limit] = $request->getMore([['keyword', ''], ['page', 1], ['limit', 12]], true);
        $keyword = trim((string)$keyword);
        if ($keyword === '') return app('json')->fail('请输入商品关键词');
        $service = app()->make(SudiAiProductSearchService::class);
        return app('json')->success($service->search($keyword, (int)$page, (int)$limit));
    }

    public function shopping(Request $request)
    {
        [$query, $page, $limit] = $request->postMore([['query', ''], ['page', 1], ['limit', 12]], true);
        $query = trim((string)$query);
        if ($query === '') return app('json')->fail('请描述你想买什么');
        $service = app()->make(SudiAiShoppingAgentService::class);
        return app('json')->success($service->recommend($query, (int)$page, (int)$limit));
    }

    public function guard(Request $request)
    {
        [$action] = $request->postMore([['action', '']], true);
        $action = strtolower(trim((string)$action));
        if ($action === '') return app('json')->fail('缺少action参数');
        $guard = app()->make(SudiAiGuardService::class);
        return app('json')->success(['action' => $action, 'allowed' => $guard->canExecute($action)]);
    }
}
