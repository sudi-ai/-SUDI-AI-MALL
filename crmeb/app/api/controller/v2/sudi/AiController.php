<?php

declare(strict_types=1);

namespace app\api\controller\v2\sudi;

use app\Request;
use app\services\sudi\SudiAiCapabilityService;
use app\services\sudi\SudiAiGuardService;
use app\services\sudi\SudiAiProductSearchService;

/**
 * Public entry point for SUDI AI capabilities.
 * No transaction state is mutated here.
 */
class AiController
{
    public function capabilities()
    {
        /** @var SudiAiCapabilityService $service */
        $service = app()->make(SudiAiCapabilityService::class);
        return app('json')->success([
            'version' => '1.0.0',
            'capabilities' => $service->all(),
        ]);
    }

    public function capability(string $name)
    {
        /** @var SudiAiCapabilityService $service */
        $service = app()->make(SudiAiCapabilityService::class);
        $capability = $service->get($name);
        if (!$capability) {
            return app('json')->fail('AI能力不存在');
        }
        return app('json')->success($capability);
    }

    public function productSearch(Request $request)
    {
        [$keyword, $page, $limit] = $request->getMore([
            ['keyword', ''], ['page', 1], ['limit', 12]
        ], true);
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return app('json')->fail('请输入商品关键词');
        }

        /** @var SudiAiProductSearchService $service */
        $service = app()->make(SudiAiProductSearchService::class);
        return app('json')->success($service->search($keyword, (int)$page, (int)$limit));
    }

    public function guard(Request $request)
    {
        [$action] = $request->postMore([['action', '']], true);
        $action = strtolower(trim((string)$action));
        if ($action === '') {
            return app('json')->fail('缺少action参数');
        }

        /** @var SudiAiGuardService $guard */
        $guard = app()->make(SudiAiGuardService::class);
        return app('json')->success([
            'action' => $action,
            'allowed' => $guard->canExecute($action),
        ]);
    }
}
