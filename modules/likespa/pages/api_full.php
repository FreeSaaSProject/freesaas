<?php
declare(strict_types=1);

if (!LikespaConfig::flag('legacy_api_enabled', false)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'legacy API disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}
LikespaLegacyApi::dispatchFull();
