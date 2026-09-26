<?php
// Disposable SQL/Redis only. SMS delivery is replaced with a counting fixture.
// These tests do not contact any SMS provider and are not live SMS acceptance.
if (getenv('SUDI_DISPOSABLE_TEST_DB') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Requires an explicitly disposable GitHub Actions database');
}
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use app\api\controller\v1\LoginController;
use app\services\message\notice\SmsService;
use app\services\user\LoginServices;
use app\services\user\SmsCodeServices;
use crmeb\exceptions\ApiException;
use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

if (Config::get('database.connections.mysql.hostname') !== '127.0.0.1'
    || Config::get('database.connections.mysql.database') !== 'crmeb31'
    || Config::get('cache.default') !== 'redis'
    || !in_array(Config::get('cache.stores.redis.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Unexpected disposable database/cache target');
}
foreach (['NoticeListener', 'CustomNoticeListener', 'OutPushListener', 'CustomEventListener'] as $event) $app->event->remove($event);
Db::execute("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
Config::set(array_merge(Config::get('sms'), [
    'maxMinuteCount' => 2, 'maxPhoneCount' => 2, 'maxIpCount' => 2,
    'sendCooldownSeconds' => 60, 'maxVerifyAttempts' => 5, 'maxVerifyIpCount' => 100,
]), 'sms');

function smsCheck($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function smsRequest(array $post, $ip = '192.0.2.10') {
    $request = new app\Request();
    $request->setMethod('POST');
    $request->withServer(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost']);
    $request->withPost($post);
    $request->macro('uid', function () { return 0; });
    app()->instance('request', $request);
    return $request;
}
function smsController($method, array $post, $ip = '192.0.2.10') {
    try {
        $request = smsRequest($post, $ip);
        return app()->make(LoginController::class)->$method($request)->getData();
    } catch (ApiException $error) {
        return ['status' => 400, 'msg' => $error->getMessage()];
    }
}
function smsRejected(callable $callback): bool {
    try { $callback(); return false; } catch (ApiException $error) { return true; }
}
function smsPhone(): string {
    do { $phone = '139' . random_int(10000000, 99999999); }
    while (Db::name('user')->where('phone', $phone)->count());
    return $phone;
}
class SmsRedisProbe extends SmsCodeServices {
    public function raw($script, array $keys, array $args = []) { return $this->run($script, $keys, $args); }
}
class CountingSmsFixture extends SmsService {
    private $counter;
    private $fail;
    public function __construct($counter, $fail = false) { $this->counter = $counter; $this->fail = $fail; }
    public function send(bool $switch, $phone, array $data, string $mark): bool {
        if (!$switch || $mark !== 'verify_code' || !preg_match('/^\d{6}$/D', (string)($data['code'] ?? ''))) {
            throw new RuntimeException('Unexpected fixture SMS request');
        }
        Cache::store()->inc($this->counter);
        if ($this->fail) throw new RuntimeException('Simulated provider timeout');
        return true;
    }
}
function smsIssue($phone, $ip, $counter, $fail = false, $minutes = 5): bool {
    smsRequest([], $ip);
    return !smsRejected(function () use ($phone, $counter, $fail, $minutes) {
        app()->make(LoginServices::class)->verify(new CountingSmsFixture($counter, $fail), $phone, 'login', $minutes);
    });
}

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $kind, $phone, $ip, $counter, $barrier] = $argv;
    file_put_contents($barrier . '.' . getmypid() . '.ready', 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('SMS barrier timed out');
        usleep(10000);
    }
    $ok = $kind === 'send' ? smsIssue($phone, $ip, $counter)
        : !smsRejected(function () use ($phone, $ip) { (new SmsCodeServices())->consume($phone, '654321', $ip); });
    echo 'RESULT:' . (int)$ok . "\n";
    exit;
}

function smsRace($kind, array $phones, $ip, $counter): array {
    $barrier = sys_get_temp_dir() . '/sudi-sms-' . bin2hex(random_bytes(8));
    $workers = [];
    try {
        foreach ($phones as $phone) {
            $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', $kind, $phone, $ip, $counter, $barrier]));
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot launch SMS worker');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier . '.*.ready')) < count($workers)) {
            if (microtime(true) > $deadline) throw new RuntimeException('SMS workers not ready');
            usleep(10000);
        }
        touch($barrier);
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || !preg_match('/RESULT:([01])/', $out, $match)) throw new RuntimeException('SMS worker failed: ' . $err);
            $results[] = (int)$match[1];
        }
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ([1, 2] as $index) if (is_resource($pipes[$index])) fclose($pipes[$index]);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        foreach (glob($barrier . '*') as $file) unlink($file);
    }
}

