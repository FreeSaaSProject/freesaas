<?php
declare(strict_types=1);

/**
 * Journal for asr_dia experiment runs (does not touch prod whisper claim).
 */
final class AsrDiaRunsRepository
{
    public static function available(): bool
    {
        return Database::tableExists('base_asr_dia_runs');
    }

    /**
     * @param list<int> $recordIds
     * @return list<int> run ids
     */
    public static function enqueueRecords(
        array $recordIds,
        string $asrModel = '',
        bool $applySpeakers = true,
        bool $applyTranscription = false
    ): array {
        if (!self::available()) {
            return [];
        }
        $pdo = Database::pdo();
        // Reuse active journal row — cabin divert used to INSERT forever while DT_TRANSCRIBED stayed 0.
        $findActive = $pdo->prepare(
            "SELECT ID FROM base_asr_dia_runs
             WHERE ID_RECORD = ? AND STATUS IN ('pending','queued')
             ORDER BY ID DESC LIMIT 1"
        );
        $ins = $pdo->prepare(
            'INSERT INTO base_asr_dia_runs
             (ID_RECORD, JOB_ID, ENGINE, ASR_MODEL, STATUS, APPLY_SPEAKERS, APPLY_TRANSCRIPTION, DT_CREATE)
             VALUES (?, ?, \'asr_dia\', ?, \'pending\', ?, ?, ?)'
        );
        $now = time();
        $out = [];
        foreach ($recordIds as $rid) {
            $rid = (int)$rid;
            if ($rid < 1) {
                continue;
            }
            $findActive->execute([$rid]);
            $existing = (int)($findActive->fetchColumn() ?: 0);
            if ($existing > 0) {
                // Refresh flags — reuse used to keep APPLY_TRANSCRIPTION=0 from an older
                // enrollment enqueue, so cabin divert never stamped DT_TRANSCRIBED.
                $pdo->prepare(
                    'UPDATE base_asr_dia_runs
                     SET APPLY_SPEAKERS = ?, APPLY_TRANSCRIPTION = ?,
                         ASR_MODEL = IF(? <> \'\', ?, ASR_MODEL)
                     WHERE ID = ? AND STATUS IN (\'pending\',\'queued\')'
                )->execute([
                    $applySpeakers ? 1 : 0,
                    $applyTranscription ? 1 : 0,
                    $asrModel,
                    $asrModel,
                    $existing,
                ]);
                $out[] = $existing;
                continue;
            }
            $jobId = bin2hex(random_bytes(16));
            $ins->execute([
                $rid,
                $jobId,
                $asrModel,
                $applySpeakers ? 1 : 0,
                $applyTranscription ? 1 : 0,
                $now,
            ]);
            $out[] = (int)$pdo->lastInsertId();
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function listPending(int $limit = 20): array
    {
        if (!self::available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $st = Database::pdo()->query(
            "SELECT r.ID AS run_id, r.ID_RECORD AS record_id, r.JOB_ID AS job_id,
                    r.ASR_MODEL AS asr_model, r.APPLY_SPEAKERS AS apply_speakers,
                    r.APPLY_TRANSCRIPTION AS apply_transcription,
                    rec.FILENAME AS file_name,
                    rec.TRANSCRIPTION AS transcription
             FROM base_asr_dia_runs r
             INNER JOIN base_records rec ON rec.ID = r.ID_RECORD
             WHERE r.STATUS = 'pending'
             ORDER BY r.ID ASC
             LIMIT {$limit}"
        );
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $model = (string)$row['asr_model'];
            $mode = '';
            $ml = strtolower($model);
            if (in_array($ml, ['reuse', 'cabin_reuse', 'cabin'], true)) {
                $mode = 'reuse';
            } elseif (in_array($ml, ['reuse_gap', 'reuse-gap', 'cabin_gap'], true)) {
                $mode = 'reuse_gap';
            }
            $out[] = [
                'run_id' => (int)$row['run_id'],
                'record_id' => (int)$row['record_id'],
                'job_id' => (string)$row['job_id'],
                'asr_model' => $model,
                'asr_mode' => $mode,
                'apply_speakers' => !empty($row['apply_speakers']),
                'apply_transcription' => !empty($row['apply_transcription']),
                'file_name' => (string)$row['file_name'],
                'transcription' => (string)($row['transcription'] ?? ''),
            ];
        }
        return $out;
    }

    public static function markQueued(string $jobId): void
    {
        if (!self::available() || $jobId === '') {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_asr_dia_runs SET STATUS = 'queued' WHERE JOB_ID = ? AND STATUS = 'pending'"
        )->execute([$jobId]);
    }

    public static function markFailed(string $jobId, string $error): void
    {
        if (!self::available() || $jobId === '') {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_asr_dia_runs
             SET STATUS = 'failed', ERROR = ?, DT_DONE = ?
             WHERE JOB_ID = ?"
        )->execute([mb_substr($error, 0, 500), time(), $jobId]);
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function saveResult(string $jobId, array $result): bool
    {
        if (!self::available() || $jobId === '') {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_RECORD, APPLY_SPEAKERS, APPLY_TRANSCRIPTION
             FROM base_asr_dia_runs WHERE JOB_ID = ? LIMIT 1'
        );
        $st->execute([$jobId]);
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return false;
        }
        $recordId = (int)$run['ID_RECORD'];
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $cues = (int)($meta['cues'] ?? count(is_array($result['cue_speakers'] ?? null) ? $result['cue_speakers'] : []));
        $matched = (int)($meta['matched_cues'] ?? 0);
        $pct = (float)($meta['matched_pct'] ?? ($cues > 0 ? round(100.0 * $matched / $cues, 2) : 0));
        $transcription = (string)($result['transcription'] ?? '');
        $asrModel = trim((string)($meta['asr_model_id'] ?? $result['asr_model_id'] ?? ''));

        // Run flags are SoT. Worker often echoes apply_transcription=false (its job default)
        // even when the journal row has APPLY_TRANSCRIPTION=1 — never AND with result flags.
        $applySpeakers = !empty($run['APPLY_SPEAKERS']);
        $applyTr = !empty($run['APPLY_TRANSCRIPTION']);

        // Strip dictionary trash (base_records_trashes / TranscriptGarbage) before apply —
        // same rules as UI; edge worker must not hardcode watermark phrases.
        // Journal keeps raw TRANSCRIPTION_NEW; base_records gets the filtered text.
        $cueSpeakers = is_array($result['cue_speakers'] ?? null) ? $result['cue_speakers'] : [];
        $applyText = $transcription;
        if ($transcription !== '' && class_exists('TranscriptGarbage', false)) {
            $filtered = TranscriptGarbage::filterTranscription($transcription);
            $applyText = (string)($filtered['text'] ?? $transcription);
            $keptOrig = is_array($filtered['kept_orig_lines'] ?? null)
                ? $filtered['kept_orig_lines']
                : [];
            if ($keptOrig !== [] && $cueSpeakers !== []) {
                $byOld = [];
                foreach ($cueSpeakers as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $old = (int)($c['line'] ?? 0);
                    if ($old > 0) {
                        $byOld[$old] = $c;
                    }
                }
                $remapped = [];
                foreach ($keptOrig as $newIdx => $oldLine) {
                    $oldLine = (int)$oldLine;
                    if (!isset($byOld[$oldLine])) {
                        continue;
                    }
                    $row = $byOld[$oldLine];
                    $row['line'] = $newIdx + 1;
                    $remapped[] = $row;
                }
                $cueSpeakers = $remapped;
            }
            if (!empty($filtered['trash'])) {
                $meta['trash_dropped'] = (int)$filtered['trash'];
            }
        }

        Database::pdo()->prepare(
            "UPDATE base_asr_dia_runs
             SET STATUS = 'done', CUES = ?, MATCHED_CUES = ?, MATCHED_PCT = ?,
                 META_JSON = ?, TRANSCRIPTION_NEW = ?, ERROR = '', DT_DONE = ?,
                 ASR_MODEL = IF(ASR_MODEL = '' AND ? <> '', ?, ASR_MODEL)
             WHERE JOB_ID = ?"
        )->execute([
            $cues,
            $matched,
            $pct,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $transcription !== '' ? $transcription : null,
            time(),
            $asrModel,
            $asrModel,
            $jobId,
        ]);

        if ($applySpeakers && $recordId > 0 && class_exists('SpeakerService', false)) {
            $payload = [
                'engine' => 'asr_dia',
                'skip_rematch' => true,
                'speakers' => is_array($result['speakers'] ?? null) ? $result['speakers'] : [],
                'segments' => is_array($result['segments'] ?? null) ? $result['segments'] : [],
                'cue_speakers' => $cueSpeakers,
            ];
            SpeakerService::saveForRecord($recordId, $payload, ['rematch' => false]);
        }
        if ($recordId > 0 && $applyText !== '' && Database::tableExists('base_records')) {
            // Must stamp DT_TRANSCRIBED — otherwise AsrLease keeps claiming the file and
            // cabin divert re-enqueues ASR-DIA in a loop (same day files ×N).
            // APPLY_TRANSCRIPTION controls whether TRANSCRIPTION text is overwritten;
            // DT stamp still happens for untranscribed rows so the lease can clear.
            if ($applyTr) {
                Database::pdo()->prepare(
                    'UPDATE base_records
                     SET TRANSCRIPTION = ?, DT_TRANSCRIBED = ?
                     WHERE ID = ? LIMIT 1'
                )->execute([$applyText, time(), $recordId]);
            } else {
                Database::pdo()->prepare(
                    'UPDATE base_records
                     SET DT_TRANSCRIBED = ?
                     WHERE ID = ? AND (DT_TRANSCRIBED IS NULL OR DT_TRANSCRIBED < 1)
                     LIMIT 1'
                )->execute([time(), $recordId]);
            }
        }
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function latestForRecords(array $recordIds): array
    {
        if (!self::available() || $recordIds === []) {
            return [];
        }
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
        if ($recordIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($recordIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT r.*
             FROM base_asr_dia_runs r
             INNER JOIN (
               SELECT ID_RECORD, MAX(ID) AS mid FROM base_asr_dia_runs
               WHERE ID_RECORD IN ($ph) GROUP BY ID_RECORD
             ) t ON t.mid = r.ID
             ORDER BY r.ID_RECORD ASC"
        );
        $st->execute($recordIds);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $meta = [];
            if (!empty($row['META_JSON'])) {
                $decoded = json_decode((string)$row['META_JSON'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $dtCreate = (int)$row['DT_CREATE'];
            $dtDone = (int)$row['DT_DONE'];
            $out[] = [
                'id' => (int)$row['ID'],
                'record_id' => (int)$row['ID_RECORD'],
                'job_id' => (string)$row['JOB_ID'],
                'status' => (string)$row['STATUS'],
                'asr_model' => (string)$row['ASR_MODEL'],
                'cues' => (int)$row['CUES'],
                'matched_cues' => (int)$row['MATCHED_CUES'],
                'matched_pct' => (float)$row['MATCHED_PCT'],
                'error' => (string)$row['ERROR'],
                'dt_create' => $dtCreate,
                'dt_done' => $dtDone,
                'job_wall_sec' => ($dtDone > $dtCreate) ? ($dtDone - $dtCreate) : null,
                'pipeline_sec' => isset($meta['pipeline_sec']) ? (float)$meta['pipeline_sec'] : null,
                'download_sec' => isset($meta['download_sec']) ? (float)$meta['download_sec'] : null,
                'wall_sec' => isset($meta['wall_sec']) ? (float)$meta['wall_sec'] : null,
                'meta' => $meta,
                'transcription_new' => (string)($row['TRANSCRIPTION_NEW'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array{pending:int,queued:int,done:int,failed:int} */
    public static function countsForRunIds(array $runIds): array
    {
        $empty = ['pending' => 0, 'queued' => 0, 'done' => 0, 'failed' => 0];
        if (!self::available() || $runIds === []) {
            return $empty;
        }
        $runIds = array_values(array_unique(array_filter(array_map('intval', $runIds))));
        if ($runIds === []) {
            return $empty;
        }
        $ph = implode(',', array_fill(0, count($runIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT STATUS, COUNT(*) AS n FROM base_asr_dia_runs WHERE ID IN ($ph) GROUP BY STATUS"
        );
        $st->execute($runIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $s = (string)$row['STATUS'];
            if (isset($empty[$s])) {
                $empty[$s] = (int)$row['n'];
            }
        }
        return $empty;
    }
}
