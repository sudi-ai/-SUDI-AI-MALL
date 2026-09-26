<?php
// Real HTTP/SQL/Redis integration, with a local SMTP sink and verified OAuth fixtures.
if (getenv('SUDI_DISPOSABLE_TEST_DB') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Requires an explicitly disposable GitHub Actions database');
}
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string)$error . "\n"); exit(1); });
use think\facade\Db;
use crmeb\services\CacheService;
use app\services\user\LoginServices;
use app\services\wechat\WechatUserServices;
if ($app->config->get('database.connections.mysql.hostname') !== '127.0.0.1'
    || $app->config->get('database.connections.mysql.database') !== 'crmeb31') throw new RuntimeException('Unexpected database');
foreach (['NoticeListener', 'CustomNoticeListener', 'OutPushListener', 'CustomEventListener'] as $event) $app->event->remove($event);
Db::execute("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
function identityCheck($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function identityApi($path, array $data = [], $token = '', $method = 'POST') {
    $curl = curl_init('http://127.0.0.1:8000/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($token) curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authori-zation: Bearer ' . $token]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $result = json_decode($body, true);
    if ($http !== 200 || !is_array($result)) throw new RuntimeException("HTTP failure: $path ($http)");
    // Never print tokens, passwords, codes, or mail bodies.
    echo "API $path status=" . ($result['status'] ?? 0) . ' msg=' . ($result['msg'] ?? '') . "\n";
    return $result;
}
function identityMailCode($email, $token = '') {
    $base = sys_get_temp_dir() . '/sudi-email-' . bin2hex(random_bytes(6));
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/email_smtp_fixture.php', $base . '.port', $base . '.mail', '2525']));
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start SMTP fixture');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 5;
        while (!is_file($base . '.port') && microtime(true) < $deadline) usleep(20000);
        identityCheck(is_file($base . '.port'), 'local SMTP sink is ready');
        $res = identityApi($token ? 'user/email/verify' : 'email/register/verify', ['email' => $email], $token);
        identityCheck(($res['status'] ?? 0) === 200, 'application sends verification through configured SMTP');
        $message = file_get_contents($base . '.mail');
        $parts = preg_split('/\r?\n\r?\n/', $message, 2);
        $body = base64_decode(preg_replace('/\s+/', '', $parts[1] ?? ''), true);
        if (!preg_match('/(?<!\d)(\d{6})(?!\d)/', (string)$body, $match)) throw new RuntimeException('Missing verification code');
        return $match[1];
    } finally {
        fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process);
        foreach (glob($base . '.*') as $file) unlink($file);
    }
}
function identityUid($token) {
    $res = identityApi('user/identities', [], $token, 'GET');
    identityCheck(($res['status'] ?? 0) === 200, 'authenticated identity endpoint works');
    return (int)$res['data']['uid'];
}

$email = 'ci-' . bin2hex(random_bytes(5)) . '@example.invalid';
$password = 'SudiIdentityCIpass42';
$code = identityMailCode($email);
$wrongCode = $code === '111111' ? '222222' : '111111';
$payload = ['email' => $email, 'password' => $password, 'captcha' => $wrongCode];
identityCheck(identityApi('email/register', $payload)['status'] !== 200, 'wrong email code cannot register');
$payload['captcha'] = $code;
identityCheck(identityApi('email/register', $payload)['status'] === 200, 'email registration succeeds');
identityCheck(identityApi('email/register', $payload)['status'] !== 200, 'used registration code cannot replay');
$emailUser = Db::name('user')->where('email', $email)->find();
$uid = (int)$emailUser['uid'];
identityCheck(password_verify($password, $emailUser['pwd']) && strlen($emailUser['pwd']) > 32, 'password hash is not truncated');
$login = identityApi('login', ['account' => strtoupper($email), 'password' => $password]);
identityCheck($login['status'] === 200, 'email login normalizes address case');
$token = $login['data']['token'];
identityCheck(identityUid($token) === $uid, 'email login resolves to registered UID');
$phone = '13900000101';
$badBind = identityApi('user/binding', ['phone' => $phone, 'step' => 1, 'captcha' => ''], $token);
identityCheck($badBind['status'] !== 200, 'step flag cannot bypass phone verification');
CacheService::set('code_' . $phone, '654321', 60);
$bound = identityApi('user/binding', ['phone' => $phone, 'step' => 1, 'captcha' => '654321'], $token);
identityCheck($bound['status'] === 200, 'verified phone binds to existing email UID');
$phoneLogin = identityApi('login', ['account' => $phone, 'password' => $password]);
identityCheck($phoneLogin['status'] === 200 && identityUid($phoneLogin['data']['token']) === $uid, 'phone and email password login share UID');
CacheService::set('code_' . $phone, '654322', 60);
$smsLogin = identityApi('login/mobile', ['phone' => $phone, 'captcha' => '654322']);
identityCheck($smsLogin['status'] === 200 && identityUid($smsLogin['data']['token']) === $uid, 'SMS login shares UID');
$wx = app()->make(WechatUserServices::class);
$openid = 'ci-openid-' . bin2hex(random_bytes(8));
$unionid = 'ci-union-' . bin2hex(random_bytes(8));
$wxData = ['openid' => $openid, 'unionid' => $unionid, 'phone' => $phone, 'nickname' => 'CI identity'];
$wxUser = $wx->wechatOauthAfter([$openid, $wxData, 0, 0, 'wechat', 'wechat']);
identityCheck((int)$wxUser['uid'] === $uid, 'verified WeChat phone resolves to same UID');
unset($wxData['phone']);
$wxUser = $wx->wechatOauthAfter([$openid, $wxData, 0, 0, 'wechat', 'wechat']);
identityCheck((int)$wxUser['uid'] === $uid, 'returning WeChat OAuth resolves same UID without phone');
$wxData['unionid'] = 'ci-new-union-' . bin2hex(random_bytes(6));
$wxUser = $wx->wechatOauthAfter([$openid, $wxData, 0, 0, 'wechat', 'wechat']);
identityCheck((int)$wxUser['uid'] === $uid, 'openid fallback prevents duplicate when unionid first changes');
$identities = identityApi('user/identities', [], $token, 'GET')['data'];
identityCheck($identities['email_bound'] && $identities['phone_bound'] && $identities['wechat_bound'], 'all three identities are bound on one user');