$codes = new SmsRedisProbe();
$counter = 'sms.test.provider.' . bin2hex(random_bytes(8));
Cache::set($counter, 0, 600);
$ipPrefix = '198.18.' . random_int(1, 250) . '.';

// Exercise one shared attempt budget across five real controller paths.
$phone = smsPhone();
CacheService::set('code_' . $phone, '654321', 60);
foreach (['mobile', 'register', 'reset', 'binding_phone', 'user_binding_phone'] as $index => $method) {
    $response = smsController($method, ['phone' => $phone, 'account' => $phone, 'captcha' => '111111',
        'password' => 'SmsIntegrationPass42', 'key' => 'unused-oauth-fixture', 'step' => 1]);
    smsCheck(($response['status'] ?? 0) !== 200, "$method rejects an incorrect SMS code");
    if ($index < 4) smsCheck((int)CacheService::get('sms.auth.attempts.' . $phone) === $index + 1,
        "$method actually reaches and increments the shared verification budget");
}
smsCheck(!CacheService::has('code_' . $phone), 'five combined failures invalidate the shared challenge');
smsCheck(smsController('mobile', ['phone' => $phone, 'captcha' => '654321'])['status'] !== 200,
    'correct code cannot bypass exhausted attempts through another route');

// Normal signup/login still works and preserves UID, without password setup.
$phone = smsPhone();
CacheService::set('code_' . $phone, '654321', 60);
for ($i = 0; $i < 4; $i++) smsCheck(smsRejected(function () use ($codes, $phone) {
    $codes->consume($phone, '111111', '192.0.2.11');
}), 'wrong attempt before the limit is rejected');
$first = smsController('mobile', ['phone' => $phone, 'captcha' => '654321']);
smsCheck($first['status'] === 200, 'first correct SMS login automatically creates an account');
$jwt = app()->make(JwtAuth::class);
[$uid] = $jwt->parseToken($first['data']['token']);
$jwt->verifyToken();
$user = Db::name('user')->where('uid', $uid)->find();
smsCheck($user && $user['phone'] === $phone && !password_verify('123456', $user['pwd']), 'new buyer has the verified phone and no guessable default password');
smsCheck(smsController('mobile', ['phone' => $phone, 'captcha' => '654321'])['status'] !== 200, 'a successful code cannot replay');
CacheService::set('code_' . $phone, '654322', 60);
$again = smsController('mobile', ['phone' => $phone, 'captcha' => '654322']);
smsCheck($again['status'] === 200, 'returning phone login succeeds');
[$againUid] = $jwt->parseToken($again['data']['token']);
$jwt->verifyToken();
smsCheck((int)$againUid === (int)$uid && Db::name('user')->where('phone', $phone)->count() === 1, 'returning phone login reuses one UID');
CacheService::set('code_' . $phone, '654321', 60);
smsCheck(array_sum(smsRace('consume', array_fill(0, 4, $phone), $ipPrefix . '1', $counter)) === 1,
    'four concurrent consumers can consume a code only once');

// Attempts from one IP are bounded independently of the selected phone.
Config::set(array_merge(Config::get('sms'), ['maxVerifyIpCount' => 2]), 'sms');
$ipPhone = smsPhone();
CacheService::set('code_' . $ipPhone, '654321', 60);
for ($i = 0; $i < 2; $i++) smsRejected(function () use ($codes, $ipPhone, $ipPrefix) { $codes->consume($ipPhone, '111111', $ipPrefix . '2'); });
smsCheck(smsRejected(function () use ($codes, $ipPhone, $ipPrefix) { $codes->consume($ipPhone, '654321', $ipPrefix . '2'); }), 'IP verification limit also rejects a correct code');
$codes->consume($ipPhone, '654321', $ipPrefix . '3');
Config::set(array_merge(Config::get('sms'), ['maxVerifyIpCount' => 100]), 'sms');

// Sending is simulated, but quota/cooldown are exercised against actual Redis.
$sendPhone = smsPhone();
$before = (int)Cache::get($counter);
smsCheck(array_sum(smsRace('send', array_fill(0, 4, $sendPhone), $ipPrefix . '4', $counter)) === 1,
    'concurrent sends to one phone reserve exactly one cooldown slot');
