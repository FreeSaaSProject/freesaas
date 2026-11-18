<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        DiarizeSettings::save($_POST, []);
        $message = t('audio_diarize.settings.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'requires_profiles' => DiarizeSettings::requiresProfiles() ? 1 : 0,
                'scripts_ignore_other_speakers' => DiarizeSettings::scriptsIgnoreOtherSpeakers() ? 1 : 0,
                'match_cue' => DiarizeSettings::matchCue(),
                'match_margin' => DiarizeSettings::matchMargin(),
                'profiles_ready' => SpeakerService::hasReadyProfiles() ? 1 : 0,
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$requiresProfiles = DiarizeSettings::requiresProfiles();
$scriptsIgnoreOther = DiarizeSettings::scriptsIgnoreOtherSpeakers();
$matchCue = DiarizeSettings::matchCue();
$matchMargin = DiarizeSettings::matchMargin();
$profilesReady = SpeakerService::hasReadyProfiles();
$tablesReady = SpeakerService::tablesReady();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'requires_profiles' => $requiresProfiles ? 1 : 0,
        'scripts_ignore_other_speakers' => $scriptsIgnoreOther ? 1 : 0,
        'match_cue' => $matchCue,
        'match_margin' => $matchMargin,
        'profiles_ready' => $profilesReady ? 1 : 0,
        'tables_ready' => $tablesReady ? 1 : 0,
    ]);
    return;
}

View::render('settings', [
    'title' => t('audio_diarize.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio_diarize.settings.title'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'requiresProfiles' => $requiresProfiles,
    'scriptsIgnoreOther' => $scriptsIgnoreOther,
    'matchCue' => $matchCue,
    'matchMargin' => $matchMargin,
    'profilesReady' => $profilesReady,
    'tablesReady' => $tablesReady,
], 'layout', 'audio_diarize');
