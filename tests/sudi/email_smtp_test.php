<?php
require __DIR__ . '/../../crmeb/app/services/email/SmtpMailer.php';
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sudi-smtp-' . bin2hex(random_bytes(5));
$portFile = $base . '.port';
$captureFile = $base . '.message';
$pipes = [];
$process = proc_open([PHP_BINARY, __DIR__ . '/email_smtp_fixture.php', $portFile, $captureFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('SMTP fixture failed to start');
fclose($pipes[0]);
try {
    $deadline = microtime(true) + 5;
    while (!is_file($portFile) && microtime(true) < $deadline) usleep(20000);
    if (!is_file($portFile)) throw new RuntimeException('SMTP fixture did not start');
    $mailer = new app\services\email\SmtpMailer([
        'host' => '127.0.0.1', 'port' => (int)file_get_contents($portFile), 'encryption' => 'none',
        'from' => 'noreply@example.invalid', 'from_name' => '苏迪商城', 'timeout' => 5,
    ]);
    if (!$mailer->send('buyer@example.invalid', '苏迪商城邮箱验证码', '验证码：123456')) throw new RuntimeException('SMTP send failed');
    $status = proc_close($process);
    if ($status !== 0 || !is_file($captureFile)) throw new RuntimeException('SMTP fixture did not accept the message');
    $message = file_get_contents($captureFile);
    if (strpos($message, 'buyer@example.invalid') === false || strpos($message, 'Content-Transfer-Encoding: base64') === false || strpos($message, base64_encode('验证码：123456')) === false) {
        throw new RuntimeException('SMTP message headers or encoded body are incorrect');
    }
    echo "SMTP delivery protocol OK\n";
} finally {
    if (is_resource($process)) proc_terminate($process);
    @unlink($portFile);
    @unlink($captureFile);
}
