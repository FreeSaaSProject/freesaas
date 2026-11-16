<?php
declare(strict_types=1);

$provider = preg_replace('/[^a-z0-9_]/', '', (string)(Router::param('provider') ?? '')) ?? '';
$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$result = Acquiring::handleWebhook($provider, is_array($payload) ? $payload : [], $raw);
http_response_code(!empty($result['ok']) ? 200 : 400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
