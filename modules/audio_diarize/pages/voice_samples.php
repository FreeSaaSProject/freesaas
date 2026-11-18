<?php
declare(strict_types=1);

require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
require_once base_path('modules/audio_diarize/lib/SpeakerProfilesRepository.php');
require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

$adminId = (int)(Router::param('id') ?? ($_GET['admin'] ?? 0));
if ($adminId < 1) {
    http_response_code(404);
    View::render('error', ['title' => t('audio_diarize.voice_profiles.samples_not_found'), 'message' => ''], 'layout', 'audio_diarize');
    return;
}

$canEdit = Auth::can('audio_diarize.edit');
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_sample') {
        $sid = (int)($_POST['sample_id'] ?? 0);
        if ($sid > 0 && SpeakerSamplesRepository::delete($sid, $adminId)) {
            $flash = t('audio_diarize.voice_profiles.sample_deleted');
        }
    }
}

$counts = SpeakerSamplesRepository::countsForAdmin($adminId);
$samples = SpeakerSamplesRepository::listForAdmin($adminId, 'manual');
$recommended = SpeakerSamplesRepository::RECOMMENDED_MANUAL;
$remain = max(0, $recommended - $counts['manual']);
$dayHref = '/audio?' . http_build_query([
    'admin' => $adminId,
    'year' => date('Y'),
    'month' => date('n'),
    'day' => date('j'),
]);

View::render('voice_samples', [
    'title' => t('audio_diarize.voice_profiles.samples_title', [
        'name' => UsersRepository::nameById($adminId),
    ]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio_diarize.menu.voice_profiles'), 'href' => '/audio/voice-profiles'],
        ['label' => UsersRepository::shortName(UsersRepository::nameById($adminId)), 'current' => true],
    ]),
    'adminId' => $adminId,
    'samples' => $samples,
    'counts' => $counts,
    'recommended' => $recommended,
    'remain' => $remain,
    'dayHref' => $dayHref,
    'flash' => $flash,
    'canEdit' => $canEdit,
], 'layout', 'audio_diarize');