// Opposite order: phone signup then bind email; the recipient must be the logged-in UID.
$newPhone = '13900000102';
CacheService::set('code_' . $newPhone, '654323', 60);
$phoneOnly = identityApi('login/mobile', ['phone' => $newPhone, 'captcha' => '654323']);
identityCheck($phoneOnly['status'] === 200, 'first SMS login creates buyer');
$phoneToken = $phoneOnly['data']['token'];
$phoneUid = identityUid($phoneToken);
identityCheck(identityApi('login', ['account' => $newPhone, 'password' => '123456'])['status'] !== 200, 'SMS signup does not create a guessable password');
$bindEmail = 'bind-' . bin2hex(random_bytes(5)) . '@example.invalid';
$bindCode = identityMailCode($bindEmail, $phoneToken);
$bindPayload = ['email' => $bindEmail, 'captcha' => $bindCode, 'password' => $password];
identityCheck(identityApi('email/register', $bindPayload)['status'] !== 200, 'binding code cannot create a separate account');
identityCheck(identityApi('user/email/bind', $bindPayload, $token)['status'] !== 200, 'binding code is scoped to the requesting UID');
identityCheck(identityApi('user/email/bind', $bindPayload, $phoneToken)['status'] === 200, 'email binds to original phone account');
$boundLogin = identityApi('login', ['account' => $bindEmail, 'password' => $password]);
identityCheck($boundLogin['status'] === 200 && identityUid($boundLogin['data']['token']) === $phoneUid, 'bound email logs in to original phone UID');
identityCheck(Db::name('user')->where('email', $bindEmail)->count() === 1, 'email binding creates no duplicate buyer');
CacheService::set('code_' . $phone, '654324', 60);
$emptyUid = Db::name('user')->insertGetId(['account' => 'ci-empty-' . bin2hex(random_bytes(4)), 'status' => 1, 'add_time' => time()]);
$emptyToken = app()->make(crmeb\utils\JwtAuth::class)->createToken($emptyUid, 'api')['token'];
identityCheck(identityApi('user/binding', ['phone' => $phone, 'captcha' => '654324', 'step' => 1], $emptyToken)['status'] !== 200, 'occupied phone cannot be attached to a second account');
try {
    Db::name('user')->where('uid', $emptyUid)->update(['phone' => $phone]);
    throw new RuntimeException('Unique phone constraint missing');
} catch (think\db\exception\PDOException $e) {
    identityCheck(true, 'database prevents concurrent duplicate phone ownership');
}
try {
    $wxData['phone'] = $newPhone;
    $wx->wechatOauthAfter([$openid, $wxData, 0, 0, 'wechat', 'wechat']);
    throw new RuntimeException('Conflicting OAuth identity was accepted');
} catch (crmeb\exceptions\ApiException $e) {
    identityCheck(true, 'conflicting WeChat and phone UIDs cannot switch or merge accounts');
}
identityCheck(identityApi('remote_register', [], '', 'GET')['status'] !== 200, 'unsigned remote tokens cannot log in');
echo "UNIFIED IDENTITY INTEGRATION PASSED\n";
