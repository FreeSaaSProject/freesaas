#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Live HTTP permission probe via php built-in server (no .htaccess).
 * Expect gated paths without session → redirect/401/403.
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$host = '127.0.0.1';
$port = 8765;
$base = "http://{$host}:{$port}";

$router = $root . '/index.php';
if (!is_file($router)) {
    echo "FAIL missing index.php\n";
    exit(1);
}

$cmd = sprintf(
    '%s -S %s:%d -t %s %s',
    escapeshellarg(PHP_BINARY),
    $host,
    $port,
    escapeshellarg($root),
    escapeshellarg($router)
);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', sys_get_temp_dir() . '/freesaas-http-perms-out.log', 'w'],
    2 => ['file', sys_get_temp_dir() . '/freesaas-http-perms-err.log', 'w'],
];
$proc = proc_open($cmd, $descriptors, $pipes, $root);
if (!is_resource($proc)) {
    echo "FAIL could not start php -S\n";
    exit(1);
}
fclose($pipes[0]);

$ready = false;
for ($i = 0; $i < 40; $i++) {
    usleep(100000);
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $ready = true;
        break;
    }
}
if (!$ready) {
    proc_terminate($proc);
    proc_close($proc);
    echo "FAIL server not ready\n";
    exit(1);
}

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

$probe = static function (string $path) use ($base): array {
    $ch = curl_init($base . $path);
    if ($ch === false) {
        return ['code' => 0, 'headers' => '', 'body' => ''];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw)) {
        return ['code' => $code, 'headers' => '', 'body' => ''];
    }
    $parts = explode("\r\n\r\n", $raw, 2);
    return ['code' => $code, 'headers' => $parts[0] ?? '', 'body' => $parts[1] ?? ''];
};

$users = $probe('/users');
$assert(
    in_array($users['code'], [301, 302, 303, 307, 401, 403], true)
    || stripos($users['headers'], 'Location:') !== false,
    '/users without session gated (got HTTP ' . $users['code'] . ')'
);

$booking = $probe('/booking/new');
$assert(
    in_array($booking['code'], [301, 302, 303, 307, 401, 403, 503], true)
    || stripos($booking['headers'], 'Location:') !== false,
    '/booking/new without session gated (got HTTP ' . $booking['code'] . ')'
);

proc_terminate($proc);
proc_close($proc);

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
