<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
DocsDefsRepository::ensureSeeded();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$fail = static function (string $message, int $http) use ($wantsJson): void {
    if ($wantsJson) {
        AgentHttp::error($message, $http);
        return;
    }
    http_response_code($http);
    View::render('error', ['title' => $message, 'message' => $message]);
};

$row = null;
$def = null;
if ($isNew) {
    $defRaw = trim((string)($_POST['def'] ?? $_GET['def'] ?? ''));
    $def = ctype_digit($defRaw) ? DocsDefsRepository::find((int)$defRaw) : DocsDefsRepository::findByCode($defRaw);
    if ($def === null || empty($def['enabled'])) {
        $fail(t('docs.def.err.not_found', ['id' => $defRaw]), 404);
        return;
    }
    $eventId = (int)($_POST['event'] ?? $_GET['event'] ?? 0);
    $clientId = (int)($_POST['client'] ?? $_GET['client'] ?? 0);
    $procedureId = (int)($_POST['procedure'] ?? $_GET['procedure'] ?? 0);
    if ($clientId < 1 && $eventId > 0 && class_exists('EventsRepository', false)) {
        $ev = EventsRepository::find($eventId);
        $clientId = $ev ? (int)($ev['id_user'] ?? 0) : 0;
    }
    if ($def['kind'] === 'medcard_profile' && $clientId > 0) {
        $pid = DocsSpawner::ensureProfile($clientId);
        if ($pid !== null) {
            redirect('/documents/' . $pid . '/edit');
            return;
        }
    }
    $row = [
        'id' => 0,
        'id_def' => (int)$def['id'],
        'id_version' => (int)$def['id_version_current'],
        'kind' => (string)$def['kind'],
        'id_client' => $clientId,
        'id_event' => $eventId,
        'id_procedure' => $procedureId,
        'id_parent' => 0,
        'status' => 'draft',
        'payload' => [],
        'dt_print' => 0,
        'dt_expire' => 0,
        'def_name' => (string)$def['name'],
        'def_code' => (string)$def['code'],
    ];
} else {
    $row = DocsInstancesRepository::find($id);
    if ($row === null) {
        $fail(t('docs.err.not_found', ['id' => $id]), 404);
        return;
    }
    $def = DocsDefsRepository::find((int)$row['id_def']);
}

if (!DocsConfig::canSeeKind((string)$row['kind'])) {
    $fail(t('docs.err.no_medical'), 403);
    return;
}

$version = DocsDefsRepository::version((int)$row['id_version']);
if ($version === null) {
    $fail(t('docs.err.no_version', ['code' => (string)$row['def_code']]), 500);
    return;
}
$schema = $version['schema'];
$canEdit = Auth::can('docs.edit');
$hasProposals = class_exists('DocsProposals', false);

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if (!$canEdit) {
            throw new RuntimeException(t('docs.err.no_edit'));
        }
        $values = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        if ($isNew) {
            $newId = DocsInstancesRepository::create($def, [
                'client' => (int)$row['id_client'],
                'event' => (int)$row['id_event'],
                'procedure' => (int)$row['id_procedure'],
                'payload' => $values,
            ]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => DocsInstancesRepository::find($newId)]);
                return;
            }
            redirect('/documents/' . $newId . '/edit?saved=1');
            return;
        }
        switch ($action) {
            case 'revise':
                $newId = DocsInstancesRepository::revise($id);
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'id' => $newId, 'voided' => $id]);
                    return;
                }
                redirect('/documents/' . $newId . '/edit?saved=1');
                return;
            case 'void':
                DocsInstancesRepository::setStatus($id, 'void');
                $message = t('docs.msg.voided');
                break;
            case 'accept':
            case 'reject':
                if (!$hasProposals) {
                    throw new RuntimeException(t('docs.err.no_proposals'));
                }
                $propId = (int)($_POST['proposal_id'] ?? 0);
                $action === 'accept' ? DocsProposals::accept($propId, $id) : DocsProposals::reject($propId, $id);
                $message = t($action === 'accept' ? 'docs.msg.accepted' : 'docs.msg.rejected');
                break;
            case 'pull_segments':
                if (!$hasProposals) {
                    throw new RuntimeException(t('docs.err.no_proposals'));
                }
                $n = DocsProposals::proposeTalkSegments($id);
                $message = $n > 0 ? t('docs.msg.segments_proposed', ['n' => $n]) : t('docs.msg.segments_none');
                break;
            case 'confirm':
                if (!DocsInstancesRepository::confirmReady()) {
                    throw new RuntimeException(t('docs.confirm.err.not_ready'));
                }
                if (!in_array((string)$row['status'], ['printed', 'void'], true)) {
                    DocsInstancesRepository::updateFields($id, $values, 'manual');
                }
                $uid = (int)(Auth::userId() ?? 0);
                $fresh = DocsInstancesRepository::find($id) ?: $row;
                if ((string)$fresh['status'] === 'draft') {
                    throw new RuntimeException(t('docs.confirm.err.draft'));
                }
                if ((int)$row['id_event'] > 0) {
                    $res = DocsInstancesRepository::confirmForEvent((int)$row['id_event'], $uid);
                } else {
                    $res = ['confirmed' => DocsInstancesRepository::confirm($id, $uid) ? [$id] : [], 'already' => [], 'drafts' => []];
                }
                $message = t('docs.confirm.msg.done', ['n' => count($res['confirmed']) + count($res['already'])]);
                if ($res['drafts'] !== []) {
                    $error = t('docs.confirm.msg.drafts', ['list' => implode(', ', array_column($res['drafts'], 'name'))]);
                }
                break;
            default:
                $changed = DocsInstancesRepository::updateFields($id, $values, 'manual');
                $message = $changed !== [] ? t('core.common.saved') : t('docs.msg.no_changes');
        }
        $row = DocsInstancesRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'action' => $action, 'item' => $row]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        if (isset($values) && is_array($values)) {
            foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
                if (array_key_exists($fid, $values)) {
                    $row['payload'][$fid] = DocsSchema::normalizeValue($f, $values[$fid]);
                }
            }
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$proposals = (!$isNew && $hasProposals) ? DocsProposals::pendingFor($id) : [];
$fieldMap = DocsSchema::fieldMap($schema);
$staffIds = [];
foreach ($fieldMap as $fid => $f) {
    if ((string)$f['type'] === 'staff' && (int)($row['payload'][$fid] ?? 0) > 0) {
        $staffIds[] = (int)$row['payload'][$fid];
    }
}
$staffNames = class_exists('UsersRepository', false) ? UsersRepository::staffNamesIncluding($staffIds) : [];
$missing = DocsSchema::missingRequired($schema, $row['payload']);
$locked = in_array((string)$row['status'], ['printed', 'void'], true);
$hasSegments = false;
foreach ($fieldMap as $f) {
    if ((string)$f['type'] === 'segments') {
        $hasSegments = true;
        break;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'def' => $def,
        'version' => (int)$version['version'],
        'schema' => $schema,
        'missing_required' => $missing,
        'proposals' => $proposals,
    ]);
    return;
}

