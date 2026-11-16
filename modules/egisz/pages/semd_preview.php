<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$instanceId = (int)($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
$kind = strtolower(trim((string)($_GET['kind'] ?? $_POST['kind'] ?? 'consult')));
if ($kind !== 'manip') {
    $kind = 'consult';
}

$doc = SemdFactory::fromInstance($instanceId, $kind);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['save_outbox'])) {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $status = $doc->ok() ? 'validated' : 'draft';
    $id = EgiszOutboxRepository::insert([
        'local_uid' => $doc->localUid,
        'kind' => $doc->kind,
        'id_instance' => $doc->instanceId,
        'id_client' => $doc->clientId,
        'id_event' => $doc->eventId,
        'id_doctor' => $doc->doctorId,
        'status' => $status,
        'semd_xml' => $doc->xml !== '' ? $doc->xml : null,
        'validation' => [
            'errors' => $doc->errors,
            'warnings' => $doc->warnings,
            'diagnoses' => $doc->diagnoses,
        ],
        'error_text' => $doc->errors !== [] ? implode('; ', $doc->errors) : null,
        'id_admin' => (int)(Auth::userId() ?? 0),
    ]);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'outbox_id' => $id, 'document' => $doc->toArray()]);
        return;
    }
    flash('ok', t('egisz.preview.saved'));
    redirect('/egisz/journal');
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'document' => $doc->toArray()]);
    return;
}

View::render('semd_preview', [
    'title' => t('egisz.preview.title'),
    'doc' => $doc,
    'instanceId' => $instanceId,
    'kind' => $kind,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('egisz.menu.journal'), 'href' => '/egisz/journal'],
        ['label' => t('egisz.preview.title'), 'current' => true],
    ]),
], 'layout', 'egisz');
