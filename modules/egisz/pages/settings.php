<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$title = t('egisz.settings.title');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $wantRegen = !empty($_POST['worker_token_regen']);
    $wantWorker = !empty($_POST['worker_enabled']) && (string)$_POST['worker_enabled'] !== '0';
    EgiszConfig::saveFromPost($_POST);
    EgiszRegistry::resetTransport();

    $freshToken = '';
    if ($wantRegen) {
        $freshToken = EgiszWorkerAuth::generateAndPersist();
    } elseif ($wantWorker && !EgiszWorkerAuth::isConfigured()) {
        $freshToken = EgiszWorkerAuth::ensureGenerated();
    }

    if (AgentHttp::wantsJson()) {
        $out = ['ok' => true, 'config' => EgiszConfig::allPublic()];
        if ($freshToken !== '') {
            $out['worker_token'] = $freshToken;
        }
        AgentHttp::emit($out);
        return;
    }
    if ($freshToken !== '') {
        flash('ok', t('egisz.settings.saved') . ' · ' . t('egisz.settings.worker_token_once') . ': ' . $freshToken);
    } else {
        flash('ok', t('egisz.settings.saved'));
    }
    redirect('/egisz/settings');
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'config' => EgiszConfig::allPublic(),
        'operators' => EgiszConfig::OPERATORS,
        'n3_envs' => EgiszConfig::N3_ENVS,
        'deploy_hint' => 'demomed.freesaas.ru',
        'worker_endpoints' => [
            '/egisz/worker/hello',
            '/egisz/worker/claim',
            '/egisz/worker/result',
        ],
    ]);
    return;
}

View::render('settings', [
    'title' => $title,
    'cfg' => EgiszConfig::all(),
    'operators' => EgiszConfig::OPERATORS,
    'n3Envs' => EgiszConfig::N3_ENVS,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('egisz.menu.journal'), 'href' => '/egisz/journal'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'egisz');
