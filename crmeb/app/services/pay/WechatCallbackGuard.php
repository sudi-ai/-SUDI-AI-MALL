<?php
namespace app\services\pay;

class WechatCallbackGuard
{
    // Called only after the payment SDK has verified the notification signature.
    public static function amount(array $notify, array $allowedAppIds): ?string
    {
        $appid = !empty($notify['sub_appid']) ? $notify['sub_appid'] : ($notify['appid'] ?? '');
        if (!is_string($appid) || $appid === '' || !in_array($appid, array_filter($allowedAppIds), true)) return null;
        $cents = $notify['total_fee'] ?? null;
        if ((!is_int($cents) && !is_string($cents)) || !preg_match('/^\d+$/D', (string)$cents)) return null;
        return bcdiv((string)$cents, '100', 2);
    }
}
