<?php
// Run only against the disposable database in GitHub Actions, never production.
if (getenv('SUDI_DISPOSABLE_TEST_DB') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Requires an explicitly disposable GitHub Actions database');
}
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
use think\facade\Db;
use app\services\order\StoreOrderCreateServices;
use app\services\pay\PayNotifyServices;
use app\services\pay\PayServices;
use app\dao\product\sku\StoreProductAttrValueDao;

if ($app->config->get('database.connections.mysql.hostname') !== '127.0.0.1'
    || $app->config->get('database.connections.mysql.database') !== 'crmeb31') {
    throw new RuntimeException('Unexpected database target');
}
// Keep actual payment bookkeeping; suppress delivery of external notifications.
foreach (['NoticeListener', 'CustomNoticeListener', 'OutPushListener', 'CustomEventListener'] as $event) {
    $app->event->remove($event);
}
Db::execute("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function cart($pid, $unique, $num = 1) {
    return [['cart_num' => $num, 'productInfo' => ['id' => $pid, 'attrInfo' => ['unique' => $unique]]]];
}
function publicApi($path, array $query = []) {
    $handle = curl_init('http://127.0.0.1:8000/api/' . $path . '?' . http_build_query($query));
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $result = json_decode($body, true);
    if ($status !== 200 || !is_array($result)) throw new RuntimeException("API failed: $path $status $body");
    return $result;
}
function reserve($pid, $unique, $num = 1) {
    return Db::transaction(function () use ($pid, $unique, $num) {
        app()->make(StoreOrderCreateServices::class)->decGoodsStock(cart($pid, $unique, $num), 0, 0, 0, 0);
        return true;
    });
}
// Separate PHP processes have separate connections, exercising actual row locks.
if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $kind, $id, $unique, $barrier] = $argv;
    file_put_contents($barrier . '.' . getmypid() . '.ready', 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout');
        usleep(10000);
    }
    if ($kind === 'stock') {
        try { reserve((int)$id, $unique); echo "RESULT:1\n"; }
        catch (crmeb\exceptions\ApiException $e) { echo "RESULT:0\n"; }
    } else {
        $ok = app()->make(PayNotifyServices::class)->wechatProduct($id, 'test-trade', PayServices::ALIAPY_PAY, '199.00');
        echo 'RESULT:' . (int)$ok . "\n";
    }
    exit;
}
function race($kind, $id, $unique = '') {
    $barrier = sys_get_temp_dir() . '/sudi-race-' . bin2hex(random_bytes(8));
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', $kind, (string)$id, $unique, $barrier]));
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Worker launch failed');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier . '.*.ready')) < 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Workers not ready');
        usleep(10000);
    }
    touch($barrier);
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !preg_match('/RESULT:([01])/', $out, $match)) {
            throw new RuntimeException("Worker failed: $code $out $err");
        }
        $results[] = (int)$match[1];
    }
    foreach (glob($barrier . '*') as $file) unlink($file);
    return $results;
}

