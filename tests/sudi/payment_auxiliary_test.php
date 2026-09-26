<?php
// Exercise the real callback methods with isolated database/service doubles.
// No application bootstrap, credentials, database connections, or provider calls.
// This verifies callback decisions/rollback boundaries, not MySQL concurrency.
namespace think\facade {
    class Db
    {
        public static $rows = [];
        public static $ledger = [];
        public static $depth = 0;
        public static $locked = [];
        public static $commits = 0;
        public static $rollbacks = 0;

        public static function transaction(callable $callback)
        {
            $snapshot = [self::$rows, self::$ledger, self::$locked];
            self::$depth++;
            try {
                $result = $callback();
                self::$commits++;
                return $result;
            } catch (\Throwable $error) {
                [self::$rows, self::$ledger, self::$locked] = $snapshot;
                self::$rollbacks++;
                throw $error;
            } finally {
                self::$depth--;
                if (!self::$depth) self::$locked = [];
            }
        }

        public static function name($table)
        {
            return new PaymentQuery($table);
        }
    }

    class PaymentQuery
    {
        private $table;
        private $orderId;
        private $locked = false;

        public function __construct($table) { $this->table = $table; }
        public function where($field, $value)
        {
            if ($field !== 'order_id') throw new \LogicException('Unexpected lookup');
            $this->orderId = $value;
            return $this;
        }
        public function lock($lock) { $this->locked = $lock === true; return $this; }
        public function find()
        {
            if (!Db::$depth || !$this->locked) throw new \LogicException('Order read must hold a transaction lock');
            Db::$locked[$this->table][$this->orderId] = true;
            return Db::$rows[$this->table][$this->orderId] ?? null;
        }
    }
}

namespace {
    require __DIR__ . '/../../crmeb/app/services/pay/PayServices.php';
    require __DIR__ . '/../../crmeb/app/services/pay/PayNotifyServices.php';

    use app\services\pay\PayNotifyServices;
    use app\services\pay\PayServices;
    use think\facade\Db;

    class AuxiliaryPaymentService
    {
        public $calls = 0;
        public $failure = '';
        public $lastPayment;

        public function rechargeSuccess($orderId, array $other)
        {
            return $this->complete('user_recharge', $orderId, $other['pay_type'], $other);
        }

        public function paySuccess(array $order, $payType, array $other)
        {
            return $this->complete('other_order', $order['order_id'], $payType, $other);
        }

        private function complete($table, $orderId, $payType, array $other)
        {
            if (!Db::$depth || empty(Db::$locked[$table][$orderId])) {
                throw new \LogicException('Business processing must retain the order lock');
            }
            $this->calls++;
            $this->lastPayment = [$payType, $other['trade_no']];
            Db::$rows[$table][$orderId]['paid'] = 1;
            Db::$ledger[] = [$table, $orderId];
            if ($this->failure === 'false') return false;
            if ($this->failure === 'exception') throw new \RuntimeException('Injected downstream failure');
            if ($this->failure === 'error') throw new \Error('Injected downstream error');
            return true;
        }
    }

    class AuxiliaryPaymentApp
    {
        public $service;
        public function make($class)
        {
            if (!in_array($class, [app\services\user\UserRechargeServices::class, app\services\order\OtherOrderServices::class], true)) {
                throw new \LogicException('Unexpected service');
            }
            return $this->service;
        }
    }

    function app() { return $GLOBALS['paymentTestApp']; }
    function check($condition, $message)
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    $GLOBALS['paymentTestApp'] = new AuxiliaryPaymentApp();
    $notify = new PayNotifyServices();
    $cases = [
        ['wechatUserRecharge', 'user_recharge', ['price' => '100.00', 'give_price' => '25.00'], '100.00', ['125.00', '75.00']],
        ['wechatMember', 'other_order', ['pay_price' => '49.90', 'member_price' => '99.00'], '49.90', ['99.00']],
    ];
    foreach ($cases as [$method, $table, $prices, $amount, $wrongPrices]) {
        foreach ([PayServices::WEIXIN_PAY, PayServices::ALIAPY_PAY] as $payType) {
            $orderId = 'auxiliary-test-order';
            $initial = ['id' => 1, 'order_id' => $orderId, 'uid' => 1, 'paid' => 0] + $prices;
            Db::$rows = [$table => [$orderId => $initial]];
            Db::$ledger = [];
            $service = app()->service = new AuxiliaryPaymentService();
            $invoke = function ($paidAmount, $id = null) use ($notify, $method, $orderId, $payType) {
                return $notify->$method($id ?? $orderId, 'auxiliary-test-trade', $payType, $paidAmount);
            };

            check($invoke($amount, 'missing-order') === false, "$method must reject missing orders");
            foreach (array_merge([null, '', '0', '-1', '1e2', '100.001', $amount . "\n", '1.00'], $wrongPrices) as $invalid) {
                check($invoke($invalid) === false, "$method must reject invalid or mismatched amounts");
                check($service->calls === 0 && Db::$ledger === [] && Db::$rows[$table][$orderId]['paid'] === 0,
                    "$method must reject before any business processing");
            }
            check($invoke($amount) === true, "$method must accept the actual charge");
            check($service->lastPayment === [$payType, 'auxiliary-test-trade'], "$method must preserve payment references");
            check($service->calls === 1 && count(Db::$ledger) === 1, "$method must process a valid payment once");
            check($invoke($amount) === true, "$method must acknowledge valid duplicate notifications");
            check($service->calls === 1 && count(Db::$ledger) === 1, "$method must not process duplicates again");
            check($invoke('0.01') === false && $invoke(null) === false,
                "$method must still verify the amount when already paid");
            check($service->calls === 1 && count(Db::$ledger) === 1, "$method must not process invalid duplicates");

            foreach (['false', 'exception', 'error'] as $failure) {
                Db::$rows[$table][$orderId] = $initial;
                Db::$ledger = [];
                $service->failure = $failure;
                $rollbacks = Db::$rollbacks;
                check($invoke($amount) === false, "$method must reject downstream $failure");
                check(Db::$rollbacks === $rollbacks + 1 && Db::$rows[$table][$orderId] === $initial && Db::$ledger === [],
                    "$method must roll back partial processing on $failure");
                $service->failure = '';
                check($invoke($amount) === true && count(Db::$ledger) === 1,
                    "$method must allow a clean retry after $failure");
            }
            // An outer rollback must also undo a successful nested callback.
            Db::$rows[$table][$orderId] = $initial;
            Db::$ledger = [];
            try {
                Db::transaction(function () use ($invoke, $amount) {
                    check($invoke($amount) === true, 'Nested callback failed');
                    throw new \RuntimeException('outer rollback');
                });
            } catch (\RuntimeException $error) {
                check($error->getMessage() === 'outer rollback', $error->getMessage());
            }
            check(Db::$rows[$table][$orderId] === $initial && Db::$ledger === [] && Db::$depth === 0,
                "$method must participate in the caller transaction");
            echo "PASS: $method / $payType amounts, duplicates, failures, and transaction boundaries\n";
        }
    }
}
