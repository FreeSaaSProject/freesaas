<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$returnUrl = ProceduresRepository::sanitizeReturnUrl(
    (string)($_POST['ret'] ?? $_GET['ret'] ?? '')
);

$row = [];
if (!$isNew) {
    $found = ProceduresRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('procedures.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('procedures.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $row = [
        'enabled' => 1,
        'dtstart' => time(),
        'id_admin' => Auth::userId(),
        'id_operator' => Auth::userId(),
    ];
}

// Baselines for chain-solo UI (before POST may merge into $row).
$chainBaseDtstart = !$isNew
    ? ProceduresRepository::tsToLocal((int)($row['dtstart'] ?? 0))
    : '';
$chainBaseOperator = !$isNew ? (string)(int)($row['id_operator'] ?? 0) : '0';

$acl = ProceduresEditAcl::forRow($row, $isNew);
if (empty($acl['allowed'])) {
    if ($wantsJson) {
        AgentHttp::error((string)($acl['deny_reason'] ?: t('procedures.edit.err.foreign')), 403);
        return;
    }
    http_response_code(403);
    $deny = (string)($acl['deny_reason'] ?: t('procedures.edit.err.foreign'));
    View::render('error', [
        'title' => $deny,
        'message' => $deny,
    ], 'layout');
    return;
}

$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('procedures', $id, '/procedures', [
    'stay_path' => '/procedures/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // Recompute ACL from posted id_good so timing lock follows product change.
        $probe = $row;
        if (ProceduresEditAcl::canEditField($acl, 'id_good') && isset($_POST['id_good'])) {
            $probe['id_good'] = (int)$_POST['id_good'];
        }
        $acl = ProceduresEditAcl::forRow($probe, $isNew);
        $data = ProceduresEditAcl::dataFromPost($_POST, $isNew ? [] : $row, $acl);
        // Default: only this row + chill#{id}. Checked chain_move = full abutting block.
        $moveFullChain = !$isNew && !empty($_POST['chain_move']);
        $chain = [];
        if (!$isNew && class_exists('ProceduresChain', false)) {
            $chain = $moveFullChain
                ? ProceduresChain::contiguousAround($id)
                : ProceduresChain::unitAround($id);
        }
        $excludeIds = [];
        foreach ($chain as $cr) {
            $cid = (int)($cr['id'] ?? 0);
            if ($cid > 0) {
                $excludeIds[] = $cid;
            }
        }
        if ($excludeIds === [] && !$isNew) {
            $excludeIds = [$id];
        }
        $doRelocate = !$isNew
            && class_exists('ProceduresChain', false)
            && ProceduresChain::needsRelocate($data, $row)
            && count($chain) > 1;
        if (
            Config::moduleEnabled('booking')
            && class_exists(BookingValidate::class, false)
        ) {
            $lead = class_exists(BookingConfig::class, false)
                ? BookingConfig::leadSec()
                : null;
            $opId = (int)($data['id_operator'] ?? 0);
            $oldStart = (int)($row['dtstart'] ?? 0);
            $newStart = (int)($data['dtstart'] ?? $oldStart);
            $delta = ($doRelocate && $newStart > 0 && $oldStart > 0)
                ? ($newStart - $oldStart)
                : 0;
            if ($isNew) {
                $goodId = (int)($data['id_good'] ?? 0);
                if ($opId > 0 && $goodId > 0 && $newStart > 0) {
                    BookingValidate::assertSlot(
                        [$goodId],
                        $opId,
                        $newStart,
                        null,
                        true,
                        $lead,
                        null
                    );
                }
            } else {
                // Existing rows: use real DTEND−DTSTART (catalog ПЕРЕРЫВ has time_sec=0 → 60′ default).
                $toCheck = $doRelocate ? $chain : [$row];
                foreach ($toCheck as $cr) {
                    $rid = (int)($cr['id'] ?? 0);
                    $goodId = $rid === $id
                        ? (int)($data['id_good'] ?? $cr['id_good'] ?? 0)
                        : (int)($cr['id_good'] ?? 0);
                    $dtStart = $rid === $id
                        ? $newStart
                        : ((int)($cr['dtstart'] ?? 0) + $delta);
                    $dtEnd = $rid === $id
                        ? (int)($data['dtend'] ?? 0)
                        : ((int)($cr['dtend'] ?? 0) + $delta);
                    if ($dtEnd <= $dtStart && $rid === $id) {
                        $oldEnd = (int)($row['dtend'] ?? 0);
                        if ($oldEnd > $oldStart) {
                            $dtEnd = $dtStart + ($oldEnd - $oldStart);
                        }
                    }
                    if ($opId < 1 || $goodId < 1 || $dtStart < 1 || $dtEnd <= $dtStart) {
                        continue;
                    }
                    $probeRow = $rid === $id ? array_merge($cr, $data) : $cr;
                    BookingValidate::assertProcedureWindow(
                        $opId,
                        $dtStart,
                        $dtEnd,
                        $goodId,
                        $excludeIds,
                        [
                            'allow_past_work_end' => class_exists('ProceduresChain', false)
                                && ProceduresChain::isBreak($probeRow),
                        ]
                    );
                }
            }
        }
        if ($isNew) {
            $newId = ProceduresRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => ProceduresRepository::find($newId),
                ]);
                return;
            }
            redirect(ListQuery::url('/procedures/' . $newId . '/edit', [
                'saved' => 1,
                'ret' => $returnUrl,
            ]));
            return;
        }
        $movedLines = [];
        $chainCount = 1;
        if (class_exists('ProceduresChain', false)) {
            $relocated = ProceduresChain::relocateOnUpdate($id, $data, $row, $moveFullChain);
            $chainCount = (int)($relocated['count'] ?? 1);
            $movedLines = is_array($relocated['lines'] ?? null) ? $relocated['lines'] : [];
        } else {
            ProceduresRepository::update($id, $data);
        }
        $row = ProceduresRepository::find($id) ?: $row;
        $acl = ProceduresEditAcl::forRow($row, false);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => $row,
                'chain_moved' => $chainCount,
                'chain_moved_lines' => $movedLines,
            ]);
            return;
        }
        if ($movedLines !== []) {
            $message = t('procedures.edit.chain_saved_list', [
                'count' => max($chainCount, count($movedLines)),
                'list' => implode('; ', $movedLines),
            ]);
        } elseif ($chainCount > 1) {
            $message = t('procedures.edit.chain_saved', ['count' => $chainCount]);
        } else {
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
        $acl = ProceduresEditAcl::forRow($row, $isNew);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staffNames = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffNames[(int)$u['id']] = $label;
}