// Review banner: values filled automatically (audio) wait for the doctor's confirmation.
$review = ['auto' => false, 'auto_at' => 0, 'record_id' => 0, 'confirmed_at' => 0, 'confirmed_by' => '', 'event_docs' => []];
if (!$isNew) {
    foreach ((array)($row['payload']['_prov'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $src = (string)($p['source'] ?? '');
        $from = (string)($p['edited_from'] ?? '');
        if (in_array($src, DocsInstancesRepository::AUTO_SOURCES, true) || $from !== '') {
            $review['auto'] = true;
            $review['auto_at'] = max($review['auto_at'], (int)($p['recorded_at'] ?? $p['at'] ?? 0));
            if ($review['record_id'] < 1 && (int)($p['record_id'] ?? 0) > 0) {
                $review['record_id'] = (int)$p['record_id'];
            }
        }
    }
    $review['confirmed_at'] = (int)($row['dt_confirmed'] ?? 0);
    if ($review['confirmed_at'] > 0 && (int)($row['id_user_confirmed'] ?? 0) > 0 && class_exists('UsersRepository', false)) {
        $names = UsersRepository::staffNamesIncluding([(int)$row['id_user_confirmed']]);
        $review['confirmed_by'] = (string)($names[(int)$row['id_user_confirmed']] ?? '');
    }
    if ((int)$row['id_event'] > 0) {
        foreach (DocsInstancesRepository::listForEvent((int)$row['id_event']) as $inst) {
            $review['event_docs'][] = [
                'id' => (int)$inst['id'],
                'name' => (string)$inst['def_name'],
                'status' => (string)$inst['status'],
                'confirmed' => (int)($inst['dt_confirmed'] ?? 0) > 0,
            ];
        }
    }
}

$clientLabel = '';
if ((int)$row['id_client'] > 0 && class_exists('ClientsRepository', false)) {
    $c = ClientsRepository::find((int)$row['id_client']);
    $clientLabel = $c ? trim((string)($c['name'] ?: $c['tel'] ?: '')) : '';
}

$title = $isNew
    ? t('docs.edit.title_new', ['name' => (string)$row['def_name']])
    : t('docs.edit.title', ['id' => $id, 'name' => (string)$row['def_name']]);
View::render('edit', [
    'title' => $title,
    'isNew' => $isNew,
    'row' => $row,
    'def' => $def,
    'version' => $version,
    'schema' => $schema,
    'staffNames' => $staffNames,
    'missing' => $missing,
    'locked' => $locked,
    'canEdit' => $canEdit,
    'canPrint' => Auth::can('docs.print'),
    'proposals' => $proposals,
    'hasSegments' => $hasSegments && !$isNew && $hasProposals,
    'clientLabel' => $clientLabel,
    'review' => $review,
    'canConfirm' => $canEdit && DocsInstancesRepository::confirmReady(),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.list'), 'href' => '/documents'],
        ['label' => $isNew ? (string)$row['def_name'] : ('#' . $id), 'current' => true],
    ]),
], 'layout', 'docs');
