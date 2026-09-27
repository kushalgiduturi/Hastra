<?php
// tests/lib/smtp_sink.php
// Minimal local SMTP server for the test run: accepts every message on
// 127.0.0.1:<port> and writes it to <dir>/<n>.eml. Nothing is relayed.
// Started and stopped by tests/runner.php.
//   php tests/lib/smtp_sink.php <port> <dir>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

[$_, $port, $dir] = $argv + [null, 2525, __DIR__ . '/../.results/mail'];
if (!is_dir($dir)) mkdir($dir, 0700, true);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) { fwrite(STDERR, "smtp_sink: $errstr\n"); exit(1); }
fwrite(STDOUT, "READY\n");

$n = 0;
while ($c = @stream_socket_accept($server, -1)) {
    stream_set_timeout($c, 10);
    $say = fn(string $l) => fwrite($c, $l . "\r\n");
    $say('220 astra-test-sink ESMTP');
    $data = null;
    while (($line = fgets($c)) !== false) {
        if ($data !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents(sprintf('%s/%04d.eml', $dir, ++$n), $data);
                $data = null;
                $say('250 OK queued');
            } else {
                $data .= (str_starts_with($line, '..') ? substr($line, 1) : $line);
            }
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') { $say('250-astra-test-sink'); $say('250 8BITMIME'); }
        elseif ($cmd === 'DATA') { $data = ''; $say('354 End data with <CR><LF>.<CR><LF>'); }
        elseif ($cmd === 'QUIT') { $say('221 Bye'); break; }
        else $say('250 OK');
    }
    fclose($c);
}
