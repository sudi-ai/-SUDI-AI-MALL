<?php
// Local RSA keys only: no payment provider requests or merchant credentials.
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key) throw new RuntimeException('Unable to generate test RSA key');
openssl_pkey_export($key, $private);
$public = openssl_pkey_get_details($key)['key'];
$strip = function ($pem) { return preg_replace('/-----[^-]+-----|\s/', '', $pem); };
$service = crmeb\services\AliPayService::instance([
    'appId' => 'sudi-ci-app', 'merchantPrivateKey' => $strip($private),
    'alipayPublicKey' => $strip($public), 'notifyUrl' => 'https://example.invalid/notify',
]);
$params = ['app_id' => 'sudi-ci-app', 'out_trade_no' => 'sudi-ci-order',
    'trade_no' => 'sudi-ci-trade', 'trade_status' => 'TRADE_SUCCESS',
    'total_amount' => '199.00', 'passback_params' => 'product', 'sign_type' => 'RSA2'];
$sign = function ($params) use ($key) {
    unset($params['sign']);
    $values = $params;
    unset($values['sign_type']);
    ksort($values);
    $parts = [];
    foreach ($values as $name => $value) if ($value !== '') $parts[] = $name . '=' . $value;
    if (!openssl_sign(implode('&', $parts), $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signing failed');
    }
    $params['sign'] = base64_encode($signature);
    return $params;
};
$run = function ($params, $expected, $label) use ($app, $service) {
    $request = new app\Request();
    $request->setMethod('POST');
    $request->withServer(['REQUEST_METHOD' => 'POST']);
    $request->withPost($params);
    $app->instance('request', $request);
    $called = 0;
    $result = $service->notify(function ($notification) use (&$called) {
        $called++;
        return $notification->total_amount === '199.00';
    });
    if ($result !== $expected || $called !== ($expected === 'success' ? 1 : 0)) {
        throw new RuntimeException("$label: result=$result calls=$called");
    }
    echo "PASS: $label\n";
};
$valid = $sign($params);
$run($valid, 'success', 'actual SDK accepts valid RSA2 callback');
$bad = $valid; $bad['sign'] = base64_encode(str_repeat('x', 256));
$run($bad, 'fail', 'actual SDK rejects invalid signature');
$bad = $params; $bad['app_id'] = 'different-app';
$run($sign($bad), 'fail', 'valid signature with wrong APPID rejected');
$bad = $valid; $bad['total_amount'] = '1.00';
$run($bad, 'fail', 'tampered amount invalidates signature');
$bad = $params; $bad['trade_status'] = 'WAIT_BUYER_PAY';
$run($sign($bad), 'fail', 'unpaid callback does not enter business processing');
