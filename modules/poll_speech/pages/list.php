<?php
declare(strict_types=1);

$day = trim((string)($_GET['day'] ?? ''));
$progress = (string)($_GET['progress'] ?? '');
$q = max(0, (int)($_GET['q'] ?? 0));

$result = PollSpeechRepository::listOral([
    'day' => $day,
    'progress' => $progress,
    'limit' => 100,
    'offset' => 0,
]);
$items = $result['items'];
if ($q > 0) {
    $items = array_values(array_filter($items, static function (array $row) use ($q): bool {
        return (int)($row['id'] ?? 0) === $q;
    }));
    if ($items === [] && class_exists('PollInvitesRepository', false)) {
        $one = PollInvitesRepository::find($q);
        if ($one !== null) {
            $items = [$one];
            $result['total'] = 1;
        }
    } else {
        $result['total'] = count($items);
    }
}

$pollNames = class_exists('PollsRepository', false) ? PollsRepository::rootNames(false) : [];

$extraStaffIds = [];
foreach ($items as $row) {
    foreach (['id_proc_operator', 'id_proc_admin', 'proc_operator', 'proc_admin'] as $k) {
        $sid = (int)($row[$k] ?? 0);
        if ($sid > 0) {
            $extraStaffIds[] = $sid;
        }
    }
    $resp = (string)($row['resp_users'] ?? '');
    if ($resp !== '' && preg_match_all('/#(\d+)#/', $resp, $m)) {
        foreach ($m[1] as $rid) {
            $extraStaffIds[] = (int)$rid;
        }
    }
}
$staffNames = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];

$enriched = [];
foreach ($items as $row) {
    $op = (int)($row['id_proc_operator'] ?? 0);
    if ($op < 1) {
        $op = (int)($row['proc_operator'] ?? 0);
    }
    $adm = (int)($row['id_proc_admin'] ?? 0);
    if ($adm < 1) {
        $adm = (int)($row['proc_admin'] ?? 0);
    }
    $scoreStaff = isset($row['score_staff']) && $row['score_staff'] !== null && $row['score_staff'] !== ''
        ? (int)$row['score_staff'] : 0;
    $scoreService = isset($row['score_service']) && $row['score_service'] !== null && $row['score_service'] !== ''
        ? (int)$row['score_service'] : 0;
    if (($scoreStaff < 1 || $scoreService < 1) && class_exists('PollSpeechScore', false)
        && class_exists('PollAnswersCodec', false)
    ) {
        $opts = PollAnswersCodec::decode((string)($row['data_opts'] ?? ''));
        if (is_array($opts)) {
            $decoded = PollSpeechScore::fromDataOpts($opts);
            if ($scoreStaff < 1) {
                $scoreStaff = (int)$decoded['staff'];
            }
            if ($scoreService < 1) {
                $scoreService = (int)$decoded['service'];
            }
        }
    }
    $score = $scoreStaff >= 1 ? $scoreStaff : ($scoreService >= 1 ? $scoreService : null);
    $row['id_proc_operator'] = $op;
    $row['id_proc_admin'] = $adm;
    $row['score'] = $score;
    $row['score_staff'] = $score;
    $row['score_service'] = $score;
    $row['executor_name'] = $op > 0 ? (string)($staffNames[$op] ?? ('#' . $op)) : '';
    $row['admin_name'] = $adm > 0 ? (string)($staffNames[$adm] ?? ('#' . $adm)) : '';
    $enriched[] = $row;
}
$items = $enriched;

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'total' => $result['total'],
        'day' => $day,
        'progress' => $progress,
    ]);
    return;
}

View::render('list', [
    'title' => t('poll_speech.menu.list'),
    'items' => $items,
    'total' => $result['total'],
    'day' => $day,
    'progress' => $progress,
    'pollNames' => $pollNames,
    'staffNames' => $staffNames,
    'canEdit' => Auth::can('poll_speech.edit'),
    'canSuper' => Auth::capability('SUPERADMIN_ACCESS'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_speech.menu.list'), 'current' => true],
    ]),
], 'layout', 'poll_speech');
