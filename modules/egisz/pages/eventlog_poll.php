<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $res = EgiszCaseSync::pollEventlog(50);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit($res);
        return;
    }
    flash('ok', t('egisz.eventlog.updated', ['n' => (string)(int)($res['updated'] ?? 0)]));
    redirect('/egisz/journal');
}

if (AgentHttp::wantsJson()) {
    $res = EgiszCaseSync::pollEventlog(50);
    AgentHttp::emit($res);
    return;
}

redirect('/egisz/journal');
