<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * SUDI AI safety boundary.
 *
 * AI features may recommend, explain and assist, but deterministic commerce
 * state remains owned by CRMEB. Unknown actions are denied by default.
 */
class SudiAiGuardService
{
    private const ALLOWED_ACTIONS = [
        'search_product',
        'recommend_product',
        'size_advice',
        'outfit_advice',
        'customer_reply',
        'store_diagnose',
    ];

    public function canExecute(string $action): bool
    {
        return in_array(strtolower(trim($action)), self::ALLOWED_ACTIONS, true);
    }

    public function assertExecutable(string $action): void
    {
        if (!$this->canExecute($action)) {
            throw new \DomainException('SUDI AI action is not explicitly allowed: ' . $action);
        }
    }

    public function allowedActions(): array
    {
        return self::ALLOWED_ACTIONS;
    }
}
