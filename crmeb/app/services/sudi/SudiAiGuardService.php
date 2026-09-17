<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * SUDI AI safety boundary.
 *
 * AI features may recommend, explain and assist, but deterministic commerce
 * state (price, stock, payment and refund) remains owned by CRMEB core.
 */
class SudiAiGuardService
{
    private const BLOCKED_ACTIONS = [
        'change_price',
        'change_stock',
        'create_payment',
        'confirm_payment',
        'refund',
        'approve_refund',
    ];

    public function canExecute(string $action): bool
    {
        return !in_array(strtolower(trim($action)), self::BLOCKED_ACTIONS, true);
    }

    public function assertExecutable(string $action): void
    {
        if (!$this->canExecute($action)) {
            throw new \DomainException('SUDI AI is not allowed to mutate deterministic transaction state: ' . $action);
        }
    }

    public function blockedActions(): array
    {
        return self::BLOCKED_ACTIONS;
    }
}
