<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$status = trim((string)($_GET['status'] ?? ''));
$clientId = (int)($_GET['client_id'] ?? 0);
$result = EgiszOutboxRepository::list([
    'status' => $status,
    'client_id' => $clientId,
    'limit' => 50,
    'offset' => 0,
]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['rows'],
        'statuses' => EgiszOutboxRepository::STATUSES,
    ]);
    return;
}

View::render('journal', [
    'title' => t('egisz.journal.title'),
    'rows' => $result['rows'],
    'total' => $result['total'],
    'status' => $status,
    'statuses' => EgiszOutboxRepository::STATUSES,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('egisz.journal.title'), 'current' => true],
    ]),
], 'layout', 'egisz');
