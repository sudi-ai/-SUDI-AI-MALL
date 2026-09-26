<?php
namespace app\services\user;

use crmeb\exceptions\ApiException;
use think\cache\driver\Redis;
use think\facade\Cache;
use think\facade\Config;

/** Shared limits and one-time consumption for every phone verification entry. */
class SmsCodeServices
{
    public function expiryMinutes($minutes): int
    {
        if (!preg_match('/^[1-9]\d?$/D', (string)$minutes) || (int)$minutes > 30) {
            throw new ApiException('手机验证码服务配置异常，请联系商城客服');
        }
        return (int)$minutes;
    }

    protected function store(): Redis
    {
        $store = Cache::store();
        if (!$store instanceof Redis) {
            throw new ApiException('手机验证码服务暂不可用，请联系商城客服');
        }
        return $store;
    }

    protected function run(string $script, array $keys, array $args = [])
    {
        try {
            $store = $this->store();
            $keys = array_map([$store, 'getCacheKey'], $keys);
            $handler = $store->handler();
            // Both clients apply the same cache prefix as normal CacheService reads.
            if ($handler instanceof \Redis) {
                $result = $handler->eval($script, array_merge($keys, $args), count($keys));
            } elseif ($handler instanceof \Predis\Client) {
                $result = $handler->eval($script, count($keys), ...array_merge($keys, $args));
            } else {
                throw new \RuntimeException('Unsupported Redis client');
            }
            if ($result === false || $result === null) throw new \RuntimeException('Redis script failed');
            return $result;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException('短信验证服务暂不可用，请稍后重试');
        }
    }

    protected function checkPhone(string $phone): void
    {
        if (!preg_match('/^1[3-9]\d{9}$/D', $phone)) throw new ApiException('手机号格式不正确');
    }

    /** Reserve before calling the provider; ambiguous failures retain the fee budget. */
    public function issue(string $phone, string $ip, int $code, $minutes, callable $send): int
    {
        $this->checkPhone($phone);
        $minutes = $this->expiryMinutes($minutes);
        if ($code < 100000 || $code > 999999) throw new ApiException('验证码生成失败');
        $token = bin2hex(random_bytes(24));
        $pending = 'sms.auth.pending.' . $phone;
        $cooldown = 'sms.auth.cooldown.' . $phone;
        $cooldownSeconds = max(60, (int)Config::get('sms.sendCooldownSeconds', 60));
        $limits = [
            max(0, (int)Config::get('sms.maxMinuteCount', 5)),
            max(0, (int)Config::get('sms.maxPhoneCount', 20)),
            max(0, (int)Config::get('sms.maxIpCount', 50)),
        ];
        $reserve = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 or redis.call('EXISTS', KEYS[2]) == 1 then return 1 end
for i = 3, 5 do
    if tonumber(redis.call('GET', KEYS[i]) or '0') >= tonumber(ARGV[i + 1]) then return i end
end
for i = 3, 5 do
    local count = redis.call('INCR', KEYS[i])
    if count == 1 then redis.call('EXPIRE', KEYS[i], i == 3 and 61 or ARGV[7]) end
end
redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
redis.call('SET', KEYS[2], '1', 'EX', ARGV[3])
return 0
LUA;
        $result = (int)$this->run($reserve, [
            $pending, $cooldown, 'sms.minute.' . $phone . date('YmdHi'),
            'sms.phone.' . $phone . '.' . date('Ymd'), 'sms.ip.' . $ip . '.' . date('Ymd'),
        ], [$token, max(120, $cooldownSeconds + 30), $cooldownSeconds, $limits[0], $limits[1], $limits[2], 86401]);
        $errors = [1 => '发送过于频繁，请稍后重试', 3 => '同一手机号每分钟短信发送次数已达上限',
            4 => '同一手机号今日短信发送次数已达上限', 5 => '同一IP今日短信发送次数已达上限'];
        if ($result !== 0) throw new ApiException($errors[$result] ?? '短信发送暂不可用');

        try {
            if ($send() !== true) throw new \RuntimeException('SMS provider rejected request');
            // Numeric codes are stored verbatim by ThinkPHP's Redis serializer.
            // Publish and reset attempts together, only for this reservation owner.
            $publish = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[3])
redis.call('DEL', KEYS[3], KEYS[4], KEYS[1])
return 1
LUA;
            if ((int)$this->run($publish, [$pending, 'code_' . $phone, 'sms.auth.attempts.' . $phone,
                'sms.auth.challenge.' . $phone], [$token, (string)$code, $minutes * 60]) !== 1) {
                throw new \RuntimeException('SMS reservation expired');
            }
            return $code;
        } catch (\Throwable $e) {
            throw new ApiException('短信发送未完成，请在冷却结束后重新获取验证码');
        } finally {
            try {
                $this->run("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0", [$pending], [$token]);
            } catch (\Throwable $ignored) {
                // A failed release expires automatically; never send without a reservation.
            }
        }
    }

    public function consume(string $phone, $code, string $ip): void
    {
        $this->checkPhone($phone);
        $code = (is_string($code) || is_int($code)) ? (string)$code : '';
        if (!preg_match('/^\d{6}$/D', $code)) $code = '';
        $verify = <<<'LUA'
local ipAttempts = tonumber(redis.call('GET', KEYS[4]) or '0')
if ipAttempts >= tonumber(ARGV[3]) then return -3 end
redis.call('INCR', KEYS[4])
if ipAttempts == 0 then redis.call('EXPIRE', KEYS[4], 300) end
local code = redis.call('GET', KEYS[1])
local ttl = redis.call('PTTL', KEYS[1])
if not code or ttl <= 0 then
    redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
    return 0
end
if string.len(code) ~= 6 or not string.match(code, '^%d+$') then return 0 end
if redis.call('GET', KEYS[3]) ~= code then
    redis.call('SET', KEYS[3], code, 'PX', ttl)
    redis.call('SET', KEYS[2], '0', 'PX', ttl)
end
local attempts = tonumber(redis.call('GET', KEYS[2]) or '0')
if attempts >= tonumber(ARGV[2]) then
    redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
    return -2
end
if code == ARGV[1] then
    redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
    return 1
end
attempts = redis.call('INCR', KEYS[2])
redis.call('PEXPIRE', KEYS[2], ttl)
if attempts >= tonumber(ARGV[2]) then
    redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
    return -2
end
return -1
LUA;
        $result = (int)$this->run($verify, ['code_' . $phone, 'sms.auth.attempts.' . $phone,
            'sms.auth.challenge.' . $phone, 'sms.auth.verify.ip.' . hash('sha256', $ip) . '.' . (int)floor(time() / 300)], [
            $code, max(1, min(10, (int)Config::get('sms.maxVerifyAttempts', 5))),
            max(1, (int)Config::get('sms.maxVerifyIpCount', 100)),
        ]);
        if ($result === 1) return;
        $errors = [0 => '验证码已失效，请重新获取', -1 => '验证码错误',
            -2 => '验证码错误次数已达上限，请重新获取', -3 => '验证过于频繁，请稍后重试'];
        throw new ApiException($errors[$result] ?? '短信验证服务暂不可用');
    }
}
