<?php
namespace app\services\user;

use crmeb\exceptions\ApiException;

class SmsRateLimitException extends ApiException
{
    public $retryAfter;
    public $reason;

    public function __construct(string $message, int $retryAfter, string $reason)
    {
        $this->retryAfter = max(1, $retryAfter);
        $this->reason = $reason;
        parent::__construct($message);
    }
}
