<?php

declare(strict_types=1);

namespace app\api\controller\v2\sudi;

use app\Request;
use app\services\sudi\SudiAiCapabilityService;
use app\services\sudi\SudiAiGuardService;

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
