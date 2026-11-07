<?php
declare(strict_types=1);

BitrixJobsAuth::assertWebhookToken();

$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
$payload = is_array($json) ? $json : $_POST;
if (!is_array($payload)) {
    $payload = [];
}

$result = BitrixWebhookIngest::ingest($payload);
http_response_code(!empty($result['ok']) ? 200 : 400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
