<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $res = EgiszPatientSync::sync($clientId);
    if (AgentHttp::wantsJson()) {
        if (!empty($res['ok'])) {
            AgentHttp::emit($res);
        } else {
            AgentHttp::error((string)($res['error'] ?? 'error'), 400, $res);
        }
        return;
    }
    if (!empty($res['ok'])) {
        flash('ok', t('egisz.patient.synced'));
    } else {
        flash('err', (string)($res['error'] ?? t('egisz.err.n3_call')));
    }
    redirect($clientId > 0 ? '/clients/' . $clientId : '/egisz/journal');
}

if (AgentHttp::wantsJson()) {
    AgentHttp::error('POST required', 405);
    return;
}

redirect('/egisz/journal');
