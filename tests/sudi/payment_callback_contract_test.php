<?php
// Lightweight contract assertions; no credentials or network calls.
$files = [
    'ali' => __DIR__ . '/../../crmeb/crmeb/services/AliPayService.php',
    'notify' => __DIR__ . '/../../crmeb/app/services/pay/PayNotifyServices.php',
];
foreach ($files as $name => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing $name file\n");
        exit(1);
    }
}
$ali = file_get_contents($files['ali']);
$notify = file_get_contents($files['notify']);
$requiredAli = ["verifyNotify", "total_amount", "hash_equals", "app_id", "TRADE_SUCCESS"];
foreach ($requiredAli as $needle) {
    if (strpos($ali, $needle) === false) {
        fwrite(STDERR, "AliPay callback contract missing: $needle\n");
        exit(1);
    }
}
if (strpos($notify, "bccomp") === false || strpos($notify, "pay_price") === false || strpos($notify, "paidAmount") === false) {
    fwrite(STDERR, "business amount verification contract missing\n");
    exit(1);
}
echo "payment callback contract OK\n";