smsCheck((int)Cache::get($counter) === $before + 1, 'cooldown rejections never call the paid provider');
smsCheck(preg_match('/^\d{6}$/D', (string)CacheService::get('code_' . $sendPhone)) === 1, 'issued code retains legacy cache compatibility');
$ttl = (int)$codes->raw("return redis.call('TTL', KEYS[1])", ['code_' . $sendPhone]);
smsCheck($ttl > 280 && $ttl <= 300, 'five-minute issue has a five-minute cache expiry');

$before = (int)Cache::get($counter);
$phones = [smsPhone(), smsPhone(), smsPhone(), smsPhone()];
smsCheck(array_sum(smsRace('send', $phones, $ipPrefix . '5', $counter)) === 2, 'parallel phones share an exact IP send quota');
smsCheck((int)Cache::get($counter) === $before + 2, 'requests rejected by IP quota do not invoke provider');

foreach (['minute', 'phone', 'ip'] as $bucket) {
    $limitPhone = smsPhone();
    $limitIp = $ipPrefix . ($bucket === 'minute' ? '6' : ($bucket === 'phone' ? '7' : '8'));
    $key = $bucket === 'minute' ? 'sms.minute.' . $limitPhone . date('YmdHi')
        : ($bucket === 'phone' ? 'sms.phone.' . $limitPhone . '.' . date('Ymd') : 'sms.ip.' . $limitIp . '.' . date('Ymd'));
    Cache::set($key, 2, 120);
    $before = (int)Cache::get($counter);
    smsCheck(!smsIssue($limitPhone, $limitIp, $counter) && (int)Cache::get($counter) === $before,
        "$bucket quota rejects at the exact configured boundary before provider call");
}

$failurePhone = smsPhone();
$before = (int)Cache::get($counter);
smsCheck(!smsIssue($failurePhone, $ipPrefix . '9', $counter, true), 'provider failure is reported');
smsCheck(!CacheService::has('code_' . $failurePhone), 'failed issue does not publish a usable code');
smsCheck(!smsIssue($failurePhone, $ipPrefix . '9', $counter) && (int)Cache::get($counter) === $before + 1,
    'ambiguous provider failure retains cooldown and fee reservation');

$stalePhone = smsPhone();
smsCheck(smsRejected(function () use ($codes, $stalePhone, $ipPrefix) {
    $codes->issue($stalePhone, $ipPrefix . '10', 654321, 5, function () use ($codes, $stalePhone) {
        $codes->raw("redis.call('SET', KEYS[1], 'new-owner', 'EX', 60); redis.call('SET', KEYS[2], '777777', 'EX', 60); return 1",
            ['sms.auth.pending.' . $stalePhone, 'code_' . $stalePhone]);
        return true;
    });
}), 'an expired reservation owner cannot publish a stale code');
smsCheck($codes->raw("return redis.call('GET', KEYS[1])", ['sms.auth.pending.' . $stalePhone]) === 'new-owner'
    && CacheService::get('code_' . $stalePhone) === '777777', 'old owner cannot release a successor reservation or replace its code');

$expiredPhone = smsPhone();
CacheService::set('code_' . $expiredPhone, '654321', 60);
$codes->raw("return redis.call('PEXPIRE', KEYS[1], 1)", ['code_' . $expiredPhone]);
usleep(20000);
smsCheck(smsRejected(function () use ($codes, $expiredPhone) { $codes->consume($expiredPhone, '654321', '192.0.2.12'); }), 'expired challenges cannot authenticate');
foreach ([0, -1, 31, 'invalid'] as $minutes) {
    $before = (int)Cache::get($counter);
    smsCheck(!smsIssue(smsPhone(), $ipPrefix . '11', $counter, false, $minutes) && (int)Cache::get($counter) === $before,
        'invalid verification lifetime fails before SMS delivery');
}
$cacheConfig = Config::get('cache');
try {
    Config::set(array_merge($cacheConfig, ['default' => 'file']), 'cache');
    smsCheck(smsRejected(function () use ($codes, $phone) { $codes->consume($phone, '654321', '192.0.2.13'); }),
        'unsupported cache driver fails closed');
} finally {
    Config::set($cacheConfig, 'cache');
}
Cache::delete($counter);
echo "SMS AUTH INTEGRATION PASSED (provider delivery simulated)\n";
