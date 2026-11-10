<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = PollInvitesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('poll_invites.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $presetPoll = (int)($_GET['id_poll'] ?? 0);
    $row = [
        'id_poll' => $presetPoll,
        'id_user' => 0,
        'bonus_auto' => 1,
        'bonus_value' => 0,
        'is_special' => 0,
        'is_special_friend' => 0,
        'dt_avail' => time(),
        'dt_expire' => time() + 72 * 3600,
    ];
    if ($presetPoll > 0) {
        $p = PollsRepository::find($presetPoll);
        if ($p && (int)$p['el_type'] === PollsRepository::EL_POLL) {
            $row['bonus_auto'] = (int)$p['bonus_auto'];
            $row['bonus_value'] = (int)$p['bonus_default'];
            $row['is_special'] = (int)$p['is_special'];
            $row['is_special_friend'] = (int)$p['is_special_friend'];
        }
    }
}

$message = '';
$error = '';
$canSuper = Auth::capability('SUPERADMIN_ACCESS');

if (!$isNew && EntityDelete::tryHandle('poll_invites', $id, '/poll-invites', [
    'stay_path' => '/poll-invites/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'set_score' && !$isNew) {
        if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
            Csrf::deny('csrf');
            return;
        }
        if (!$canSuper) {
            if (AgentHttp::wantsJson()) {
                AgentHttp::error(t('poll_invites.err.forbidden'), 403);
                return;
            }
            flash_set('error', t('poll_invites.err.forbidden'));
            redirect('/poll-invites/' . $id . '/edit');
            return;
        }
        if (!ModuleManager::isEnabled('poll_speech') || !class_exists('PollSpeechFiller', false)) {
            $error = t('poll_invites.err.speech_score');
        } else {
            try {
                $score = (int)($_POST['score'] ?? 0);
                PollSpeechFiller::applyManualScore($id, $score);
                if (AgentHttp::wantsJson()) {
                    AgentHttp::emit(['ok' => true, 'id' => $id, 'score' => $score]);
                    return;
                }
                flash_set('ok', t('poll_invites.score.saved', ['score' => $score]));
                redirect('/poll-invites/' . $id . '/edit');
                return;
            } catch (Throwable $e) {
                AgentHttp::rethrowIfCapture($e);
                $error = $e->getMessage();
                if (AgentHttp::wantsJson()) {
                    AgentHttp::error($error, 400);
                    return;
                }
            }
        }
    } else {
        try {
            $data = PollInvitesRepository::dataFromPost($_POST, $isNew ? null : $row);
            if ($isNew) {
                $newId = PollInvitesRepository::create($data);
                if (AgentHttp::wantsJson()) {
                    AgentHttp::emit(['ok' => true, 'id' => $newId]);
                    return;
                }
                redirect('/poll-invites/' . $newId . '/edit?saved=1');
                return;
            }
            PollInvitesRepository::update($id, $data);
            $message = t('core.common.saved');
            $row = PollInvitesRepository::find($id) ?: $row;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $row = array_merge($row, $_POST);
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$pollNames = PollsRepository::rootNames(false);
$extraStaffIds = [
    (int)($row['id_operator_linked'] ?? 0),
    (int)($row['id_proc_operator'] ?? 0),
    (int)($row['id_proc_admin'] ?? 0),
];
$respRaw = (string)($row['resp_users'] ?? '');
if ($respRaw !== '' && preg_match_all('/#(\d+)#/', $respRaw, $m)) {
    foreach ($m[1] as $rid) {
        $extraStaffIds[] = (int)$rid;
    }
}
$viewers = $isNew
    ? []
    : PollInvitesRepository::viewerIdsFromViewed((string)($row['resp_users_viewed'] ?? ''));
foreach ($viewers as $vid) {
    $extraStaffIds[] = $vid;
}
$staff = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];
$staffMeta = $isNew ? [
    'id_proc_operator' => 0,
    'id_proc_admin' => 0,
    'executor_name' => '',
    'admin_name' => '',
    'score_staff' => null,
    'score_service' => null,
    'score' => null,
    'has_scores' => false,
    'has_staff' => false,
    'speech_record_ids' => [],
] : PollInvitesRepository::staffScoreMeta($row, $staff);

$canScoreEdit = $canSuper
    && !$isNew
    && ModuleManager::isEnabled('poll_speech')
    && class_exists('PollSpeechConfig', false)
    && (PollSpeechConfig::qidStaff() > 0 || PollSpeechConfig::qidService() > 0)
    && (PollSpeechConfig::answersStaff() !== [] || PollSpeechConfig::answersService() !== []);

$speechAudio = [];
$activeRecordId = max(0, (int)($_GET['record'] ?? 0));
if ($canSuper && !$isNew && ModuleManager::isEnabled('audio')) {
    $recordIds = PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? ''));
    if ($recordIds !== []) {
        require_once base_path('modules/audio/lib/RecordsRepository.php');
        require_once base_path('modules/audio/lib/TranscriptHighlighter.php');
        require_once base_path('modules/audio/lib/FileStorage.php');
        Assets::need('audio');
        $storage = FileStorage::fromSettings();
        $demoBlock = class_exists('DemoMode', false) && DemoMode::readonly();
        $trash = RecordsRepository::trashPhrases();
        foreach ($recordIds as $rid) {
            $rec = RecordsRepository::find($rid);
            if ($rec === null) {
                continue;
            }
            $playUrl = '';
            if (!$demoBlock) {
                $rel = RecordsRepository::playbackRelativePath($rec);
                if ($rel !== '') {
                    $playUrl = $storage->getPlaybackUrl($rel);
                }
            }
            $instances = RecordsRepository::instancesForRecords([$rid]);
            $transcript = TranscriptHighlighter::render(
                (string)($rec['transcription'] ?? ''),
                $instances,
                $trash
            );
            $speechAudio[] = [
                'id' => $rid,
                'play_url' => $playUrl,
                'transcript_html' => (string)($transcript['html'] ?? ''),
                'lines' => (int)($transcript['lines'] ?? 0),
                'keys' => (int)($transcript['keys'] ?? 0),
            ];
        }
        if ($activeRecordId < 1 && $speechAudio !== []) {
            $activeRecordId = (int)$speechAudio[0]['id'];
        }
    }
}

View::render('edit', [
    'title' => $isNew
        ? t('poll_invites.edit.title_new')
        : t('poll_invites.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'pollNames' => $pollNames,
    'staff' => $staff,
    'staffMeta' => $staffMeta,
    'viewers' => $viewers,
    'showAudioLinks' => true,
    'canScoreEdit' => $canScoreEdit,
    'speechAudio' => $speechAudio,
    'activeRecordId' => $activeRecordId,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.list'), 'href' => '/poll-invites'],
        [
            'label' => $isNew ? t('poll_invites.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'poll_invites');
