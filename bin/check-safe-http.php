#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Unit-style SSRF guard checks for SafeHttp (no network required for rejects).
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/modules/llm/lib/OAuthServer.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    echo "FAIL {$msg}\n";
    $failed++;
};

$assert(!SafeHttp::validateOutboundUrl('http://example.com/x')['ok'], 'reject http');
$assert(!SafeHttp::validateOutboundUrl('https://localhost/x')['ok'], 'reject localhost');
$assert(!SafeHttp::validateOutboundUrl('https://127.0.0.1/x')['ok'], 'reject 127.0.0.1');
$assert(!SafeHttp::validateOutboundUrl('https://[::1]/x')['ok'], 'reject ::1');
$assert(!SafeHttp::validateOutboundUrl('https://192.168.1.1/x')['ok'], 'reject RFC1918');
$assert(!SafeHttp::validateOutboundUrl('https://10.0.0.1/x')['ok'], 'reject 10/8');
$assert(!SafeHttp::validateOutboundUrl('https://169.254.169.254/latest')['ok'], 'reject link-local metadata');
$assert(!SafeHttp::validateOutboundUrl('file:///etc/passwd')['ok'], 'reject file scheme');
$assert(SafeHttp::isPublicIp('8.8.8.8'), '8.8.8.8 public');
$assert(!SafeHttp::isPublicIp('127.0.0.1'), '127 not public');

// validateOutboundUrl exposes port for CURLOPT_RESOLVE pin (anti DNS rebinding).
$v = SafeHttp::validateOutboundUrl('https://example.com/x');
if ($v['ok']) {
    $assert(($v['port'] ?? 0) === 443, 'https default port 443 for pin');
    $assert(($v['ip'] ?? '') !== '', 'public ip for pin');
} else {
    // Offline / DNS blocked environments — still require shape when ok.
    $assert(array_key_exists('port', $v), 'validate result includes port key');
}
$src = (string)file_get_contents(dirname(__DIR__) . '/app/SafeHttp.php');
$assert(strpos($src, 'CURLOPT_RESOLVE') !== false, 'CURLOPT_RESOLVE pin present');
$assert(strpos($src, 'CURLOPT_WRITEFUNCTION') !== false, 'streaming WRITEFUNCTION size cap');

// Trusted OAuth hosts must not treat loopback as CIMD fetch target.
$assert(!OAuthServer::isTrustedOAuthHost('localhost'), 'OAuth CIMD host rejects localhost');
$assert(!OAuthServer::isTrustedOAuthHost('127.0.0.1'), 'OAuth CIMD host rejects 127.0.0.1');
$assert(OAuthServer::isTrustedRedirectUri('http://127.0.0.1/callback'), 'loopback redirect uri still ok');
$assert(OAuthServer::isTrustedOAuthHost('chatgpt.com'), 'chatgpt.com trusted for CIMD');

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
