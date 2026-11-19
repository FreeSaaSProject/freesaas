#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild CAM++ centroids from existing manual samples (same dim as asr_dia match).
 * Use after dia-first experiment if cue-based profiles underperform on long turns.
 *
 *   php bin/asr-dia-rebuild-profiles.php
 *   php bin/asr-dia-rebuild-profiles.php --admins=58,127
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

$admins = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--admins=([\d,]+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $admins[] = $id;
            }
        }
    }
}
if ($admins === []) {
    $fixture = base_path('docs/agents/asr_dia_fixture_2026-11-16.json');
    if (is_file($fixture)) {
        $data = json_decode((string)file_get_contents($fixture), true);
        foreach (is_array($data['staff'] ?? null) ? $data['staff'] : [] as $s) {
            $id = (int)($s['id_admin'] ?? 0);
            if ($id > 0) {
                $admins[] = $id;
            }
        }
    }
}
$admins = array_values(array_unique($admins));
$out = [];
foreach ($admins as $adminId) {
    $counts = SpeakerSamplesRepository::countsForAdmin($adminId);
    if ($counts['manual'] < 1) {
        $out[] = ['admin' => $adminId, 'ok' => false, 'error' => 'no_manual_samples'];
        continue;
    }
    $res = SpeakerService::buildProfileFromManualSamples($adminId);
    $out[] = [
        'admin' => $adminId,
        'ok' => !empty($res['ok']),
        'manual' => $counts['manual'],
        'message' => (string)($res['message'] ?? $res['diag'] ?? ''),
    ];
}
echo json_encode(['rebuilt' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
