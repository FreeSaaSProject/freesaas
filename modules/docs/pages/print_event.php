<?php
declare(strict_types=1);

/**
 * Oper-style «print all»: one preview with every printable document of the visit.
 * POST freeze=1 freezes each unprinted instance (same as single print).
 */
$wantsJson = AgentHttp::wantsJson();
$eventId = (int)(Router::param('id') ?? 0);
$event = $eventId > 0 && class_exists('EventsRepository', false) ? EventsRepository::find($eventId) : null;
if ($event === null || !empty($event['is_block'])) {
    $msg = t('docs.err.event_not_found', ['id' => $eventId]);
    if ($wantsJson) {
        AgentHttp::error($msg, 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => $msg, 'message' => $msg]);
    return;
}
if (!DocsConfig::canSeeEventDocs()) {
    $msg = t('docs.err.no_medical');
    if ($wantsJson) {
        AgentHttp::error($msg, 403);
        return;
    }
    http_response_code(403);
    View::render('error', ['title' => $msg, 'message' => $msg]);
    return;
}

$instances = array_values(array_filter(
    DocsInstancesRepository::listForEvent($eventId),
    static function (array $i): bool {
        if ((string)($i['status'] ?? '') === 'void') {
            return false;
        }
        return DocsConfig::canSeeKind((string)($i['kind'] ?? ''));
    }
));

$error = '';
$frozen = 0;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'freeze') {
    try {
        $onlyIds = null;
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $onlyIds = [];
            foreach ($_POST['ids'] as $raw) {
                $oid = (int)$raw;
                if ($oid > 0) {
                    $onlyIds[$oid] = true;
                }
            }
            if ($onlyIds === []) {
                throw new RuntimeException(t('docs.print_event.err_none_selected'));
            }
        }
        foreach ($instances as $inst) {
            $id = (int)$inst['id'];
            if ($id < 1 || (int)($inst['dt_print'] ?? 0) > 0) {
                continue;
            }
            if ($onlyIds !== null && !isset($onlyIds[$id])) {
                continue;
            }
            if ((string)$inst['status'] === 'void') {
                continue;
            }
            // listForEvent is meta-only; render needs payload via find().
            $full = DocsInstancesRepository::find($id);
            if ($full === null) {
                continue;
            }
            $def = DocsDefsRepository::find((int)$full['id_def']);
            if ($def !== null && !empty($def['require_medcard']) && DocsSpawner::eventMedcardBlocking($eventId)) {
                throw new RuntimeException(t('docs.err.medcard_not_filled'));
            }
            DocsInstancesRepository::markPrinted($id, DocsRender::render($full), $def !== null ? (int)$def['expire_days'] : 0);
            $frozen++;
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'event_id' => $eventId,
                'frozen' => $frozen,
                'ids' => $onlyIds !== null ? array_map('intval', array_keys($onlyIds)) : null,
            ]);
            return;
        }
        $qs = 'autoprint=1';
        if ($onlyIds !== null) {
            foreach (array_keys($onlyIds) as $oid) {
                $qs .= '&ids[]=' . (int)$oid;
            }
        }
        redirect('/documents/event/' . $eventId . '/print-all?' . $qs);
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
    $instances = array_values(array_filter(
        DocsInstancesRepository::listForEvent($eventId),
        static function (array $i): bool {
            if ((string)($i['status'] ?? '') === 'void') {
                return false;
            }
            return DocsConfig::canSeeKind((string)($i['kind'] ?? ''));
        }
    ));
}

$sheets = [];
$allPrinted = $instances !== [];
foreach ($instances as $inst) {
    $id = (int)$inst['id'];
    $full = DocsInstancesRepository::find($id);
    if ($full === null) {
        continue;
    }
    $printed = (int)($full['dt_print'] ?? 0) > 0;
    if (!$printed) {
        $allPrinted = false;
    }
    $snapshot = trim((string)($full['print_snapshot'] ?? ''));
    $html = ($printed && $snapshot !== '') ? $snapshot : DocsRender::render($full);
    $version = DocsDefsRepository::version((int)$full['id_version']);
    $sheets[] = [
        'id' => $id,
        'name' => (string)($full['def_name'] ?? $inst['def_name'] ?? ''),
        'printed' => $printed,
        'html' => $html,
        'orientation' => (string)($version['orientation'] ?? 'portrait'),
        'format' => (string)($version['format'] ?? 'A4'),
    ];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'event_id' => $eventId,
        'count' => count($sheets),
        'all_printed' => $allPrinted,
        'items' => array_map(static fn(array $s): array => [
            'id' => $s['id'],
            'name' => $s['name'],
            'printed' => $s['printed'],
        ], $sheets),
    ]);
    return;
}

// Optional ?ids[]=… after freeze: pre-exclude other sheets for autoprint.
$preselect = null;
if (isset($_GET['ids']) && is_array($_GET['ids'])) {
    $preselect = [];
    foreach ($_GET['ids'] as $raw) {
        $oid = (int)$raw;
        if ($oid > 0) {
            $preselect[$oid] = true;
        }
    }
    if ($preselect === []) {
        $preselect = null;
    }
}

$backUrl = NavContext::carryReturn('/calendar/events/' . $eventId . '/edit');
View::render('print_event', [
    'title' => t('docs.print_event.title', ['id' => $eventId]),
    'eventId' => $eventId,
    'sheets' => $sheets,
    'allPrinted' => $allPrinted,
    'error' => $error,
    'backUrl' => $backUrl,
    'autoPrint' => isset($_GET['autoprint']),
    'preselectIds' => $preselect,
], '', 'docs');
