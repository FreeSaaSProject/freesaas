<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$diag = MaxGroupSpike::diagnose();

if ($wantsJson) {
    AgentHttp::emit(['ok' => !empty($diag['ok']), 'diagnose' => $diag]);
    return;
}

View::render('status', [
    'title' => t('assistant_groups.status.title'),
    'diag' => $diag,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('assistant_groups.menu.settings'), 'href' => '/assistant-groups/settings'],
        ['label' => t('assistant_groups.menu.status'), 'current' => true],
    ]),
], 'layout', 'assistant_groups');
