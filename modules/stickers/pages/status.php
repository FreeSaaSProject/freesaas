<?php
declare(strict_types=1);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$canToggle = Auth::capability('SUPERADMIN_ACCESS');

if ($method === 'POST' && $canToggle) {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'public_print') {
        $on = !empty($_POST['public_print_enabled']);
        StickerPrint::setPublicPrintEnabled($on);
        flash_set('ok', $on ? t('stickers.status.public_print_on') : t('stickers.status.public_print_off'));
        redirect('/stickers/status');
    }
}

$report = StickersDiagnose::report();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report);
    return;
}

View::render('status', [
    'title' => t('stickers.status.title'),
    'report' => $report,
    'can_toggle' => $canToggle,
    'public_print_enabled' => StickerPrint::isPublicPrintEnabled(),
    'public_print_url' => StickerPrint::publicPrintUrl(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('stickers.menu.ins'), 'href' => '/stickers/ins'],
        ['label' => t('stickers.status.title'), 'current' => true],
    ]),
], 'layout', 'stickers');
