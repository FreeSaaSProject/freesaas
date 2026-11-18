<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

AsrWorkerAuth::assertToken();

header('Content-Type: text/plain; charset=utf-8');

$sampleId = (int)($_POST['sample_id'] ?? $_POST['id'] ?? 0);
$embRaw = $_POST['embedding'] ?? '';
$embedding = [];
if (is_string($embRaw) && $embRaw !== '') {
    $decoded = json_decode($embRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $v) {
            $embedding[] = (float)$v;
        }
    }
} elseif (is_array($embRaw)) {
    foreach ($embRaw as $v) {
        $embedding[] = (float)$v;
    }
}

if ($sampleId < 1 || count($embedding) < 32) {
    echo 'false';
    return;
}

if (!SpeakerSamplesRepository::saveEmbedding($sampleId, $embedding)) {
    echo 'false';
    return;
}

$st = Database::pdo()->prepare(
    'SELECT ID_ADMIN FROM base_records_speaker_samples WHERE ID = ? LIMIT 1'
);
$st->execute([$sampleId]);
$adminId = (int)($st->fetchColumn() ?: 0);
$autoBuild = (int)($_POST['build_profile'] ?? 0) === 1;
$diag = 'EMBED=' . $sampleId;
if ($autoBuild && $adminId > 0) {
    $pending = SpeakerSamplesRepository::countsForAdmin($adminId)['pending_manual'];
    if ($pending < 1) {
        $res = SpeakerService::buildProfileFromManualSamples($adminId);
        $diag .= ' ' . $res['diag'];
    }
}
echo "true\n" . $diag;