$pid = $uid = $oid = 0;
$orderNumber = 'SUDI-CI-' . bin2hex(random_bytes(8));
try {
    $attrs = [];
    foreach (['黑', '灰'] as $color) foreach (['S', 'M', 'L'] as $size) {
        $attrs[] = ['detail' => ['颜色' => $color, '尺码' => $size], 'attr_arr' => [$color, $size],
            'stock' => 1, 'price' => 199 + count($attrs), 'cost' => 80, 'ot_price' => 259,
            'pic' => '/test.jpg', 'bar_code' => '', 'bar_code_number' => '', 'weight' => 0,
            'volume' => 0, 'brokerage' => 0, 'brokerage_two' => 0, 'vip_price' => 0,
            'is_show' => 1, 'is_default_select' => count($attrs) === 0 ? 1 : 0,
            'virtual_list' => [], 'coupon_id' => 0];
    }
    $merchant = app()->make(app\services\admin\StoreManageServices::class);
    $merchant->createProduct(['store_name' => $orderNumber, 'slider_image' => ['/test.jpg', '/test2.jpg'],
        'cate_id' => [1], 'unit_name' => '件', 'attr' => [], 'content' => '<p>CI dress</p>',
        'logistics' => ['1'], 'freight' => 2, 'postage' => 0, 'temp_id' => 0,
        'spec_type' => 1, 'items' => [['value' => '颜色', 'detail' => ['黑', '灰']],
            ['value' => '尺码', 'detail' => ['S', 'M', 'L']]], 'attrs' => $attrs, 'is_show' => 0]);
    $pid = (int)Db::name('store_product')->where('store_name', $orderNumber)->value('id');
    check($pid > 0, 'merchant service creates draft');
    check((int)Db::name('store_product')->where('id', $pid)->value('is_new') === 0, 'ordinary product has no promotion flag');
    $draft = publicApi('products', ['ids' => $pid]);
    check(($draft['status'] ?? 0) === 200 && count($draft['data']) === 0, 'buyer API hides draft');
    $merchant->productShow($pid, 1);
    $published = publicApi('products', ['news' => 0, 'timeOrder' => 1, 'page' => 1, 'limit' => 10]);
    check(($published['status'] ?? 0) === 200 && in_array($pid, array_column($published['data'], 'id')), 'ordinary published product appears in homepage feed');
    $category = publicApi('products', ['sid' => 1, 'ids' => $pid]);
    check(($category['status'] ?? 0) === 200 && in_array($pid, array_column($category['data'], 'id')), 'category API finds published product');
    $skus = Db::name('store_product_attr_value')->where('product_id', $pid)->order('id')->column('unique');
    check((int)Db::name('store_product_attr_value')->where('product_id', $pid)->count() === 6, 'six distinct color/size SKUs');
    $results = race('stock', $pid, $skus[0]);
    check(array_sum($results) === 1, 'real order stock service: only one last-unit buyer succeeds');
    check((int)Db::name('store_product_attr_value')->where('unique', $skus[0])->value('stock') === 0, 'SKU never negative');
    check((int)Db::name('store_product')->where('id', $pid)->value('stock') === 5, 'product stock decremented once');
    try {
        Db::transaction(function () use ($pid, $skus) {
            reserve($pid, $skus[1]);
            throw new RuntimeException('injected transaction failure');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'injected transaction failure') throw $e;
    }
    check((int)Db::name('store_product_attr_value')->where('unique', $skus[1])->value('stock') === 1, 'failure rolls SKU stock back');
    check((int)Db::name('store_product')->where('id', $pid)->value('stock') === 5, 'failure rolls product stock back');
    $dao = app()->make(StoreProductAttrValueDao::class);
    check(!$dao->decStockIncSales(['product_id' => $pid, 'unique' => $skus[1], 'type' => 0], 99), 'actual DAO rejects oversized decrement');
    check(!$dao->decStockIncSales(['product_id' => $pid, 'unique' => $skus[1], 'type' => 0], -1), 'actual DAO rejects negative quantity');
    check((int)Db::name('store_product_attr_value')->where('unique', $skus[1])->value('stock') === 1, 'rejected decrements preserve stock');

    $uid = Db::name('user')->insertGetId(['account' => $orderNumber, 'pwd' => '', 'nickname' => 'CI buyer', 'phone' => '', 'status' => 1, 'add_time' => time(), 'last_time' => time()]);
    $oid = Db::name('store_order')->insertGetId([
        'order_id' => $orderNumber, 'uid' => $uid, 'real_name' => 'CI buyer',
        'user_phone' => '', 'user_address' => 'CI only', 'cart_id' => '[]',
        'total_num' => 1, 'total_price' => 199, 'pay_price' => 199, 'paid' => 0,
        'add_time' => time(), 'status' => 0, 'pay_type' => 'alipay',
    ]);
    $notify = app()->make(PayNotifyServices::class);
    foreach (['198.99', '199.001', '', 'invalid', null] as $amount) {
        check(!$notify->wechatProduct($orderNumber, 'test-trade', PayServices::ALIAPY_PAY, $amount), 'invalid payment amount rejected: ' . var_export($amount, true));
    }
    check((int)Db::name('store_order')->where('id', $oid)->value('paid') === 0, 'rejected payment remains unpaid');
    $results = race('pay', $orderNumber);
    check(array_sum($results) === 2, 'valid concurrent duplicate callbacks both acknowledged');
    check((int)Db::name('store_order')->where('id', $oid)->value('paid') === 1, 'actual order paid');
    check((int)Db::name('store_order_status')->where('oid', $oid)->where('change_type', 'pay_success')->count() === 1, 'one payment success event');
    check((int)Db::name('capital_flow')->where('order_id', $orderNumber)->count() === 1, 'one actual capital ledger entry');
    check(!$notify->wechatProduct($orderNumber, 'test-trade', PayServices::ALIAPY_PAY, '1.00'), 'wrong amount rejected even after paid');
    check($notify->wechatProduct($orderNumber, 'test-trade', PayServices::ALIAPY_PAY, '199.00'), 'sequential callback is idempotent');
    check((int)Db::name('capital_flow')->where('order_id', $orderNumber)->count() === 1, 'sequential retry does not duplicate ledger');
    echo "SERVICE INTEGRATION PACK PASSED (not full browser/end-to-end acceptance)\n";
} finally {
    if ($oid) {
        Db::name('capital_flow')->where('order_id', $orderNumber)->delete();
        Db::name('store_order_status')->where('oid', $oid)->delete();
        Db::name('store_order')->where('id', $oid)->delete();
    }
    if ($uid) Db::name('user')->where('uid', $uid)->delete();
    if ($pid) {
        foreach (['store_product_attr', 'store_product_attr_result', 'store_product_cate', 'store_product_description'] as $table) {
            Db::name($table)->where('product_id', $pid)->delete();
        }
        Db::name('store_product_attr_value')->where('product_id', $pid)->delete();
        Db::name('store_product')->where('id', $pid)->delete();
    }
}
