<?php
namespace app\services\email;

/** Small SMTP client for verification mail. TLS certificate checks stay enabled. */
class SmtpMailer
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $c = $this->config;
        $host = trim((string)($c['host'] ?? ''));
        $from = trim((string)($c['from'] ?? ''));
        $port = (int)($c['port'] ?? 0);
        $encryption = strtolower((string)($c['encryption'] ?? 'tls'));
        if ($host === '' || $from === '' || $port < 1 || $port > 65535 || !filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('SMTP is not configured');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) throw new \RuntimeException('Unsupported SMTP encryption');
        if ($encryption === 'none' && (string)($c['username'] ?? '') !== '') throw new \RuntimeException('SMTP authentication requires TLS');
        $timeout = max(3, min(30, (int)($c['timeout'] ?? 10)));
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new \RuntimeException('SMTP connection failed');
        stream_set_timeout($socket, $timeout);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO sudix.cn', [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP TLS negotiation failed');
                $this->command($socket, 'EHLO sudix.cn', [250]);
            }
            $username = (string)($c['username'] ?? '');
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode((string)($c['password'] ?? '')), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $fromName = '=?UTF-8?B?' . base64_encode((string)($c['from_name'] ?? '苏迪商城')) . '?=';
            $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n";
            $headers .= 'To: <' . $to . ">\r\n";
            $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $headers .= 'MIME-Version: 1.0' . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $payload = $headers . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n";
            if (fwrite($socket, $payload) !== strlen($payload)) throw new \RuntimeException('SMTP message write failed');
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
            return true;
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): void
    {
        if (fwrite($socket, $command . "\r\n") === false) throw new \RuntimeException('SMTP command write failed');
        $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): void
    {
        $code = null;
        for ($i = 0; $i < 40 && !feof($socket); $i++) {
            $line = fgets($socket, 2048);
            if ($line === false) break;
            if (preg_match('/^(\d{3})([ -])/', $line, $match)) {
                $code = (int)$match[1];
                if ($match[2] === ' ') break;
            }
        }
        if ($code === null || !in_array($code, $expected, true)) throw new \RuntimeException('SMTP server rejected the request');
    }
}
