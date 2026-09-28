<?php
// Real localhost HTTP/SQL/Redis in disposable CI. SMS codes are injected fixtures;
// no SMS provider is called, and no existing browser/account fixture is changed.
if (getenv('SUDI_DISPOSABLE_TEST_DB') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Requires an explicitly disposable GitHub Actions database');
}
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use app\services\user\LoginServices;
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

function passwordCheck($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function passwordCurl($path, array $data = [], $token = '', $method = 'POST') {
    $curl = curl_init('http://127.0.0.1:8000/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if ($token !== '') curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authori-zation: Bearer ' . $token]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    return $curl;
}
function passwordResponse($curl, $body): array {
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $result = json_decode($body, true);
    if ($status !== 200 || !is_array($result)) throw new RuntimeException('Password setup HTTP response was invalid');
    return $result;
}
function passwordApi($path, array $data = [], $token = '', $method = 'POST'): array {
    $curl = passwordCurl($path, $data, $token, $method);
    try { return passwordResponse($curl, curl_exec($curl)); }
    finally { curl_close($curl); }
}
function passwordPhone(): string {
    do { $phone = '139' . random_int(10000000, 99999999); }
    while (Db::name('user')->where('account|phone', $phone)->count());
    return $phone;
}
function passwordSmsLogin(string $phone): array {
    $code = (string)random_int(100000, 999999);
    if (!CacheService::set('code_' . $phone, $code, 60)) throw new RuntimeException('Cannot inject SMS fixture');
    $response = passwordApi('login/mobile', ['phone' => $phone, 'captcha' => $code]);
    passwordCheck(($response['status'] ?? 0) === 200, 'HTTP SMS fixture login succeeds');
    return $response['data'];
}
function passwordUid(string $token): int {
    $response = passwordApi('user/identities', [], $token, 'GET');
    passwordCheck(($response['status'] ?? 0) === 200, 'HTTP token authenticates the buyer');
    return (int)$response['data']['uid'];
}
function passwordNewBuyer(): array {
    $phone = passwordPhone();
    $login = passwordSmsLogin($phone);
    passwordCheck(($login['needs_password_setup'] ?? null) === true, 'first SMS signup explicitly requests password setup');
    return [$phone, passwordUid($login['token']), $login['token']];
}
function passwordGrantKey(int $uid, string $token): string {
    return 'password.setup.' . $uid . '.' . hash('sha256', $token);
}
function passwordSetup(string $token, string $password, ?string $confirmation = null, array $extra = []): array {
    return passwordApi('user/password/setup', ['password' => $password, 'password_confirm' => $confirmation ?? $password] + $extra, $token);
}
function passwordExpired(array $response, string $message): void {
    passwordCheck(($response['status'] ?? 0) !== 200 && strpos((string)($response['msg'] ?? ''), '重新获取短信验证码') !== false, $message);
}
function passwordHashAt(int $uid): string {
    return (string)Db::name('user')->where('uid', $uid)->value('pwd');
}
function passwordRace(string $token, array $passwords): array {
    $multi = curl_multi_init();
    $handles = [];
    try {
        foreach ($passwords as $password) {
            $curl = passwordCurl('user/password/setup', ['password' => $password, 'password_confirm' => $password], $token);
            $handles[] = $curl;
            curl_multi_add_handle($multi, $curl);
        }
        do {
            $code = curl_multi_exec($multi, $running);
            if ($code !== CURLM_OK) throw new RuntimeException('Concurrent HTTP request failed');
            if ($running && curl_multi_select($multi, 0.2) === -1) usleep(10000);
        } while ($running);
        $responses = [];
        foreach ($handles as $curl) $responses[] = passwordResponse($curl, curl_multi_getcontent($curl));
        return $responses;
    } finally {
        foreach ($handles as $curl) { curl_multi_remove_handle($multi, $curl); curl_close($curl); }
        curl_multi_close($multi);
    }
}

[$phone, $uid, $token] = passwordNewBuyer();
$initialHash = passwordHashAt($uid);
$grantKey = passwordGrantKey($uid, $token);
$grant = CacheService::get($grantKey);
passwordCheck(is_array($grant) && (int)$grant['uid'] === $uid && $grant['phone'] === $phone
    && hash_equals($initialHash, $grant['pwd']) && hash_equals(hash('sha256', $token), $grant['token_sha256'])
    && $grant['expires_at'] > time() + 570 && $grant['expires_at'] <= time() + 600,
    'signup grant binds UID, verified phone, password version and JWT for ten minutes');
$ttl = Cache::store()->handler()->ttl(Cache::store()->getCacheKey($grantKey));
passwordCheck($ttl > 570 && $ttl <= 600, 'grant also expires in Redis within ten minutes');

passwordCheck(passwordSetup('', 'BuyerFirstPass42', null, ['uid' => $uid, 'phone' => $phone])['status'] !== 200,
    'unauthenticated setup is rejected even with a valid UID and phone');
passwordCheck(passwordHashAt($uid) === $initialHash, 'unauthenticated request cannot change a password');
foreach (['short1', '12345678', 'abcdefgh', str_repeat('a', 32) . '1', ' BuyerPass42', "BuyerPass42\t", "BuyerPass42\0"] as $weak) {
    passwordCheck(passwordSetup($token, $weak)['status'] !== 200, 'weak, oversized or whitespace-altered password is rejected');
}
passwordCheck(passwordSetup($token, 'BuyerFirstPass42', 'DifferentPass42')['status'] !== 200, 'confirmation must match exactly');
passwordCheck(passwordHashAt($uid) === $initialHash && CacheService::has($grantKey), 'validation failures do not change password or spend the grant');

$again = passwordSmsLogin($phone);
passwordCheck(($again['needs_password_setup'] ?? null) === false && $again['token'] !== $token,
    'returning account gets no grant and a different JWT even when logins share one second');
passwordCheck(passwordUid($again['token']) === $uid, 'second SMS login still resolves the same UID');
passwordExpired(passwordSetup($again['token'], 'WrongSessionPass42'), 'another valid token for the same UID cannot use the signup grant');

[$otherPhone, $otherUid, $otherSignupToken] = passwordNewBuyer();
$otherLogin = passwordSmsLogin($otherPhone);
$otherHash = passwordHashAt($otherUid);
passwordExpired(passwordSetup($otherLogin['token'], 'CrossAccountPass42', null, ['uid' => $uid, 'phone' => $phone]),
    'a different authenticated UID cannot select someone else by posted UID or phone');
passwordCheck(passwordHashAt($uid) === $initialHash && passwordHashAt($otherUid) === $otherHash, 'cross-UID request leaves both accounts unchanged');

$password = 'BuyerFirstPass42';
passwordCheck(passwordSetup($token, $password, null, ['uid' => $otherUid, 'phone' => $otherPhone])['status'] === 200,
    'setup derives the buyer from authentication and ignores posted identity fields');
passwordCheck(password_verify($password, passwordHashAt($uid)) && passwordHashAt($otherUid) === $otherHash,
    'only the authenticated buyer receives a password_hash password');
passwordCheck(!CacheService::has($grantKey), 'successful setup evicts the one-time grant');
passwordExpired(passwordSetup($token, 'AnotherPassword42'), 'successful setup cannot replay');
$passwordLogin = passwordApi('login', ['account' => $phone, 'password' => $password]);
passwordCheck($passwordLogin['status'] === 200 && passwordUid($passwordLogin['data']['token']) === $uid,
    'new password authenticates the original SMS UID over HTTP');
$smsLogin = passwordSmsLogin($phone);
passwordCheck($smsLogin['needs_password_setup'] === false && passwordUid($smsLogin['token']) === $uid,
    'SMS login remains available for the same UID after setup');

[$expiredPhone, $expiredUid, $expiredToken] = passwordNewBuyer();
$expiredKey = passwordGrantKey($expiredUid, $expiredToken);
$expiredHash = passwordHashAt($expiredUid);
Cache::store()->handler()->pexpire(Cache::store()->getCacheKey($expiredKey), 1);
usleep(20000);
passwordExpired(passwordSetup($expiredToken, 'ExpiredGrantPass42'), 'expired Redis grant gives the SMS-reset recovery phrase');
passwordCheck(passwordHashAt($expiredUid) === $expiredHash, 'expired grant does not change password');

[$resetPhone, $resetUid, $resetToken] = passwordNewBuyer();
$resetPassword = 'SmsResetPassword42';
CacheService::set('code_' . $resetPhone, '765432', 60);
passwordCheck(passwordApi('register/reset', ['account' => $resetPhone, 'captcha' => '765432', 'password' => $resetPassword])['status'] === 200,
    'existing SMS reset route still changes the password');
passwordExpired(passwordSetup($resetToken, 'StaleGrantPassword42'), 'a password reset makes the earlier signup grant unusable');
passwordCheck(password_verify($resetPassword, passwordHashAt($resetUid)), 'old grant cannot overwrite a password reset');

[$racePhone, $raceUid, $raceToken] = passwordNewBuyer();
$racePasswords = ['ConcurrentOnePass42', 'ConcurrentTwoPass42'];
$responses = passwordRace($raceToken, $racePasswords);
$successes = 0;
foreach ($responses as $index => $response) {
    if (($response['status'] ?? 0) === 200) {
        $successes++;
        passwordCheck(password_verify($racePasswords[$index], passwordHashAt($raceUid)), 'the successful concurrent request owns the stored password');
    } else {
        passwordExpired($response, 'the losing concurrent request is rejected');
    }
}
passwordCheck($successes === 1, 'two concurrent HTTP setup requests have exactly one success');

[$changedPhone, $changedUid, $changedToken] = passwordNewBuyer();
$changedHash = passwordHashAt($changedUid);
Db::name('user')->where('uid', $changedUid)->update(['phone' => passwordPhone()]);
passwordExpired(passwordSetup($changedToken, 'ChangedPhonePass42'), 'phone changes invalidate the original verified-phone grant');
passwordCheck(passwordHashAt($changedUid) === $changedHash, 'phone mismatch cannot overwrite password');

[$disabledPhone, $disabledUid, $disabledToken] = passwordNewBuyer();
$disabledHash = passwordHashAt($disabledUid);
Db::name('user')->where('uid', $disabledUid)->update(['status' => 0]);
passwordCheck(passwordSetup($disabledToken, 'DisabledBuyerPass42')['status'] !== 200
    && passwordHashAt($disabledUid) === $disabledHash, 'disabled buyer cannot use a previously issued grant');

// Extra claims must never replace server-owned authentication identity or password claims.
$claimToken = app()->make(LoginServices::class)->createToken($uid, 'api', '', [
    'password_setup_nonce' => bin2hex(random_bytes(8)), 'uid' => $otherUid, 'type' => 'admin',
    'jti' => ['id' => $otherUid, 'type' => 'admin'], 'pwd' => 'overridden',
]);
$jwt = app()->make(JwtAuth::class);
[$claimUid, $claimType, $claimPassword] = $jwt->parseToken($claimToken['token']);
$jwt->verifyToken();
passwordCheck((int)$claimUid === $uid && $claimType === 'api' && $claimPassword === md5(''),
    'optional token claims cannot override UID, type or password claims');
passwordExpired(passwordSetup($claimToken['token'], 'NoGrantTokenPass42'), 'a valid JWT without a signup grant cannot set a password');
echo "PASSWORD SETUP HTTP INTEGRATION PASSED (SMS verification fixtures only)\n";
