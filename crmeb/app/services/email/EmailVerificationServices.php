<?php
namespace app\services\email;

use app\services\user\LoginServices;
use crmeb\services\CacheService;

class EmailVerificationServices
{
    public function sendRegistrationCode(string $email, LoginServices $login): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) throw new \InvalidArgumentException('邮箱格式不正确');
        if ($login->emailExists($email)) throw new \RuntimeException('邮箱已注册');

        $minuteKey = 'email.register.minute.' . hash('sha256', $email . date('YmdHi'));
        $dayKey = 'email.register.day.' . hash('sha256', $email . date('Ymd'));
        $ipKey = 'email.register.ip.' . hash('sha256', app()->request->ip() . date('Ymd'));
        if ((int)CacheService::get($minuteKey, 0) >= 1) throw new \RuntimeException('请稍后再试');
        $dayCount = (int)CacheService::get($dayKey, 0);
        $ipCount = (int)CacheService::get($ipKey, 0);
        if ($dayCount >= 8 || $ipCount >= 40) throw new \RuntimeException('验证码发送次数过多，请明天再试');

        // Reserve the rate-limit slot before contacting SMTP, including on provider failures.
        CacheService::set($minuteKey, 1, 61);
        CacheService::set($dayKey, $dayCount + 1, 86400);
        CacheService::set($ipKey, $ipCount + 1, 86400);

        $code = (string)random_int(100000, 999999);
        $mailer = new SmtpMailer((array)Config::get('email', []));
        $mailer->send($email, '苏迪商城邮箱验证码', '你的邮箱注册验证码是：' . $code . '，10分钟内有效。若非本人操作，请忽略此邮件。');
        CacheService::set('email.register.code.' . hash('sha256', $email), $this->codeHash($code), 600);
    }

    public function verifyRegistrationCode(string $email, string $code): bool
    {
        $emailHash = hash('sha256', strtolower(trim($email)));
        $stored = CacheService::get('email.register.code.' . $emailHash);
        $attemptKey = 'email.register.attempt.' . $emailHash;
        $attempts = (int)CacheService::get($attemptKey, 0);
        if ($attempts >= 6) return false;
        if ($stored !== '' && $stored !== null) CacheService::set($attemptKey, $attempts + 1, 600);
        return is_string($stored) && $stored !== '' && password_verify($code, $stored);
    }

    public function clearRegistrationCode(string $email): void
    {
        $emailHash = hash('sha256', strtolower(trim($email)));
        CacheService::delete('email.register.code.' . $emailHash);
        CacheService::delete('email.register.attempt.' . $emailHash);
    }

    private function codeHash(string $code): string
    {
        return password_hash($code, PASSWORD_DEFAULT);
    }
}
