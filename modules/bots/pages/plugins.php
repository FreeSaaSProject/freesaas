<?php
declare(strict_types=1);

$plugins = BotPlugins::listForUi();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'plugins' => $plugins,
    ]);
}

View::render('plugins', [
    'title' => t('bots.plugins.title'),
    'plugins' => $plugins,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.settings'), 'href' => '/bots/settings'],
        ['label' => t('bots.menu.plugins'), 'current' => true],
    ]),
], 'layout', 'bots');
