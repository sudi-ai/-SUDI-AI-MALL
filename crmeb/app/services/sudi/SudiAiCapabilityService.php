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
        'shopping_agent' => ['name' => 'AI购物代理', 'enabled' => true, 'status' => 'available'],
        'image_search'   => ['name' => '图搜商品', 'enabled' => false, 'status' => 'planned'],
        'size_advisor'   => ['name' => 'AI尺码助手', 'enabled' => true, 'status' => 'available'],
        'outfit_advisor' => ['name' => 'AI搭配购买', 'enabled' => true, 'status' => 'available'],
        'customer_ai'    => ['name' => 'AI客服', 'enabled' => true, 'status' => 'available'],
        'store_manager'  => ['name' => 'AI店长', 'enabled' => false, 'status' => 'admin_pending'],
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
