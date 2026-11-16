<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$system = trim((string)($_GET['system'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$filter = trim((string)($_GET['q'] ?? $_GET['filter'] ?? ''));
$client = new N3TerminologyClient();
$res = $client->lookup($system, $code, $filter, 20);

if (AgentHttp::wantsJson() || isset($_GET['ajax'])) {
    AgentHttp::emit(array_merge(['ok' => !empty($res['ok'])], $res));
    return;
}

redirect('/egisz/status');
