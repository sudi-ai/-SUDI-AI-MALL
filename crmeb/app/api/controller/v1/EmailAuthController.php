<?php
namespace app\api\controller\v1;

use app\Request;
use app\services\email\EmailVerificationServices;
use app\services\user\LoginServices;
use crmeb\services\CacheService;

class EmailAuthController
{
    public function verify(Request $request, EmailVerificationServices $verification, LoginServices $login)
    {
        [$email, $type] = $request->postMore([['email', ''], ['type', 'register']], true);
        if ($type !== 'register') return app('json')->fail('不支持的邮箱验证码用途');
        try {
            $verification->sendRegistrationCode($email, $login);
            return app('json')->success('如果该邮箱可注册，验证码已发送');
        } catch (\Throwable $e) {
            // Do not log email codes, SMTP credentials, or provider responses.
            if ($e instanceof \InvalidArgumentException) return app('json')->fail($e->getMessage());
            if ($e->getMessage() === '邮箱已注册') return app('json')->fail('邮箱已注册');
            \think\facade\Log::warning('Email verification delivery failed');
            return app('json')->fail('邮件发送失败，请检查邮箱服务配置后重试');
        }
    }

    public function register(Request $request, EmailVerificationServices $verification, LoginServices $login)
    {
        [$email, $captcha, $password, $spread] = $request->postMore([
            ['email', ''], ['captcha', ''], ['password', ''], ['spread', 0],
        ], true);
        $email = strtolower(trim((string)$email));
        if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return app('json')->fail('邮箱格式不正确');
        if (!preg_match('/^\d{6}$/', (string)$captcha)) return app('json')->fail('验证码必须为6位数字');
        if (strlen((string)$password) < 8 || strlen((string)$password) > 72 || trim((string)$password) !== (string)$password) return app('json')->fail('密码长度须为8到72位');
        if (in_array(strtolower((string)$password), ['12345678', 'password', 'qwerty123'], true)) return app('json')->fail('密码过于简单');
        if (!$verification->verifyRegistrationCode($email, (string)$captcha)) return app('json')->fail('验证码错误或已过期');
        try {
            $login->registerEmail($email, (string)$password, (int)$spread);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage() === '邮箱已注册' ? '邮箱已注册' : '注册失败，请稍后重试');
        }
        $verification->clearRegistrationCode($email);
        CacheService::delete('email.register.minute.' . hash('sha256', $email . date('YmdHi')));
        return app('json')->success('注册成功');
    }
}
