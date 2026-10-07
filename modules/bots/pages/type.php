<?php
declare(strict_types=1);

$audience = strtolower(trim((string)(Router::param('audience') ?? '')));
if (!in_array($audience, BotsConfig::AUDIENCES, true)) {
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('bots.err.unknown_type'),
    ]);
    return;
}

if ($audience === 'client' && !ModuleManager::isEnabled('clients')) {
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('bots.err.client_module_off'),
    ]);
    return;
}

$channels = [];
foreach (BotsConfig::listForUi() as $ch) {
    if (($ch['audience'] ?? '') === $audience) {
        $channels[] = $ch;
    }
}

$menu = BotMenuCatalog::resolve($audience);
$counts = BotMenuCatalog::counts($menu);
$preview = BotMenuCatalog::previewCabinetLabels($audience);
$plugins = BotPlugins::listForUi($audience);
$pending = BotSendsRepository::countPending($audience);
$title = t('bots.menu.type.' . $audience);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'audience' => $audience,
        'title' => $title,
        'channels' => $channels,
        'menu' => $menu,
        'counts' => $counts,
        'preview_buttons' => $preview,
        'plugins' => $plugins,
        'pending' => $pending,
        'webhook_base' => '/bots/webhook',
    ]);
}

View::render('type', [
    'title' => $title,
    'audience' => $audience,
    'channels' => $channels,
    'menu' => $menu,
    'counts' => $counts,
    'preview' => $preview,
    'plugins' => $plugins,
    'pending' => $pending,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.group'), 'href' => '/bots/types/staff'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'bots');
