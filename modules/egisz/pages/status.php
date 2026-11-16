<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Live N3 probes (SOAP WSDL / term) only when ?live=1 — without OpenVPN they hang the worker.
 */

$transport = EgiszRegistry::transport();
$signer = EgiszRegistry::signer();
$counts = EgiszOutboxRepository::countsByStatus();
$cfg = EgiszConfig::allPublic();
$live = isset($_GET['live']) && (string)$_GET['live'] !== '' && (string)$_GET['live'] !== '0';

$soap = [
    'probed' => false,
    'hint' => t('egisz.status.probe_hint'),
];
$term = [
    'probed' => false,
    'hint' => t('egisz.status.probe_hint'),
];
if ($live) {
    if ($transport instanceof N3HealthTransport) {
        $soap = $transport->diagnoseSoap();
        $soap['probed'] = true;
    }
    $term = (new N3TerminologyClient())->ping();
    $term['probed'] = true;
}

$payload = [
    'ok' => true,
    'maturity' => 'alpha',
    'deploy' => 'demomed.freesaas.ru',
    'live' => $live,
    'config' => $cfg,
    'transport' => [
        'id' => $transport->id(),
        'configured' => $transport->configured(),
    ],
    'signer' => [
        'id' => $signer->id(),
        'available' => $signer->available(),
        'note' => t('egisz.note.unsigned'),
    ],
    'soap' => $soap,
    'terminology' => $term,
    'outbox' => $counts,
    'mo_oid_set' => EgiszConfig::moOid() !== '',
];

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('egisz.status.title'),
    'cfg' => EgiszConfig::all(),
    'live' => $live,
    'transport' => $payload['transport'],
    'signer' => $payload['signer'],
    'soap' => $soap,
    'term' => $term,
    'counts' => $counts,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('egisz.menu.journal'), 'href' => '/egisz/journal'],
        ['label' => t('egisz.status.title'), 'current' => true],
    ]),
], 'layout', 'egisz');
