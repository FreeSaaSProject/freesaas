<?php
declare(strict_types=1);

/** Idempotent call stub ingest (VATS / aggregators / site callback). */
$body = $_POST;
if ($body === [] && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

try {
    $id = CallsRepository::upsertIngest([
        'provider' => (string)($body['provider'] ?? 'webhook'),
        'provider_call_id' => (string)($body['provider_call_id'] ?? $body['external_id'] ?? ''),
        'phone' => (string)($body['phone'] ?? ''),
        'client_id' => (int)($body['client_id'] ?? 0),
        'admin_id' => (int)($body['admin_id'] ?? 0),
        'direction' => (int)($body['direction'] ?? CallsRepository::TYPE_IN),
        'channel' => (string)($body['channel'] ?? 'ingest'),
        'result_id' => (int)($body['result_id'] ?? CallsRepository::RESULT_UNMARKED),
        'dt_call' => $body['dt_call'] ?? time(),
        'request' => (string)($body['request'] ?? ''),
        'result_text' => (string)($body['result_text'] ?? ''),
        'filedata' => (string)($body['filedata'] ?? $body['recording_url'] ?? ''),
        'event_id' => (int)($body['event_id'] ?? 0),
        'order_id' => (int)($body['order_id'] ?? 0),
        'client_kind' => (int)($body['client_kind'] ?? 0),
    ]);
    AgentHttp::emit(['ok' => true, 'id' => $id, 'message' => t('acquisition.ingest.ok')]);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
}
