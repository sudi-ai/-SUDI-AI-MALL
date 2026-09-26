<?php
$portFile = $argv[1];
$captureFile = $argv[2];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) exit(2);
$address = stream_socket_get_name($server, false);
file_put_contents($portFile, substr(strrchr($address, ':'), 1));
$client = stream_socket_accept($server, 10);
if (!$client) exit(3);
fwrite($client, "220 fixture ESMTP\r\n");
$data = '';
while (($line = fgets($client, 4096)) !== false) {
    $command = strtoupper(rtrim($line, "\r\n"));
    if (strpos($command, 'EHLO ') === 0) fwrite($client, "250-fixture\r\n250 HELP\r\n");
    elseif (strpos($command, 'MAIL FROM:') === 0 || strpos($command, 'RCPT TO:') === 0) fwrite($client, "250 OK\r\n");
    elseif ($command === 'DATA') {
        fwrite($client, "354 end with dot\r\n");
        while (($bodyLine = fgets($client, 4096)) !== false) {
            if (rtrim($bodyLine, "\r\n") === '.') break;
            $data .= $bodyLine;
        }
        file_put_contents($captureFile, $data);
        fwrite($client, "250 queued\r\n");
    } elseif ($command === 'QUIT') { fwrite($client, "221 bye\r\n"); break; }
    else { fwrite($client, "500 unsupported\r\n"); }
}
fclose($client);
fclose($server);
