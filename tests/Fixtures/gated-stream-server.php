<?php

declare(strict_types=1);

// A single synthetic HTTP exchange. The tail is withheld until the parent
// acknowledges its first text event; no provider, account or external address.
$folder = $argv[1] ?? '';
if (! is_dir($folder) || dirname(realpath($folder)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($folder), 'prism-stream-')) {
    exit(2);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException('Synthetic loopback listener failed.');
}
$name = stream_socket_get_name($server, false);
file_put_contents($folder.'/ready.tmp', json_encode(['url' => 'http://'.$name], JSON_THROW_ON_ERROR));
rename($folder.'/ready.tmp', $folder.'/ready.json');
$connection = stream_socket_accept($server, 10);
if ($connection === false) {
    fclose($server);
    exit(3);
}
stream_set_timeout($connection, 10);
$headers = '';
while (! str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 8192 && ! feof($connection)) {
    $headers .= fread($connection, 1);
}
preg_match('/Content-Length: ([0-9]+)/i', $headers, $matches);
$length = (int) ($matches[1] ?? 0);
if ($length < 1 || $length > 65536) {
    exit(4);
}
$body = '';
while (strlen($body) < $length && ! feof($connection)) {
    $body .= fread($connection, $length - strlen($body));
}
file_put_contents($folder.'/request.json', $body);
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
$chunk = static function (string $value) use ($connection): void {
    fwrite($connection, dechex(strlen($value))."\r\n".$value."\r\n");
    fflush($connection);
};
$chunk('data: {"type":"response.output_text.delta","delta":"first"}'."\n\n");
$deadline = microtime(true) + 5;
while (! file_exists($folder.'/ack') && microtime(true) < $deadline) {
    usleep(5000);
}
file_put_contents($folder.'/complete', file_exists($folder.'/ack') ? 'acknowledged-first-event' : 'tail-released-after-gate-timeout');
$chunk('data: {"type":"response.output_text.delta","delta":"last"}'."\n\n");
$chunk('data: {"type":"response.completed","status":"completed","id":"synthetic","model":"sonar","usage":{"input_tokens":1,"output_tokens":2},"output":[]}'."\n\n");
fwrite($connection, "0\r\n\r\n");
fflush($connection);
fclose($connection);
fclose($server);
