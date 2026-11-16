<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$instanceId = (int)($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
$kind = strtolower(trim((string)($_GET['kind'] ?? $_POST['kind'] ?? 'consult')));
$update = !empty($_POST['update']) || (string)($_GET['update'] ?? '') === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || AgentHttp::wantsJson()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && class_exists('Csrf', false)
        && !AgentHttp::wantsJson()
        && !Csrf::verify()
    ) {
        Csrf::deny('csrf');
    }
    // Allow GET for agent diagnose with side_effect documented on POST only — agents use POST.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && AgentHttp::wantsJson()) {
        // Dry build preview via case sync path without send? Use SemdFactory only.
        $doc = SemdFactory::fromInstance($instanceId, $kind === 'manip' ? 'manip' : 'consult');
        AgentHttp::emit(['ok' => true, 'document' => $doc->toArray(), 'note' => t('egisz.note.unsigned')]);
        return;
    }
    $res = EgiszCaseSync::sendFromInstance($instanceId, $kind, $update);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(array_merge(['ok' => !empty($res['ok'])], $res));
        return;
    }
    if (!empty($res['ok'])) {
        flash('ok', t('egisz.case.sent'));
    } else {
        flash('err', (string)($res['error'] ?? t('egisz.err.n3_call')) . ' — ' . t('egisz.note.unsigned'));
    }
    redirect('/egisz/journal');
}

View::render('case_send', [
    'title' => t('egisz.case.title'),
    'instanceId' => $instanceId,
    'kind' => $kind === 'manip' ? 'manip' : 'consult',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('egisz.menu.journal'), 'href' => '/egisz/journal'],
        ['label' => t('egisz.case.title'), 'current' => true],
    ]),
], 'layout', 'egisz');
