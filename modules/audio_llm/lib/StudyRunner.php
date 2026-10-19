<?php
declare(strict_types=1);

/** Queue processor for LLM study results. */
final class StudyRunner
{
    public static function isMeaningfulResult(string $text): bool
    {
        return StudiesRepository::isMeaningfulResult($text);
    }

    public static function isCompleted(?array $study): bool
    {
        if (!is_array($study)) {
            return false;
        }
        $total = (int)($study['TOTAL_RECORDS'] ?? 0);
        $done = (int)($study['DONE_RECORDS'] ?? 0);
        return $total > 0 && $done >= $total && (int)($study['STATUS'] ?? 0) === 2;
    }

    /** @return array{done:int,total:int,pending:int,percent:float,complete:bool,status:int}|null */
    public static function progress(int $studyId): ?array
    {
        $study = StudiesRepository::find($studyId);
        if (!$study) {
            return null;
        }
        $total = (int)$study['TOTAL_RECORDS'];
        $done = (int)$study['DONE_RECORDS'];
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_records_llm_study_results WHERE ID_STUDY = ? AND STATUS = 0'
        );
        $st->execute([$studyId]);
        $pending = (int)$st->fetchColumn();
        $percent = $total > 0 ? round($done * 100 / $total) : 0;
        return [
            'done' => $done,
            'total' => $total,
            'pending' => $pending,
            'percent' => $percent,
            'complete' => ($total > 0 && $pending === 0),
            'status' => (int)$study['STATUS'],
        ];
    }

    /**
     * @return array{processed:int,study_id:int,remaining:int,last_record_id:int,last_result:string}
     */
    public static function processNext(int $studyId = 0, int $limit = 5): array
    {
        $limit = max(1, min(100, $limit));
        $out = ['processed' => 0, 'study_id' => $studyId, 'remaining' => 0, 'last_record_id' => 0, 'last_result' => ''];
        if (!StudiesRepository::tablesReady()) {
            return $out;
        }
        $pdo = Database::pdo();
        if ($studyId > 0) {
            $st = $pdo->prepare(
                'SELECT r.*, s.PROMPT, s.ID_CYCLIC_PARENT
                 FROM base_records_llm_study_results r
                 INNER JOIN base_records_llm_studies s ON s.ID = r.ID_STUDY
                 WHERE r.ID_STUDY = ? AND r.STATUS = 0
                 ORDER BY r.ID ASC LIMIT ' . $limit
            );
            $st->execute([$studyId]);
        } else {
            $st = $pdo->query(
                'SELECT r.*, s.PROMPT, s.ID_CYCLIC_PARENT
                 FROM base_records_llm_study_results r
                 INNER JOIN base_records_llm_studies s ON s.ID = r.ID_STUDY
                 WHERE r.STATUS = 0 AND s.IS_CYCLIC = 0
                 ORDER BY r.ID ASC LIMIT ' . $limit
            );
        }
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $usedStudy = $studyId;
        foreach ($rows as $row) {
            $usedStudy = (int)$row['ID_STUDY'];
            $prompt = (string)$row['PROMPT'];
            if ((int)$row['ID_CYCLIC_PARENT'] > 0 && strpos($prompt, '"found"') === false) {
                $prompt .= CyclicHelpers::promptSuffix();
            }
            if (self::processOneResult($row, $prompt)) {
                $out['processed']++;
                $out['last_record_id'] = (int)$row['ID_RECORD'];
                $fresh = $pdo->prepare('SELECT RESULT_TEXT FROM base_records_llm_study_results WHERE ID = ?');
                $fresh->execute([(int)$row['ID']]);
                $out['last_result'] = (string)$fresh->fetchColumn();
            }
        }
        $out['study_id'] = $usedStudy;
        if ($usedStudy > 0) {
            $p = self::progress($usedStudy);
            $out['remaining'] = $p ? (int)$p['pending'] : 0;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $resultRow
     * @param list<int>|null $claimStatuses
     */
    public static function processOneResult(array $resultRow, string $prompt, ?array $claimStatuses = null): bool
    {
        $resultId = (int)$resultRow['ID'];
        $studyId = (int)$resultRow['ID_STUDY'];
        $recordId = (int)$resultRow['ID_RECORD'];
        $now = time();
        if ($claimStatuses === null) {
            $claimStatuses = [0];
        }
        $claimStatuses = array_values(array_filter(array_map('intval', $claimStatuses), static function ($s) {
            return $s >= 0;
        }));
        if ($claimStatuses === []) {
            $claimStatuses = [0];
        }
        $in = implode(',', $claimStatuses);
        $clear = (in_array(3, $claimStatuses, true) || in_array(2, $claimStatuses, true))
            ? ", ERROR_TEXT = '', RESULT_TEXT = '', DT_PROCESSED = 0"
            : '';
        $pdo = Database::pdo();
        $upd = $pdo->prepare(
            "UPDATE base_records_llm_study_results SET STATUS = 1{$clear} WHERE ID = ? AND STATUS IN ({$in})"
        );
        $upd->execute([$resultId]);
        if ($upd->rowCount() < 1) {
            return false;
        }
        $pdo->prepare(
            'UPDATE base_records_llm_studies SET STATUS = 1, DT_UPDATE = ?, DT_FINISHED = 0 WHERE ID = ?'
        )->execute([$now, $studyId]);

        $payload = StudyPayload::forRecord($recordId);
        if ($payload === null) {
            $pdo->prepare(
                'UPDATE base_records_llm_study_results SET STATUS = 4, ERROR_TEXT = ?, DT_PROCESSED = ? WHERE ID = ?'
            )->execute(['Нет транскрипции или только мусор', $now, $resultId]);
            StudiesRepository::refreshCounters($studyId);
            return true;
        }

        $prevId = self::previousRecordId($studyId, $recordId);
        $context = $prevId > 0 ? StudyPayload::forRecord($prevId) : null;
        $llm = StudyLlm::callForRecord($payload, $prompt, $context);
        if (!$llm['ok']) {
            $pdo->prepare(
                'UPDATE base_records_llm_study_results SET STATUS = 3, ERROR_TEXT = ?, DT_PROCESSED = ? WHERE ID = ?'
            )->execute([t('audio_llm.err.llm') . ': ' . $llm['error'], $now, $resultId]);
            StudiesRepository::refreshCounters($studyId);
            return true;
        }
        $pdo->prepare(
            'UPDATE base_records_llm_study_results SET STATUS = 2, RESULT_TEXT = ?, ERROR_TEXT = \'\', DT_PROCESSED = ? WHERE ID = ?'
        )->execute([$llm['result'], $now, $resultId]);
        StudiesRepository::refreshCounters($studyId);
        EventBus::emit('audio_llm.study.result', [
            'study_id' => $studyId,
            'result_id' => $resultId,
            'record_id' => $recordId,
            'result_text' => (string)$llm['result'],
        ]);
        return true;
    }

    /**
     * @return array{ok:bool,processed:int,error:string}
     */
    public static function processUntilDone(int $studyId, int $maxBatches = 200): array
    {
        $maxBatches = max(1, min(500, $maxBatches));
        $processed = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $progress = self::progress($studyId);
            if (!$progress) {
                return ['ok' => false, 'processed' => $processed, 'error' => t('audio_llm.err.not_found')];
            }
            if (!empty($progress['complete']) || (int)$progress['pending'] <= 0) {
                return ['ok' => true, 'processed' => $processed, 'error' => ''];
            }
            $res = self::processNext($studyId, 1);
            $processed += (int)$res['processed'];
            if ((int)$res['processed'] <= 0) {
                break;
            }
        }
        $progress = self::progress($studyId);
        $ok = $progress && !empty($progress['complete']);
        return [
            'ok' => (bool)$ok,
            'processed' => $processed,
            'error' => $ok ? '' : 'Incomplete',
        ];
    }

    /**
     * @return array{ok:bool,error:string,record_id:int}
     */
    public static function retryResult(int $studyId, int $recordId): array
    {
        if ($studyId < 1 || $recordId < 1) {
            return ['ok' => false, 'error' => 'Bad params', 'record_id' => $recordId];
        }
        $st = Database::pdo()->prepare(
            'SELECT r.*, s.PROMPT, s.ID_CYCLIC_PARENT
             FROM base_records_llm_study_results r
             INNER JOIN base_records_llm_studies s ON s.ID = r.ID_STUDY
             WHERE r.ID_STUDY = ? AND r.ID_RECORD = ? LIMIT 1'
        );
        $st->execute([$studyId, $recordId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found', 'record_id' => $recordId];
        }
        $status = (int)$row['STATUS'];
        $resultText = trim((string)$row['RESULT_TEXT']);
        $canRetry = ($status === 3) || ($status === 2 && $resultText === '');
        if (!$canRetry) {
            return ['ok' => false, 'error' => 'Retry not allowed', 'record_id' => $recordId];
        }
        Database::pdo()->prepare(
            'UPDATE base_records_llm_studies SET LLM_SUMMARY=\'\', DT_SUMMARY=0, DT_FINISHED=0, STATUS=1, DT_UPDATE=? WHERE ID=?'
        )->execute([time(), $studyId]);
        $prompt = (string)$row['PROMPT'];
        if ((int)$row['ID_CYCLIC_PARENT'] > 0 && strpos($prompt, '"found"') === false) {
            $prompt .= CyclicHelpers::promptSuffix();
        }
        $claim = ($status === 3) ? [3] : [2];
        if (!self::processOneResult($row, $prompt, $claim)) {
            return ['ok' => false, 'error' => 'Claim failed', 'record_id' => $recordId];
        }
        return ['ok' => true, 'error' => '', 'record_id' => $recordId];
    }

    /**
     * @return array{ok:bool,error:string,reset:int}
     */
    public static function reset(int $studyId): array
    {
        $study = StudiesRepository::find($studyId);
        if (!$study) {
            return ['ok' => false, 'error' => t('audio_llm.err.not_found'), 'reset' => 0];
        }
        if (!empty($study['IS_CYCLIC'])) {
            return ['ok' => false, 'error' => 'Cyclic template', 'reset' => 0];
        }
        $total = (int)$study['TOTAL_RECORDS'];
        if ($total <= 0) {
            return ['ok' => false, 'error' => t('audio_llm.err.none'), 'reset' => 0];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'UPDATE base_records_llm_study_results SET STATUS=0, RESULT_TEXT=\'\', ERROR_TEXT=\'\', DT_PROCESSED=0 WHERE ID_STUDY=?'
        );
        $st->execute([$studyId]);
        $reset = max($st->rowCount(), $total);
        $pdo->prepare(
            'UPDATE base_records_llm_studies SET STATUS=0, DONE_RECORDS=0, MEANINGFUL_COUNT=0,
                LLM_SUMMARY=\'\', DT_SUMMARY=0, DT_FINISHED=0, DT_UPDATE=? WHERE ID=?'
        )->execute([time(), $studyId]);
        StudiesRepository::refreshCounters($studyId);
        return ['ok' => true, 'error' => '', 'reset' => $reset];
    }

    public static function requeueBlankResults(int $studyId): int
    {
        if ($studyId < 1) {
            return 0;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "UPDATE base_records_llm_study_results SET STATUS=0, RESULT_TEXT='', ERROR_TEXT='', DT_PROCESSED=0
             WHERE ID_STUDY=? AND STATUS=2 AND (RESULT_TEXT='' OR RESULT_TEXT IS NULL)"
        );
        $st->execute([$studyId]);
        $n = $st->rowCount();
        if ($n > 0) {
            $pdo->prepare(
                'UPDATE base_records_llm_studies SET LLM_SUMMARY=\'\', DT_SUMMARY=0, DT_FINISHED=0, STATUS=0, DT_UPDATE=? WHERE ID=?'
            )->execute([time(), $studyId]);
            StudiesRepository::refreshCounters($studyId);
        }
        return $n;
    }

    /**
     * @return array{ok:bool,error:string,summary:string,attempted:bool}
     */
    public static function maybeGenerateSummary(int $studyId): array
    {
        $study = StudiesRepository::find($studyId);
        if (!$study || trim((string)$study['LLM_SUMMARY']) !== '') {
            return ['ok' => false, 'error' => '', 'summary' => (string)($study['LLM_SUMMARY'] ?? ''), 'attempted' => false];
        }
        if (!self::isCompleted($study)) {
            return ['ok' => false, 'error' => '', 'summary' => '', 'attempted' => false];
        }
        $built = self::buildMeaningful($studyId);
        if ($built['meaningful_items'] === []) {
            return ['ok' => false, 'error' => '', 'summary' => '', 'attempted' => false];
        }
        $gen = StudyLlm::aggregateSummary($study, $built['meaningful_items']);
        if (!$gen['ok']) {
            return ['ok' => false, 'error' => $gen['error'], 'summary' => '', 'attempted' => true];
        }
        Database::pdo()->prepare(
            'UPDATE base_records_llm_studies SET LLM_SUMMARY=?, DT_SUMMARY=?, DT_UPDATE=? WHERE ID=?'
        )->execute([$gen['summary'], time(), time(), $studyId]);
        return ['ok' => true, 'error' => '', 'summary' => $gen['summary'], 'attempted' => true];
    }

    /**
     * @return array{meaningful_count:int,meaningful_items:list<array>,total:int,done:int}
     */
    public static function buildMeaningful(int $studyId): array
    {
        $study = StudiesRepository::find($studyId);
        $items = [];
        foreach (StudiesRepository::results($studyId) as $row) {
            if ((int)$row['STATUS'] !== 2) {
                continue;
            }
            if (!self::isMeaningfulResult((string)$row['RESULT_TEXT'])) {
                continue;
            }
            $items[] = [
                'id_result' => (int)$row['ID'],
                'id_record' => (int)$row['ID_RECORD'],
                'id_admin' => (int)$row['ID_ADMIN'],
                'dt_recorded' => (int)($row['DT_RECORDED'] ?? 0),
                'result_text' => (string)$row['RESULT_TEXT'],
            ];
        }
        return [
            'meaningful_count' => count($items),
            'meaningful_items' => $items,
            'total' => (int)($study['TOTAL_RECORDS'] ?? 0),
            'done' => (int)($study['DONE_RECORDS'] ?? 0),
        ];
    }

    private static function previousRecordId(int $studyId, int $recordId): int
    {
        $st = Database::pdo()->prepare(
            'SELECT r2.ID_RECORD
             FROM base_records_llm_study_results r
             INNER JOIN base_records br ON br.ID = r.ID_RECORD
             INNER JOIN base_records_llm_study_results r2 ON r2.ID_STUDY = r.ID_STUDY
             INNER JOIN base_records br2 ON br2.ID = r2.ID_RECORD
             WHERE r.ID_STUDY = ? AND r.ID_RECORD = ?
               AND (br2.DT_RECORDED < br.DT_RECORDED OR (br2.DT_RECORDED = br.DT_RECORDED AND br2.ID < br.ID))
             ORDER BY br2.DT_RECORDED DESC, br2.ID DESC
             LIMIT 1'
        );
        $st->execute([$studyId, $recordId]);
        return (int)$st->fetchColumn();
    }
}
