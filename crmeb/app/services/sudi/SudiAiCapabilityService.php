<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Central registry for SUDI AI capabilities.
 * Keeps AI additions isolated from CRMEB transaction services.
 */
class SudiAiCapabilityService
{
    private const CAPABILITIES = [
        'shopping_agent' => ['name' => 'AI购物代理', 'enabled' => true],
        'image_search'   => ['name' => '图搜商品', 'enabled' => true],
        'size_advisor'   => ['name' => 'AI尺码助手', 'enabled' => true],
        'outfit_advisor' => ['name' => 'AI搭配购买', 'enabled' => true],
        'customer_ai'    => ['name' => 'AI客服', 'enabled' => true],
        'store_manager'  => ['name' => 'AI店长', 'enabled' => true],
    ];

    public function all(): array
    {
        return self::CAPABILITIES;
    }

    public function isEnabled(string $capability): bool
    {
        return (bool)(self::CAPABILITIES[$capability]['enabled'] ?? false);
    }

    public function get(string $capability): ?array
    {
        return self::CAPABILITIES[$capability] ?? null;
    }
}
