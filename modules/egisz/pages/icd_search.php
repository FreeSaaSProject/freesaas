<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$q = trim((string)($_GET['q'] ?? ''));
$items = Icd10Repository::search($q, 20);

if (AgentHttp::wantsJson() || isset($_GET['ajax'])) {
    AgentHttp::emit(['ok' => true, 'q' => $q, 'items' => $items]);
    return;
}

redirect('/egisz/journal');
