<?php
declare(strict_types=1);

if (!LikespaLegacyApi::termyEnabled()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'legacy API disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}
LikespaLegacyApi::dispatchTermy();
