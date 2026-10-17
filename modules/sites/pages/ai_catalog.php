<?php
declare(strict_types=1);

/**
 * Domain AI Catalog (/.well-known/ai-catalog.json): modules add entries via EventBus sites.ai_catalog.
 *
 * @see https://github.com/modelcontextprotocol/experimental-ext-server-card/blob/main/docs/discovery.md
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
header('Access-Control-Expose-Headers: ETag');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$payload = EventBus::emit('sites.ai_catalog', ['entries' => []]);
$entries = [];
$seen = [];
foreach ((array)($payload['entries'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $identifier = trim((string)($row['identifier'] ?? ''));
    $type = trim((string)($row['type'] ?? ''));
    $hasUrl = trim((string)($row['url'] ?? '')) !== '';
    $hasData = is_array($row['data'] ?? null);
    if ($identifier === '' || $type === '' || $hasUrl === $hasData || isset($seen[$identifier])) {
        continue;
    }
    $seen[$identifier] = true;
    $entries[] = $row;
}

if ($entries === []) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"not_found"}';
    exit;
}

$body = json_encode(
    ['specVersion' => '1.0', 'entries' => $entries],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($body === false) {
    http_response_code(500);
    exit;
}
$etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
$inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag || $inm === '*')) {
    http_response_code(304);
    exit;
}
$type = 'application/ai-catalog+json';
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
header('Content-Type: ' . (stripos($accept, $type) !== false ? $type : 'application/json') . '; charset=utf-8');
header('Vary: Accept');
echo $body;
exit;
