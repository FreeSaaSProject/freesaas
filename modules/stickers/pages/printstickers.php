<?php
declare(strict_types=1);

/**
 * Optional anonymous A4 print sheet (oper printstickers=1).
 * Enabled only via cfg/modules/stickers.ini public_print_enabled=1.
 * GET-only: no mutations, no ids filter, no JSON API.
 */

if (!StickerPrint::isPublicPrintEnabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo t('stickers.print.public_disabled');
    return;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method Not Allowed';
    return;
}

// Print-only surface: ignore ids / format / Accept JSON (no scraping API, no selection).
$items = StickerInsRepository::listForPrint(0);
$payload = StickerPrint::buildPayload($items);

View::render('print', [
    'title' => t('stickers.print.title'),
    'stickers' => $payload['stickers'],
    'pages' => StickerPrint::pages($payload['stickers']),
    'public_mode' => true,
    'auto_print' => true,
], 'layout_auth', 'stickers');
