<?php
declare(strict_types=1);

OAuthServer::corsHeaders();
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$doc = OpenApiBuilder::document();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
