<?php
declare(strict_types=1);

/**
 * GET /audio/asr-dia — experiment console (fixture 16.11 + run status).
 */
require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');

$wantsJson = AgentHttp::wantsJson();
$fixturePath = base_path('docs/agents/asr_dia_fixture_2026-11-16.json');
$fixture = [];
if (is_file($fixturePath)) {
    $decoded = json_decode((string)file_get_contents($fixturePath), true);
    if (is_array($decoded)) {
        $fixture = $decoded;
    }
}
$recordIds = array_map('intval', is_array($fixture['record_ids'] ?? null) ? $fixture['record_ids'] : []);
$runs = AsrDiaRunsRepository::available()
    ? AsrDiaRunsRepository::latestForRecords($recordIds)
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'fixture' => $fixture,
        'runs' => $runs,
        'can_edit' => Auth::can('audio_diarize.edit'),
    ]);
    return;
}

View::render('asr_dia', [
    'title' => t('audio_diarize.asr_dia.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio_diarize.asr_dia.title'), 'current' => true],
    ]),
    'fixture' => $fixture,
    'runs' => $runs,
    'canEdit' => Auth::can('audio_diarize.edit'),
], 'layout', 'audio_diarize');
