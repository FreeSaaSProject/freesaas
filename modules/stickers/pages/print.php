<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$idsRaw = trim((string)($_GET['ids'] ?? $_POST['ids'] ?? ''));
$ids = $idsRaw !== '' ? StickerPrint::parseIdsParam($idsRaw) : [];

if ($ids !== []) {
    $items = StickerInsRepository::listForPrintByIds($ids);
} else {
    // All pending (multi-page A4); was hard-capped at 65.
    $items = StickerInsRepository::listForPrint(0);
}
$payload = StickerPrint::buildPayload($items);

if ($method === 'POST') {
    // Print sheet is read-only (oper never marks DT_PRINT here — scan activates).
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'printed' => (int)$payload['total'],
            'pages' => (int)$payload['pages'],
            'stickers' => $payload['stickers'],
        ]);
        return;
    }
    flash_set('ok', t('stickers.print.ok', ['n' => (int)$payload['total']]));
    $jump = '/stickers/ins/print';
    if ($ids !== []) {
        $jump .= '?ids=' . implode(',', $ids);
    }
    redirect($jump);
}

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

View::render('print', [
    'title' => t('stickers.print.title'),
    'stickers' => $payload['stickers'],
    'pages' => StickerPrint::pages($payload['stickers']),
    'public_mode' => false,
    'auto_print' => false,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('stickers.menu.ins'), 'href' => '/stickers/ins'],
        ['label' => t('stickers.print.title'), 'current' => true],
    ]),
], 'layout', 'stickers');
