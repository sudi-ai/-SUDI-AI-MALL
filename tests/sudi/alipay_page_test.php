<?php
// Real official SDK signing using a generated test key; no provider or merchant access.
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/'); $app->initialize();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string)$e . "\n"); exit(1); });
$key = openssl_pkey_new(['private_key_bits' => 2048]);
openssl_pkey_export($key, $private);
$public = openssl_pkey_get_details($key)['key'];
$strip = function ($s) { return preg_replace('/-----[^-]+-----|\s+/', '', $s); };
$service = crmeb\services\AliPayService::instance([
    'appId' => 'sudi-page-ci', 'merchantPrivateKey' => $strip($private), 'alipayPublicKey' => $strip($public),
    'notifyUrl' => 'https://mall.example.invalid/api/pay/notify/alipay', 'webMode' => 'page',
]);
$html = $service->create('商城测试', 'sudi-mall-ci-order', '0.01', 'product',
    'https://mall.example.invalid/', 'https://mall.example.invalid/paid', false);
$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$form = $dom->getElementById('alipaysubmit');
if (!$form || parse_url($form->getAttribute('action'), PHP_URL_HOST) !== 'openapi.alipay.com') throw new RuntimeException('Invalid gateway form');
$fields = [];
foreach ($dom->getElementsByTagName('input') as $input) {
    // Browsers do not submit unnamed controls (the SDK includes a submit button).
    if ($input->hasAttribute('name')) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
}
$query = []; parse_str(parse_url($form->getAttribute('action'), PHP_URL_QUERY) ?? '', $query);
$fields = array_merge($query, $fields);
$biz = json_decode($fields['biz_content'], true);
if ($fields['method'] !== 'alipay.trade.page.pay' || $fields['notify_url'] !== 'https://mall.example.invalid/api/pay/notify/alipay'
    || $fields['return_url'] !== 'https://mall.example.invalid/paid' || $biz['out_trade_no'] !== 'sudi-mall-ci-order'
    || $biz['total_amount'] !== '0.01' || $biz['passback_params'] !== 'product') throw new RuntimeException('Wrong mall payment fields');
$signature = base64_decode($fields['sign']); unset($fields['sign']); ksort($fields);
$parts = []; foreach ($fields as $name => $value) if ($value !== '') $parts[] = $name . '=' . $value;
if (openssl_verify(implode('&', $parts), $signature, $public, OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Page request signature invalid');
echo "PASS: official SDK page payment keeps mall callback, return, amount, and RSA2 signature\n";
