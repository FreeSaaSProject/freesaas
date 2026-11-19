<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

require_once base_path('modules/audio/lib/RecordsRepository.php');
require_once base_path('modules/audio/lib/TranscriptHighlighter.php');
if (
    ModuleManager::isEnabled('audio_diarize')
    && is_file(base_path('modules/audio_diarize/lib/SpeakerService.php'))
) {
    require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
    require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
}

$id = (int)Router::param('id');
$flash = '';
$row = RecordsRepository::find($id);

if (!$row) {
    if ($wantsJson) {
        AgentHttp::error(t('audio.view.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('audio.view.not_found', ['id' => $id]),
        'message' => t('audio.view.not_found', ['id' => $id]),
    ], 'error', 'core');
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('audio.edit')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_disabled') {
        RecordsRepository::setDisabled($id, empty($row['disabled']));
        $row = RecordsRepository::find($id) ?? $row;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => RecordsRepository::forAgent($row, true),
            ]);
            return;
        }
        $flash = t('audio.view.saved');
    }
}

$storage = FileStorage::fromSettings();
$rel = RecordsRepository::playbackRelativePath($row);
$playUrl = '';
if (!(class_exists('DemoMode', false) && DemoMode::readonly()) && $rel !== '') {
    $playUrl = $storage->getPlaybackUrl($rel);
}

$instances = RecordsRepository::instancesForRecords([$id]);
$trash = RecordsRepository::trashPhrases();
$speakerRows = [];
$speakerOpts = [
    'cue_speakers' => [],
    'speaker_roles' => [],
    'speaker_admins' => [],
];
if (class_exists('SpeakerService', false)) {
    // Fix cue labels that disagree with Nemotron segments (false S2 on other speech).
    SpeakerService::realignCuesFromSegments($id);
    $speakerRows = SpeakerService::speakersForRecord($id);
    $speakerOpts = SpeakerService::highlightOptsForRecord($id, (int)($row['id_admin'] ?? $row['ID_ADMIN'] ?? 0));
}
$transcript = TranscriptHighlighter::render(
    (string)($row['transcription'] ?? ''),
    $instances,
    $trash,
    '',
    $speakerOpts
);
$dictMeta = RecordsRepository::dictMetaMap();
$keyDicts = TranscriptHighlighter::parseKeyDicts(isset($row['key_dicts']) ? (string)$row['key_dicts'] : null);

$diarizeOn = ModuleManager::isEnabled('audio_diarize')
    && class_exists('SpeakerService', false)
    && SpeakerService::diarizeEnabled();
$canAssignSpeaker = $diarizeOn && Auth::can('audio_diarize.edit');
$hasAsrDiaHost = $diarizeOn
    && class_exists('EdgeHosts', false)
    && EdgeHosts::forService('asr_dia') !== [];
// Allow queue even if host briefly missing — LongJob / status still useful; push needs host.
$canRunDiarize = $canAssignSpeaker;
$hasTranscription = trim((string)($row['transcription'] ?? $row['TRANSCRIPTION'] ?? '')) !== '';

$asrDiaRun = null;
if ($diarizeOn && is_file(base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php'))) {
    require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
    if (AsrDiaRunsRepository::available()) {
        $runs = AsrDiaRunsRepository::latestForRecords([$id]);
        $asrDiaRun = $runs[0] ?? null;
    }
}

if ($wantsJson) {
    $withTranscription = !empty($_GET['include_transcription']);
    $payload = [
        'ok' => true,
        'id' => $id,
        'item' => RecordsRepository::forAgent($row, $withTranscription),
        'play_url' => $playUrl,
        'instances' => $instances,
        'key_dicts' => $keyDicts,
        'can_edit' => Auth::can('audio.edit'),
        'audio_diarize' => [
            'enabled' => $diarizeOn,
            'can_assign' => $canAssignSpeaker,
            'can_run_diarize' => $canRunDiarize,
            'has_asr_dia_host' => $hasAsrDiaHost,
            'has_transcription' => $hasTranscription,
            'latest_run' => $asrDiaRun,
            // Enrollment: POST /audio/diarize/longjob ids_csv=<id> asr_model=reuse apply_transcription=0
            'run_diarize_op' => 'audio_diarize.diarize.longjob',
        ],
    ];
    if ($diarizeOn) {
        $payload['speakers'] = $speakerRows;
    }
    AgentHttp::emit($payload);
    return;
}

View::render('view', [
    'title' => '#' . $id,
    'breadcrumbs' => Breadcrumbs::withHome([
        // href rewritten to ret=… when path matches (keeps list filters)
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => '#' . $id, 'current' => true],
    ]),
    'row' => $row,
    'flash' => $flash,
    'playUrl' => $playUrl,
    'canEdit' => Auth::can('audio.edit'),
    'transcript' => $transcript,
    'instances' => $instances,
    'dictMeta' => $dictMeta,
    'keyDicts' => $keyDicts,
    'speakers' => $speakerRows,
    'canAssignSpeaker' => $canAssignSpeaker,
    'canRunDiarize' => $canRunDiarize,
    'hasAsrDiaHost' => $hasAsrDiaHost,
    'hasTranscription' => $hasTranscription,
    'diarizeOn' => $diarizeOn,
    'asrDiaRun' => $asrDiaRun,
], 'layout', 'audio');