$clientLabel = '';
$clientId = (int)($row['id_user'] ?? 0);
if ($clientId > 0) {
    $client = ClientsRepository::find($clientId);
    if ($client) {
        $clientLabel = trim((string)($client['name'] ?: $client['tel'] ?: ''));
    }
}

$chainLen = (!$isNew && class_exists('ProceduresChain', false))
    ? count(ProceduresChain::contiguousAround($id))
    : 0;
$showChainMove = $chainLen > 1;
$chainMoveChecked = !empty($_POST['chain_move']);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'is_new' => $isNew,
        'acl' => [
            'editable' => $acl['editable'],
            'lock_dtend_timing' => !empty($acl['lock_dtend_timing']),
        ],
        'procedure_types' => ProcedureTypesRepository::names(),
        'staff' => $staffNames,
        'client_label' => $clientLabel,
        'chain_len' => $chainLen,
        'chain_move_available' => $showChainMove,
    ]);
    return;
}

$sections = $isNew ? null : EventBus::emit('procedures.edit.sections', [
    'html' => '',
    'row' => $row,
    'id' => $id,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

View::render('edit', [
    'title' => $isNew
        ? t('procedures.edit.title_new')
        : t('procedures.edit.title', ['id' => $id]),
    'extraHtml' => $extraHtml,
    'isNew' => $isNew,
    'row' => $row,
    'acl' => $acl,
    'message' => $message,
    'error' => $error,
    'returnUrl' => $returnUrl,
    'typeNames' => ProcedureTypesRepository::names(),
    'productNames' => ProductsRepository::names(true),
    'staffNames' => $staffNames,
    'clientLabel' => $clientLabel,
    'showChainMove' => $showChainMove,
    'chainLen' => $chainLen,
    'chainMoveChecked' => $chainMoveChecked,
    'chainBaseDtstart' => $chainBaseDtstart,
    'chainBaseOperator' => $chainBaseOperator,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('procedures.menu.list'), 'href' => '/procedures'],
        [
            'label' => $isNew ? t('procedures.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'procedures');
