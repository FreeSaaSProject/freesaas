<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
require_once base_path('modules/audio_diarize/lib/SpeakerProfilesRepository.php');
require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

$flash = '';
$canEdit = Auth::can('audio_diarize.edit');
$recommended = SpeakerSamplesRepository::RECOMMENDED_MANUAL;
$recentDays = SpeakerSamplesRepository::CATALOG_RECENT_DAYS;

BulkActions::tryHandle('audio_voice_profiles', '/audio/voice-profiles', []);
$bulkFlash = BulkActions::consumeFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && !isset($_POST['bulk_action'])) {
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if ($action === 'delete_profile' && $adminId > 0) {
        if (SpeakerProfilesRepository::deleteByAdmin($adminId)) {
            $flash = t('audio_diarize.voice_profiles.deleted');
        }
    } elseif ($action === 'clear_samples' && $adminId > 0) {
        $n = SpeakerSamplesRepository::deleteAllForAdmin($adminId);
        $flash = t('audio_diarize.voice_profiles.samples_cleared', ['n' => (string)$n]);
    }
}

$rows = SpeakerSamplesRepository::catalogStaffWithAudio($recentDays);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'recommended_manual' => $recommended,
        'recent_days' => $recentDays,
        'profiles_ready' => SpeakerService::hasReadyProfiles(),
        'items' => $rows,
        'can_edit' => $canEdit,
    ]);
    return;
}

View::render('voice_profiles', [
    'title' => t('audio_diarize.menu.voice_profiles'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio_diarize.menu.voice_profiles'), 'current' => true],
    ]),
    'rows' => $rows,
    'flash' => $flash,
    'bulkFlash' => $bulkFlash,
    'canEdit' => $canEdit,
    'recommended' => $recommended,
    'recentDays' => $recentDays,
    'profilesReady' => SpeakerService::hasReadyProfiles(),
], 'layout', 'audio_diarize');
