<?php
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string)$error . "\n"); exit(1); });
function checkWechat($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
use app\services\pay\WechatCallbackGuard;
use EasyWeChat\Payment\Merchant;
use EasyWeChat\Payment\Notify;
use EasyWeChat\Support\XML;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

$v2key = 'sudi-ci-v2-signing-key';
$v2 = ['appid' => 'sudi-ci-app', 'total_fee' => '19900', 'out_trade_no' => 'sudici', 'return_code' => 'SUCCESS', 'result_code' => 'SUCCESS'];
$v2['sign'] = EasyWeChat\Payment\generate_sign($v2, $v2key, 'md5');
$parseV2 = function ($data) use ($v2key) {
    $request = HttpRequest::create('/notify', 'POST', [], [], [], [], XML::build($data));
    return new Notify(new Merchant(['key' => $v2key]), $request);
};
checkWechat($parseV2($v2)->isValid($v2key), 'WeChat v2 actual SDK accepts a valid signature');
$tampered = $v2; $tampered['total_fee'] = '1';
checkWechat(!$parseV2($tampered)->isValid($v2key), 'WeChat v2 SDK rejects tampered amount');
$tampered = $v2; $tampered['sign'] = 'invalid';
checkWechat(!$parseV2($tampered)->isValid($v2key), 'WeChat v2 SDK rejects invalid signature');
checkWechat(WechatCallbackGuard::amount($v2, ['sudi-ci-app']) === '199.00', 'WeChat amount converts cents exactly');
checkWechat(WechatCallbackGuard::amount($v2, ['wrong-app']) === null, 'WeChat rejects wrong APPID');
checkWechat(WechatCallbackGuard::amount(['appid' => 'sudi-ci-app', 'total_fee' => '199.1'], ['sudi-ci-app']) === null, 'WeChat rejects fractional cents');
checkWechat(WechatCallbackGuard::amount(['appid' => 'provider', 'sub_appid' => 'sudi-ci-app', 'total_fee' => 19900], ['sudi-ci-app']) === '199.00', 'WeChat partner callbacks bind the sub-app ID');

class TestWechatV3Client extends crmeb\services\easywechat\v3pay\PayClient {
    public function __construct(array $config) { $this->app = ['config' => ['v3_payment' => $config]]; }
}
class TestWechatCertificateClient extends TestWechatV3Client {
    public $fixtureResponse = [];
    public function request(string $endpoint, string $method = 'POST', array $options = [], $serial = true) { return $this->fixtureResponse; }
    public function decrypt(array $encryptedCertificate) { return $encryptedCertificate['pem']; }
}
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
checkWechat((bool)$key, 'local ephemeral RSA key generated');
$pemPath = tempnam(sys_get_temp_dir(), 'sudi-wx-key-');
file_put_contents($pemPath, openssl_pkey_get_details($key)['key']);
$apiKey = random_bytes(32);
$client = new TestWechatV3Client(['key' => $apiKey, 'v3_pay_public_key' => 'PUB_KEY_ID_CI', 'v3_pay_public_pem' => $pemPath]);
$makeBody = function ($state, $appid = 'sudi-ci-app') use ($apiKey) {
    $nonce = '123456789012'; $aad = 'transaction'; $tag = '';
    $data = json_encode(['trade_state' => $state, 'appid' => $appid, 'amount' => ['total' => 19900]]);
    $cipher = openssl_encrypt($data, 'aes-256-gcm', $apiKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
    return json_encode(['event_type' => 'TRANSACTION.SUCCESS', 'resource' => ['nonce' => $nonce, 'associated_data' => $aad, 'ciphertext' => base64_encode($cipher . $tag)]]);
};
$send = function ($body, $mutation = '') use ($app, $key, $client) {
    $timestamp = (string)($mutation === 'expired' ? time() - 601 : time()); $nonce = 'sudi-ci-notify';
    openssl_sign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $key, OPENSSL_ALGO_SHA256);
    $headers = ['wechatpay-timestamp' => $timestamp, 'wechatpay-nonce' => $nonce, 'wechatpay-serial' => 'PUB_KEY_ID_CI', 'wechatpay-signature' => base64_encode($signature)];
    if ($mutation === 'signature') $headers['wechatpay-signature'] = base64_encode('invalid');
    if ($mutation === 'serial') $headers['wechatpay-serial'] = 'WRONG_SERIAL';
    if ($mutation === 'body') $body .= ' ';
    $request = new app\Request();
    $request->setMethod('POST'); $request->withServer(['REQUEST_METHOD' => 'POST']);
    $request->withHeader($headers)->withInput($body)->withPost(json_decode($body, true));
    $app->instance('request', $request);
    $called = 0;
    $response = $client->handleNotify(function ($notify, $success) use (&$called) {
        $called++;
        return $success && WechatCallbackGuard::amount(['appid' => $notify->appid, 'total_fee' => $notify->amount->total], ['sudi-ci-app']) === '199.00';
    });
    return [$response, $called];
};
try {
    [$response, $called] = $send($makeBody('SUCCESS'));
    checkWechat($called === 1 && $response->getData()['code'] === 'SUCCESS', 'WeChat v3 real signed encrypted callback accepted');
    foreach (['signature', 'serial', 'body', 'expired'] as $mutation) {
        [$response, $called] = $send($makeBody('SUCCESS'), $mutation);
        checkWechat($called === 0 && $response->getCode() === 400, 'WeChat v3 rejects ' . $mutation . ' before business processing');
    }
    [$response, $called] = $send($makeBody('NOTPAY'));
    checkWechat($called === 0 && $response->getData()['code'] === 'FAIL', 'WeChat v3 rejects an unpaid encrypted transaction');
    [$response, $called] = $send($makeBody('SUCCESS', 'wrong-app'));
    checkWechat($response->getData()['code'] === 'FAIL', 'WeChat v3 signed wrong APPID is rejected');

    $certClient = new TestWechatCertificateClient(['key' => $apiKey, 'serial_no' => 'CI_ROTATION_' . uniqid()]);
    $publicPem = openssl_pkey_get_details($key)['key'];
    $certClient->fixtureResponse = ['data' => [
        ['serial_no' => 'OLDER_CERT', 'encrypt_certificate' => ['pem' => $publicPem]],
        ['serial_no' => 'ROTATED_CERT', 'encrypt_certificate' => ['pem' => $publicPem]],
    ]];
    $rotatedCertificate = $certClient->getCertficatesBySerial('ROTATED_CERT');
    checkWechat(($rotatedCertificate['serial_no'] ?? '') === 'ROTATED_CERT', 'WeChat v3 selects a matching certificate from a multi-certificate response');
} finally { unlink($pemPath); }
