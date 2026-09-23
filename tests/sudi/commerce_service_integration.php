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
function publicApi($path, array $query = [], $token = '', $method = 'GET') {
    $handle = curl_init('http://127.0.0.1:8000/api/' . $path . ($method === 'GET' ? '?' . http_build_query($query) : ''));
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($token !== '') curl_setopt($handle, CURLOPT_HTTPHEADER, ['Authori-zation: Bearer ' . $token]);
    if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($query)]);
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

$pid = $uid = $oid = $bid = $rid = 0;
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
    $bid = Db::name('user')->insertGetId(['account' => $orderNumber . 'B', 'pwd' => '', 'nickname' => 'CI buyer B', 'status' => 1, 'add_time' => time(), 'last_time' => time()]);
    $tokenA = app()->make(crmeb\utils\JwtAuth::class)->createToken($uid, 'api')['token'];
    $tokenB = app()->make(crmeb\utils\JwtAuth::class)->createToken($bid, 'api')['token'];
    $denied = publicApi('admin/manage/product/create', [], $tokenB, 'POST');
    check(($denied['msg'] ?? '') === '权限不足', 'authenticated ordinary buyer cannot publish products');
    $denied = publicApi('order/detail/' . $orderNumber, [], $tokenB);
    check(($denied['status'] ?? 200) !== 200 && strpos($denied['msg'] ?? '', '订单不存在') !== false, 'buyer B cannot read buyer A order');
    $denied = publicApi('order/refund/apply/' . $oid, ['text' => 'test', 'refund_type' => 1, 'refund_price' => 199], $tokenB, 'POST');
    check(($denied['status'] ?? 200) !== 200 && ($denied['msg'] ?? '') === '订单不存在', 'buyer B cannot refund buyer A order');
    $cartUnique = md5($orderNumber);
    $cartData = ['id' => 'ci-cart', 'cart_num' => 1, 'product_id' => $pid, 'truePrice' => 199,
        'vip_truePrice' => 0, 'postage_price' => 0, 'combination_id' => 0, 'seckill_id' => 0,
        'bargain_id' => 0, 'productInfo' => ['id' => $pid, 'image' => '/test.jpg',
            'store_name' => $orderNumber, 'unit_name' => '件', 'attrInfo' => ['suk' => '黑,S', 'unique' => $skus[0]]]];
    Db::name('store_order_cart_info')->insert(['oid' => $oid, 'uid' => $uid, 'cart_id' => 'ci-cart',
        'product_id' => $pid, 'cart_num' => 1, 'surplus_num' => 1, 'unique' => $cartUnique, 'cart_info' => json_encode($cartData)]);
    $review = ['unique' => $cartUnique, 'comment' => 'CI review', 'product_score' => 5, 'service_score' => 5, 'pics' => '/test.jpg'];
    $denied = publicApi('order/comment', $review, $tokenB, 'POST');
    check(($denied['msg'] ?? '') === '不是您自己的订单，无法评价', 'buyer B cannot review buyer A order');
    $denied = publicApi('order/comment', $review, $tokenA, 'POST');
    check(($denied['msg'] ?? '') === '订单未支付，无法评价', 'unpaid order cannot be reviewed');
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
    foreach ([0, 1, -1, -2] as $state) {
        Db::name('store_order')->where('id', $oid)->update(['status' => $state]);
        $denied = publicApi('order/comment', $review, $tokenA, 'POST');
        check(($denied['msg'] ?? '') === '请确认收货后再评价', 'unreceived or closed order cannot be reviewed: ' . $state);
    }
    Db::name('store_order')->where('id', $oid)->update(['status' => 2]);
    $accepted = publicApi('order/comment', $review, $tokenA, 'POST');
    check(($accepted['status'] ?? 0) === 200, 'received order accepts rating text and image');
    $denied = publicApi('order/comment', $review, $tokenA, 'POST');
    check(($denied['msg'] ?? '') === '订单商品已评价', 'duplicate review rejected');
    check((int)Db::name('store_product_reply')->where('oid', $oid)->count() === 1, 'one persisted review');
    $rid = Db::name('store_order_refund')->insertGetId(['order_id' => $orderNumber . 'R', 'store_order_id' => $oid,
        'uid' => $uid, 'refund_type' => 4, 'refund_price' => 199, 'cart_info' => json_encode([$cartData])]);
    foreach (['order/refund/detail/' . $orderNumber . 'R', 'order/express/' . $orderNumber . 'R/refund'] as $path) {
        $denied = publicApi($path, [], $tokenB);
        check(($denied['status'] ?? 200) !== 200 && ($denied['msg'] ?? '') === '订单不存在', 'foreign refund hidden: ' . $path);
    }
    $returnData = ['id' => $rid, 'refund_express' => 'CI123', 'refund_express_name' => 'CI courier'];
    $denied = publicApi('order/refund/express', $returnData, $tokenB, 'POST');
    check(($denied['msg'] ?? '') === '订单不存在', 'buyer B cannot modify buyer A return tracking');
    check((string)Db::name('store_order_refund')->where('id', $rid)->value('refund_express') === '', 'foreign return remains unchanged');
    $accepted = publicApi('order/refund/express', $returnData, $tokenA, 'POST');
    check(($accepted['status'] ?? 0) === 200, 'owner can submit return tracking');
    echo "SERVICE INTEGRATION PACK PASSED (not full browser/end-to-end acceptance)\n";
} finally {
    if ($oid) {
        Db::name('store_product_reply')->where('oid', $oid)->delete();
        Db::name('store_order_cart_info')->where('oid', $oid)->delete();
        Db::name('store_order_refund')->where('store_order_id', $oid)->delete();
        Db::name('capital_flow')->where('order_id', $orderNumber)->delete();
        Db::name('store_order_status')->where('oid', $oid)->delete();
        Db::name('store_order')->where('id', $oid)->delete();
    }
    if ($uid) Db::name('user')->where('uid', $uid)->delete();
    if ($bid) Db::name('user')->where('uid', $bid)->delete();
    if ($pid) {
        foreach (['store_product_attr', 'store_product_attr_result', 'store_product_cate', 'store_product_description'] as $table) {
            Db::name($table)->where('product_id', $pid)->delete();
        }
        Db::name('store_product_attr_value')->where('product_id', $pid)->delete();
        Db::name('store_product')->where('id', $pid)->delete();
    }
}
