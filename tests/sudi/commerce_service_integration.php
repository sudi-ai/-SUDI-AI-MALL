<?php
// Run only against the disposable database in GitHub Actions, never production.
if (getenv('SUDI_DISPOSABLE_TEST_DB') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Requires an explicitly disposable GitHub Actions database');
}
require __DIR__ . '/../../crmeb/vendor/autoload.php';
$app = new think\App(__DIR__ . '/../../crmeb/');
$app->initialize();
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
    $pid = Db::name('store_product')->insertGetId([
        'image' => '/test.jpg', 'slider_image' => '["/test.jpg"]',
        'store_name' => $orderNumber, 'store_info' => 'Disposable CI fixture',
        'keyword' => 'SUDI-CI', 'cate_id' => '1', 'price' => 199, 'ot_price' => 259,
        'postage' => 0, 'unit_name' => '件', 'sort' => 0, 'sales' => 0, 'stock' => 6,
        'is_show' => 0, 'is_new' => 1, 'add_time' => time(), 'is_postage' => 1,
        'is_del' => 0, 'cost' => 80, 'spec_type' => 1,
    ]);
    $skus = [];
    foreach (['黑', '灰'] as $color) foreach (['S', 'M', 'L'] as $size) {
        $unique = substr(md5($orderNumber . $color . $size), 0, 8);
        $skus[] = $unique;
        Db::name('store_product_attr_value')->insert([
            'product_id' => $pid, 'suk' => "$color,$size", 'unique' => $unique,
            'stock' => 1, 'sales' => 0, 'price' => 199 + count($skus),
            'cost' => 80, 'ot_price' => 259, 'image' => '/test.jpg', 'type' => 0,
        ]);
    }
    check(Db::name('store_product_attr_value')->where('product_id', $pid)->count() === 6, 'six distinct color/size SKUs');
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
    check(Db::name('store_order_status')->where('oid', $oid)->where('change_type', 'pay_success')->count() === 1, 'one payment success event');
    check(Db::name('capital_flow')->where('order_id', $orderNumber)->count() === 1, 'one actual capital ledger entry');
    check(!$notify->wechatProduct($orderNumber, 'test-trade', PayServices::ALIAPY_PAY, '1.00'), 'wrong amount rejected even after paid');
    check($notify->wechatProduct($orderNumber, 'test-trade', PayServices::ALIAPY_PAY, '199.00'), 'sequential callback is idempotent');
    check(Db::name('capital_flow')->where('order_id', $orderNumber)->count() === 1, 'sequential retry does not duplicate ledger');
    echo "SERVICE INTEGRATION PACK PASSED (not full browser/end-to-end acceptance)\n";
} finally {
    if ($oid) {
        Db::name('capital_flow')->where('order_id', $orderNumber)->delete();
        Db::name('store_order_status')->where('oid', $oid)->delete();
        Db::name('store_order')->where('id', $oid)->delete();
    }
    if ($uid) Db::name('user')->where('uid', $uid)->delete();
    if ($pid) {
        Db::name('store_product_attr_value')->where('product_id', $pid)->delete();
        Db::name('store_product')->where('id', $pid)->delete();
    }
}
