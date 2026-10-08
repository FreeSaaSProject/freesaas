<?php
declare(strict_types=1);

/**
 * Receiver for Clientics eventHandlers webhooks (pull-side).
 * Auth: X-Clientics-Webhook-Token or ?token= / POST token= (webhook_token ini).
 */
ClienticsJobsAuth::assertWebhookToken();

$payload = array_merge($_GET, $_POST);
$raw = file_get_contents('php://input');
$body = null;
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
        $payload = array_merge($payload, $decoded);
    }
}

$result = ClienticsWebhookIngest::ingest($payload);

ClienticsSyncStateRepository::mark(
    'webhook',
    !empty($result['ok']),
    (string)($result['error'] ?? ''),
    [
        'action' => (string)($result['action'] ?? ''),
        'local_id' => (int)($result['local_id'] ?? 0),
        'ext_id' => (string)($result['ext_id'] ?? ''),
        'keys' => array_slice(array_keys($payload), 0, 40),
        'has_body' => $body !== null,
        'appointment_id' => (string)($payload['appointment_id'] ?? ''),
    ],
    (string)($result['ext_id'] ?? $payload['client_id'] ?? $payload['id'] ?? '')
);

if (empty($result['ok'])) {
    AgentHttp::error((string)($result['error'] ?? 'ingest failed'), 400, ['result' => $result]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'accepted' => true,
    'result' => $result,
]);
