<?php
namespace app\services\user;

use crmeb\exceptions\ApiException;
use crmeb\services\CacheService;
use think\facade\Db;

/** A first-signup grant is bound to the verified phone, password version and login. */
class PasswordSetupServices
{
    private const EXPIRED = '设置密码许可已失效，请重新获取短信验证码后重置密码';

    private function key(int $uid, string $token): string
    {
        return 'password.setup.' . $uid . '.' . hash('sha256', $token);
    }

    public function grant(int $uid, string $phone, string $passwordHash, string $token): void
    {
        $permit = ['uid' => $uid, 'phone' => $phone, 'pwd' => $passwordHash,
            'token_sha256' => hash('sha256', $token), 'expires_at' => time() + 600];
        if (!CacheService::set($this->key($uid, $token), $permit, 600)) {
            throw new ApiException('暂时无法设置密码，请重新获取短信验证码后重置密码');
        }
    }

    public function setup(int $uid, string $token, $password, $confirmation): void
    {
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 32
            || strpos($password, "\0") !== false || preg_match('/\A\s|\s\z/u', $password) !== 0
            || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new ApiException('密码须为8至32位，包含字母和数字，首尾不能有空白');
        }
        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            throw new ApiException('两次输入的密码不一致');
        }
        $key = $this->key($uid, $token);
        $permit = CacheService::get($key);
        if ($uid <= 0 || $token === '' || !is_array($permit) || (int)($permit['uid'] ?? 0) !== $uid
            || !is_string($permit['token_sha256'] ?? null) || !hash_equals($permit['token_sha256'], hash('sha256', $token))
            || !is_string($permit['phone'] ?? null) || !preg_match('/^1[3-9]\d{9}$/D', $permit['phone'])
            || !is_string($permit['pwd'] ?? null) || $permit['pwd'] === '' || (int)($permit['expires_at'] ?? 0) <= time()) {
            throw new ApiException(self::EXPIRED);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) throw new ApiException('设置密码失败，请稍后重试');
        // The conditional UPDATE is the single-use boundary, including concurrent
        // requests and intervening SMS/email resets. Check expiry again in SQL,
        // so a request waiting on a row lock cannot use an expired grant.
        $updated = Db::name('user')->where('uid', $uid)->where('phone', $permit['phone'])
            ->where('status', 1)->where('is_del', 0)
            ->whereRaw('BINARY pwd = :setup_password_hash', ['setup_password_hash' => $permit['pwd']])
            ->whereRaw('UNIX_TIMESTAMP() < :setup_expiry', ['setup_expiry' => (int)$permit['expires_at']])
            ->update(['pwd' => $hash]);
        if ($updated !== 1) throw new ApiException(self::EXPIRED);
        try {
            CacheService::delete($key);
        } catch (\Throwable $ignored) {
            // A successful password CAS has already spent the grant, even if cache eviction fails.
        }
    }
}
