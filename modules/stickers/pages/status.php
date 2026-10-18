<?php
declare(strict_types=1);

$report = StickersDiagnose::report();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report);
    return;
}

View::render('status', [
    'title' => t('stickers.status.title'),
    'report' => $report,
    'can_settings' => Auth::capability('SUPERADMIN_ACCESS'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('stickers.menu.ins'), 'href' => '/stickers/ins'],
        ['label' => t('stickers.status.title'), 'current' => true],
    ]),
], 'layout', 'stickers');
