<?php
declare(strict_types=1);

/**
 * Fill oral poll invites from audio key instances in a day window.
 * Trigger: audio_reports.day.analyzed or manual /poll-speech/fill-day.
 */
final class PollSpeechFiller
{
    /**
     * @param array{date?:string,day_start?:int,day_end?:int,admin_ids?:list<int>,log?:callable|null} $ctx
     * @return array{ok:bool,filled:int,skipped:int,errors:list<string>,date:string}
     */
    public static function fillDay(array $ctx): array
    {
        $log = is_callable($ctx['log'] ?? null) ? $ctx['log'] : null;
        $date = trim((string)($ctx['date'] ?? ''));
        $dayStart = (int)($ctx['day_start'] ?? 0);
        $dayEnd = (int)($ctx['day_end'] ?? 0);
        if ($dayStart < 1 || $dayEnd < $dayStart) {
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dayStart = (int)strtotime($date . ' 00:00:00');
                $dayEnd = (int)strtotime($date . ' 23:59:59');
            }
        }
        if ($dayStart < 1 || $dayEnd < $dayStart) {
            return ['ok' => false, 'filled' => 0, 'skipped' => 0, 'errors' => [t('poll_speech.err.day')], 'date' => $date];
        }
        if ($date === '') {
            $date = date('Y-m-d', $dayStart);
        }

        $dictId = PollSpeechConfig::dictId();
        $keyScores = PollSpeechConfig::keyScoreMap();
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $ansStaff = PollSpeechConfig::answersStaff();
        $ansService = PollSpeechConfig::answersService();
        $window = PollSpeechConfig::windowSec();
        $adminField = PollSpeechConfig::adminField();

        if ($dictId < 1 || $keyScores === []) {
            self::say($log, 'poll_speech: skip (no dict/key_score_map)');
            return ['ok' => true, 'filled' => 0, 'skipped' => 0, 'errors' => [], 'date' => $date];
        }
        if ($qidStaff < 1 && $qidService < 1) {
            self::say($log, 'poll_speech: skip (no rating qids)');
            return ['ok' => true, 'filled' => 0, 'skipped' => 0, 'errors' => [], 'date' => $date];
        }
        if (!Database::tableExists('base_poll_invites')
            || !Database::tableExists('base_records_instances')
            || !Database::tableExists('base_procedures')
        ) {
            return [
                'ok' => false,
                'filled' => 0,
                'skipped' => 0,
                'errors' => [t('poll_speech.err.tables')],
                'date' => $date,
            ];
        }

        $invites = PollSpeechRepository::openOralInvitesForDay($dayStart, $dayEnd);
        $filled = 0;
        $skipped = 0;
        $errors = [];
        /** @var array<string,true> $usedEvidence */
        $usedEvidence = [];

        foreach ($invites as $invite) {
            $inviteId = (int)$invite['id'];
            $procId = (int)$invite['id_proc'];
            if ($procId < 1) {
                $skipped++;
                continue;
            }
            if (self::inviteAlreadyAnswered($invite)) {
                $skipped++;
                continue;
            }

            $proc = class_exists('ProceduresRepository', false)
                ? ProceduresRepository::find($procId)
                : null;
            if ($proc === null) {
                $skipped++;
                continue;
            }

            // Center on oral mark time (invite create) — when admin asked for feedback.
            $created = (int)($invite['dt_create'] ?? 0);
            $dtStart = (int)($proc['dtstart'] ?? 0);
            $dtEnd = (int)($proc['dtend'] ?? 0);
            if ($dtEnd < 1) {
                $dtEnd = $dtStart > 0 ? $dtStart : time();
            }
            $center = self::oralAskCenter($created, $dtEnd);
            $winFrom = max(0, $center - $window);
            $winTo = $center + $window;

            $adminIds = self::resolveAdminIds($invite, $proc, $adminField);
            if ($adminIds === []) {
                $skipped++;
                continue;
            }

            $resolved = self::resolveScoreAndSnippet(
                $adminIds,
                $winFrom,
                $winTo,
                $dictId,
                $keyScores,
                $center,
                $usedEvidence
            );
            $score = (int)($resolved['score'] ?? 0);
            $via = (string)($resolved['via'] ?? '');
            $snippet = trim((string)($resolved['snippet'] ?? ''));
            if ($score < 1 || $score > 10) {
                // No score in speech: drop empty oral invite (do not keep unanswered poll).
                $records = PollSpeechRepository::recordsInWindow($adminIds, $winFrom, $winTo);
                if ($records !== []) {
                    PollInvitesRepository::deleteByIds([$inviteId]);
                    self::say($log, "poll_speech: deleted empty invite #{$inviteId} (no score in speech)");
                }
                $skipped++;
                continue;
            }

            $built = self::buildAnswers($score, $snippet, $qidStaff, $qidService, $ansStaff, $ansService);
            if ($built['data_opts'] === []) {
                $skipped++;
                continue;
            }

            try {
                PollInvitesRepository::saveAnswersFromChannel(
                    $inviteId,
                    $built['data_opts'],
                    $built['data_text'],
                    true,
                    'speech',
                    '[SPEECH] score=' . $score . ' ' . $via
                        . ($snippet !== '' ? ' snippet=' . mb_substr($snippet, 0, 120) : '')
                );
                $claim = (string)($resolved['claim'] ?? '');
                if ($claim !== '') {
                    $usedEvidence[$claim] = true;
                }
                $filled++;
                self::say($log, "poll_speech: filled invite #{$inviteId} score={$score}");
            } catch (Throwable $e) {
                $errors[] = '#' . $inviteId . ': ' . $e->getMessage();
                self::say($log, 'poll_speech: error invite #' . $inviteId . ' ' . $e->getMessage());
            }
        }

        return [
            'ok' => $errors === [],
            'filled' => $filled,
            'skipped' => $skipped,
            'errors' => $errors,
            'date' => $date,
        ];
    }

    /**
     * Wipe oral invites and recreate only when a score is found in speech.
     * No speech answer → no invite.
     *
     * @param array{log?:callable|null} $ctx
     * @return array{ok:bool,deleted:int,created:int,filled:int,skipped:int,errors:list<string>}
     */
    public static function recreateAllFromSpeech(array $ctx = []): array
    {
        $log = is_callable($ctx['log'] ?? null) ? $ctx['log'] : null;
        $dictId = PollSpeechConfig::dictId();
        $keyScores = PollSpeechConfig::keyScoreMap();
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $ansStaff = PollSpeechConfig::answersStaff();
        $ansService = PollSpeechConfig::answersService();
        $window = PollSpeechConfig::windowSec();
        $adminField = PollSpeechConfig::adminField();
        $pollId = PollSpeechConfig::oralPollId();
        if ($pollId < 1) {
            $pollId = class_exists('PollInvitesRepository', false)
                ? PollInvitesRepository::resolveOralPollId(null)
                : 0;
        }

        $out = [
            'ok' => false,
            'deleted' => 0,
            'created' => 0,
            'filled' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        if ($pollId < 1 || $dictId < 1 || ($qidStaff < 1 && $qidService < 1)) {
            $out['errors'][] = t('poll_speech.err.tables');
            return $out;
        }
        if (!Database::tableExists('base_poll_invites') || !class_exists('PollInvitesRepository', false)) {
            $out['errors'][] = t('poll_speech.err.tables');
            return $out;
        }

        $marks = PollSpeechRepository::listAllOralMarks();
        if ($marks === []) {
            $out['ok'] = true;
            return $out;
        }

        // One candidate per procedure (keep earliest oral mark timing).
        /** @var array<int,array<string,mixed>> $byProc */
        $byProc = [];
        foreach ($marks as $row) {
            $procId = (int)($row['id_proc'] ?? 0);
            if ($procId < 1) {
                continue;
            }
            if (!isset($byProc[$procId])) {
                $byProc[$procId] = $row;
            }
        }

        $ids = [];
        foreach ($marks as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $out['deleted'] = PollInvitesRepository::deleteByIds($ids);
        self::say($log, 'poll_speech: deleted oral invites=' . $out['deleted']);

        // Earlier oral marks claim speech evidence first (fairer when windows overlap).
        uasort($byProc, static function (array $a, array $b): int {
            return ((int)($a['dt_create'] ?? 0)) <=> ((int)($b['dt_create'] ?? 0));
        });

        /** @var array<string,true> $usedEvidence */
        $usedEvidence = [];
        foreach ($byProc as $procId => $snap) {
            $proc = class_exists('ProceduresRepository', false)
                ? ProceduresRepository::find($procId)
                : null;
            if ($proc === null) {
                $out['skipped']++;
                continue;
            }
            $created = (int)($snap['dt_create'] ?? 0);
            $dtEnd = (int)($proc['dtend'] ?? 0);
            if ($dtEnd < 1) {
                $dtEnd = (int)($proc['dtstart'] ?? 0);
            }
            // Oral ask is near procedure end. If invite DT_CREATE was rewritten by a
            // bulk recreate (far from the procedure day), fall back to DTEND.
            $center = self::oralAskCenter($created, $dtEnd);
            $adminIds = self::resolveAdminIds($snap, $proc, $adminField);
            if ($adminIds === []) {
                $out['skipped']++;
                continue;
            }
            $resolved = self::resolveScoreAndSnippet(
                $adminIds,
                max(0, $center - $window),
                $center + $window,
                $dictId,
                $keyScores,
                $center,
                $usedEvidence
            );
            $score = (int)($resolved['score'] ?? 0);
            $snippet = trim((string)($resolved['snippet'] ?? ''));
            $via = (string)($resolved['via'] ?? '');
            if ($score < 1 || $score > 10) {
                $out['skipped']++;
                self::say($log, "poll_speech: skip proc #{$procId} (no score)");
                continue;
            }
            $built = self::buildAnswers($score, $snippet, $qidStaff, $qidService, $ansStaff, $ansService);
            if ($built['data_opts'] === []) {
                $out['skipped']++;
                continue;
            }

            try {
                $inviteId = self::createOralInviteFromSnapshot($snap, $proc, $pollId);
                if ($inviteId < 1) {
                    $out['skipped']++;
                    continue;
                }
                $out['created']++;
                PollInvitesRepository::saveAnswersFromChannel(
                    $inviteId,
                    $built['data_opts'],
                    $built['data_text'],
                    true,
                    'speech',
                    '[SPEECH] recreate score=' . $score . ' ' . $via
                        . ($snippet !== '' ? ' snippet=' . mb_substr($snippet, 0, 120) : '')
                );
                $claim = (string)($resolved['claim'] ?? '');
                if ($claim !== '') {
                    $usedEvidence[$claim] = true;
                }
                $out['filled']++;
                self::say($log, "poll_speech: recreate invite #{$inviteId} proc={$procId} score={$score}");
            } catch (Throwable $e) {
                $out['errors'][] = 'proc#' . $procId . ': ' . $e->getMessage();
            }
        }

        $out['ok'] = $out['errors'] === [];
        return $out;
    }

    /**
     * Create+fill oral invites for paid procedures in a period when speech has a unique score.
     * Skips procedures that already have an invite. Seeds used cabin records from existing invites.
     *
     * @param array{from_ts?:int,to_ts?:int,from?:string,to?:string,log?:callable|null} $ctx
     * @return array{ok:bool,created:int,filled:int,skipped:int,errors:list<string>}
     */
    public static function fillRangeFromSpeech(array $ctx = []): array
    {
        $log = is_callable($ctx['log'] ?? null) ? $ctx['log'] : null;
        $from = (int)($ctx['from_ts'] ?? 0);
        $to = (int)($ctx['to_ts'] ?? 0);
        if ($from < 1) {
            $fromRaw = trim((string)($ctx['from'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
                $from = (int)strtotime($fromRaw . ' 00:00:00');
            }
        }
        if ($to < 1) {
            $toRaw = trim((string)($ctx['to'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
                $to = (int)strtotime($toRaw . ' 23:59:59');
            }
        }
        $out = ['ok' => false, 'created' => 0, 'filled' => 0, 'skipped' => 0, 'errors' => []];
        if ($from < 1 || $to < $from) {
            $out['errors'][] = t('poll_speech.err.day');
            return $out;
        }

        $dictId = PollSpeechConfig::dictId();
        $keyScores = PollSpeechConfig::keyScoreMap();
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $ansStaff = PollSpeechConfig::answersStaff();
        $ansService = PollSpeechConfig::answersService();
        $window = PollSpeechConfig::windowSec();
        $adminField = PollSpeechConfig::adminField();
        $pollId = PollSpeechConfig::oralPollId();
        if ($pollId < 1 && class_exists('PollInvitesRepository', false)) {
            $pollId = PollInvitesRepository::resolveOralPollId(null);
        }
        if ($pollId < 1 || $dictId < 1 || ($qidStaff < 1 && $qidService < 1)
            || !Database::tableExists('base_procedures')
            || !class_exists('PollInvitesRepository', false)
        ) {
            $out['errors'][] = t('poll_speech.err.tables');
            return $out;
        }

        $pdo = Database::pdo();
        $takenProc = [];
        if (Database::tableExists('base_poll_invites')) {
            $st = $pdo->prepare(
                'SELECT ID_PROC AS id_proc, LOG AS log FROM base_poll_invites
                 WHERE ID_PROC > 0 AND ID_POLL = ?'
            );
            $st->execute([$pollId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pid = (int)($row['id_proc'] ?? 0);
                if ($pid > 0) {
                    $takenProc[$pid] = true;
                }
            }
        }

        /** @var array<string,true> $usedEvidence */
        $usedEvidence = self::usedEvidenceFromExistingInvites($pollId);

        $st = $pdo->prepare(
            'SELECT ID AS id FROM base_procedures
             WHERE ENABLED = 1 AND DTSTART >= ? AND DTSTART <= ? AND SUMM > 0 AND ID_USER > 0
             ORDER BY DTEND ASC, ID ASC'
        );
        $st->execute([$from, $to]);
        $procIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach ($procIds as $procId) {
            if ($procId < 1 || isset($takenProc[$procId])) {
                $out['skipped']++;
                continue;
            }
            $proc = class_exists('ProceduresRepository', false)
                ? ProceduresRepository::find($procId)
                : null;
            if ($proc === null) {
                $out['skipped']++;
                continue;
            }
            $dtEnd = (int)($proc['dtend'] ?? 0);
            if ($dtEnd < 1) {
                $dtEnd = (int)($proc['dtstart'] ?? 0);
            }
            $center = self::oralAskCenter(0, $dtEnd);
            if ($center < 1) {
                $out['skipped']++;
                continue;
            }
            $snap = [
                'id_proc' => $procId,
                'id_user' => (int)($proc['id_user'] ?? 0),
                'dt_create' => $center,
                'id_operator_linked' => (int)($proc['id_admin'] ?? 0),
            ];
            $adminIds = self::resolveAdminIds($snap, $proc, $adminField);
            if ($adminIds === []) {
                $out['skipped']++;
                continue;
            }
            $resolved = self::resolveScoreAndSnippet(
                $adminIds,
                max(0, $center - $window),
                $center + $window,
                $dictId,
                $keyScores,
                $center,
                $usedEvidence
            );
            $score = (int)($resolved['score'] ?? 0);
            $snippet = trim((string)($resolved['snippet'] ?? ''));
            $via = (string)($resolved['via'] ?? '');
            if ($score < 1 || $score > 10) {
                $out['skipped']++;
                continue;
            }
            $built = self::buildAnswers($score, $snippet, $qidStaff, $qidService, $ansStaff, $ansService);
            if ($built['data_opts'] === []) {
                $out['skipped']++;
                continue;
            }
            try {
                $inviteId = self::createOralInviteFromSnapshot($snap, $proc, $pollId);
                if ($inviteId < 1) {
                    $out['skipped']++;
                    continue;
                }
                $out['created']++;
                PollInvitesRepository::saveAnswersFromChannel(
                    $inviteId,
                    $built['data_opts'],
                    $built['data_text'],
                    true,
                    'speech',
                    '[SPEECH] range score=' . $score . ' ' . $via
                        . ($snippet !== '' ? ' snippet=' . mb_substr($snippet, 0, 120) : '')
                );
                $claim = (string)($resolved['claim'] ?? '');
                if ($claim !== '') {
                    $usedEvidence[$claim] = true;
                }
                $takenProc[$procId] = true;
                $out['filled']++;
                self::say($log, "poll_speech: range invite #{$inviteId} proc={$procId} score={$score}");
            } catch (Throwable $e) {
                $out['errors'][] = 'proc#' . $procId . ': ' . $e->getMessage();
            }
        }

        $out['ok'] = $out['errors'] === [];
        return $out;
    }

    /**
     * Drop duplicate speech invites that share the same cabin record (keep earliest ask).
     *
     * @return array{ok:bool,deleted:int,kept:int}
     */
    public static function dedupeSharedSpeechRecords(): array
    {
        if (!Database::tableExists('base_poll_invites')) {
            return ['ok' => false, 'deleted' => 0, 'kept' => 0];
        }
        $pollId = PollSpeechConfig::oralPollId();
        if ($pollId > 0) {
            $st = Database::pdo()->prepare(
                "SELECT ID AS id, DT_CREATE AS dt_create, LOG AS log
                 FROM base_poll_invites
                 WHERE ID_POLL = ? AND LOG LIKE '%record=%'
                 ORDER BY DT_CREATE ASC, ID ASC"
            );
            $st->execute([$pollId]);
        } else {
            $st = Database::pdo()->query(
                "SELECT ID AS id, DT_CREATE AS dt_create, LOG AS log
                 FROM base_poll_invites
                 WHERE LOG LIKE '%[SPEECH]%' AND LOG LIKE '%record=%'
                 ORDER BY DT_CREATE ASC, ID ASC"
            );
        }
        $seen = [];
        $delete = [];
        $kept = 0;
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $row) {
            $ids = PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? ''));
            if ($ids === []) {
                continue;
            }
            $rid = (int)$ids[0];
            if (isset($seen[$rid])) {
                $delete[] = (int)$row['id'];
                continue;
            }
            $seen[$rid] = (int)$row['id'];
            $kept++;
        }
        $deleted = $delete !== [] ? PollInvitesRepository::deleteByIds($delete) : 0;
        return ['ok' => true, 'deleted' => $deleted, 'kept' => $kept];
    }

    /** @return array<string,true> */
    private static function usedEvidenceFromExistingInvites(int $pollId): array
    {
        $used = [];
        if (!Database::tableExists('base_poll_invites')) {
            return $used;
        }
        $sql = "SELECT LOG AS log FROM base_poll_invites WHERE LOG LIKE '%record=%'";
        $params = [];
        if ($pollId > 0) {
            $sql .= ' AND ID_POLL = ?';
            $params[] = $pollId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            foreach (PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? '')) as $rid) {
                $used['r' . $rid] = true;
            }
        }
        return $used;
    }

    /**
     * @param array<string,mixed> $snap
     * @param array<string,mixed> $proc
     */
    private static function createOralInviteFromSnapshot(array $snap, array $proc, int $pollId): int
    {
        $procId = (int)($proc['id'] ?? $snap['id_proc'] ?? 0);
        $userId = (int)($proc['id_user'] ?? $snap['id_user'] ?? 0);
        if ($procId < 1 || $userId < 1 || $pollId < 1) {
            return 0;
        }
        $dtStart = (int)($proc['dtstart'] ?? 0);
        $dtEnd = (int)($proc['dtend'] ?? 0);
        if ($dtEnd < 1) {
            $dtEnd = $dtStart > 0 ? $dtStart : time();
        }
        if ($dtStart < 1) {
            $dtStart = $dtEnd;
        }
        $idEventParent = (int)($proc['id_event_parent'] ?? 0);
        $idEvent = $idEventParent > 0 ? $idEventParent : $procId;

        $respParts = [];
        $opId = (int)($proc['id_operator'] ?? 0);
        $admId = (int)($proc['id_admin'] ?? 0);
        if ($opId > 0) {
            $respParts[] = '#' . $opId . '#';
        }
        if ($admId > 0 && $admId !== $opId) {
            $respParts[] = '#' . $admId . '#';
        }

        $linked = (int)($snap['id_operator_linked'] ?? 0);
        if ($linked < 1) {
            $linked = $opId > 0 ? $opId : $admId;
        }

        $availTs = (int)mktime(0, 0, 1, (int)date('n', $dtStart), (int)date('j', $dtStart), (int)date('Y', $dtStart));
        $expireTs = (int)mktime(23, 59, 59, (int)date('n', $dtEnd), (int)date('j', $dtEnd), (int)date('Y', $dtEnd));
        $created = self::oralAskCenter((int)($snap['dt_create'] ?? 0), $dtEnd);

        $poll = class_exists('PollsRepository', false) ? PollsRepository::find($pollId) : null;
        $log = '[' . date('d.m.y H:i') . '][0]: [ORAL_ASKED][POLL_SPEECH] recreate from speech'
            . ($created > 0 ? ' (mark@' . date('Y-m-d H:i', $created) . ')' : '')
            . "\n";

        return PollInvitesRepository::create([
            'dt_avail' => $availTs,
            'dt_expire' => $expireTs,
            'id_operator_linked' => $linked,
            'id_user' => $userId,
            'id_event' => $idEvent,
            'id_proc' => $procId,
            'id_poll' => $pollId,
            'text_admin_to_user' => '',
            'bonus_auto' => !empty($poll['bonus_auto']) ? 1 : 0,
            'bonus_value' => max(0, (int)($poll['bonus_default'] ?? 0)),
            'bonus_given' => 0,
            'log' => $log,
            'dt_nextcall' => 0,
            'status' => 0,
            'is_special' => !empty($poll['is_special']) ? 1 : 0,
            'is_special_friend' => !empty($poll['is_special_friend']) ? 1 : 0,
            'sign_req' => 0,
            'code' => '',
            'resp_users' => implode(',', $respParts),
            'is_result' => !empty($poll['is_result']) ? 1 : (!empty($snap['is_result']) ? 1 : 1),
            'source' => 'oral_asked',
            'id_proc_operator' => $opId,
            'id_proc_admin' => $admId,
            // Keep oral-ask moment (near procedure) so later fillDay windows stay correct.
            'dt_create' => $created > 0 ? $created : 0,
        ]);
    }

    /**
     * Prefer invite create when it falls on the procedure day; otherwise procedure end.
     */
    private static function oralAskCenter(int $inviteCreate, int $procEnd): int
    {
        if ($procEnd < 1) {
            return $inviteCreate > 0 ? $inviteCreate : 0;
        }
        if ($inviteCreate < 1) {
            return $procEnd;
        }
        // Same calendar day (app TZ) → trust the oral-ask button timestamp.
        if (date('Y-m-d', $inviteCreate) === date('Y-m-d', $procEnd)) {
            return $inviteCreate;
        }
        // Bulk recreate used to stamp DT_CREATE = now for every invite.
        return $procEnd;
    }

    /**
     * @param array<int,int> $ansStaff
     * @param array<int,int> $ansService
     * @return array{data_opts:array<string,int>,data_text:array<string,string>}
     */
    private static function buildAnswers(
        int $score,
        string $snippet,
        int $qidStaff,
        int $qidService,
        array $ansStaff,
        array $ansService
    ): array {
        $dataOpts = [];
        if ($qidStaff > 0 && isset($ansStaff[$score])) {
            $dataOpts[(string)$qidStaff] = (int)$ansStaff[$score];
        }
        if ($qidService > 0 && isset($ansService[$score])) {
            $dataOpts[(string)$qidService] = (int)$ansService[$score];
        }
        $dataText = [];
        $snippet = trim($snippet);
        if ($snippet !== '') {
            $snippet = mb_substr($snippet, 0, 500);
            if ($qidStaff > 0) {
                $dataText[(string)$qidStaff] = $snippet;
            }
            if ($qidService > 0) {
                $dataText[(string)$qidService] = $snippet;
            }
        }
        return ['data_opts' => $dataOpts, 'data_text' => $dataText];
    }

    /**
     * Prefer transcript extracts / key hits closest to oral-ask time.
     * Claim keys prevent one cabin record (or one ask snippet) filling several procedures.
     *
     * @param list<int> $adminIds
     * @param array<int,int> $keyScores
     * @param array<string,true> $usedEvidence
     * @return array{score:int,via:string,snippet:string,claim:string}
     */
    private static function resolveScoreAndSnippet(
        array $adminIds,
        int $winFrom,
        int $winTo,
        int $dictId,
        array $keyScores,
        int $preferTs = 0,
        array &$usedEvidence = []
    ): array {
        $empty = ['score' => 0, 'via' => '', 'snippet' => '', 'claim' => ''];
        $preferTs = $preferTs > 0 ? $preferTs : (int)(($winFrom + $winTo) / 2);

        $records = class_exists('PollSpeechDictEnrich', false)
            ? PollSpeechRepository::recordsInWindow($adminIds, $winFrom, $winTo)
            : [];

        /** @var list<array{score:int,phrase:string,snippet:string,record_id:int,dt:int,claim:string,via:string}> $candidates */
        $candidates = [];
        foreach ($records as $rec) {
            $rid = (int)($rec['id'] ?? 0);
            $rdt = (int)($rec['dt'] ?? 0);
            if ($rid < 1 || !class_exists('PollSpeechDictEnrich', false)) {
                continue;
            }
            foreach (PollSpeechDictEnrich::extractScoresFromTranscription((string)($rec['transcription'] ?? '')) as $ex) {
                $snippet = trim((string)($ex['snippet'] ?? ''));
                $score = (int)($ex['score'] ?? 0);
                if ($score < 1 || $score > 10) {
                    continue;
                }
                // One cabin record → at most one invite (multiple ask-lines in one file are one talk).
                $claim = 'r' . $rid;
                if (isset($usedEvidence[$claim])) {
                    continue;
                }
                $candidates[] = [
                    'score' => $score,
                    'phrase' => (string)($ex['phrase'] ?? ''),
                    'snippet' => $snippet,
                    'record_id' => $rid,
                    'dt' => $rdt,
                    'claim' => $claim,
                    'via' => 'transcript phrase=' . (string)($ex['phrase'] ?? '') . ' record=' . $rid,
                ];
            }
        }

        $hits = PollSpeechRepository::scoreHitsInWindow(
            $adminIds,
            $winFrom,
            $winTo,
            $dictId,
            array_keys($keyScores)
        );
        foreach ($hits as $hit) {
            $rid = (int)$hit['id_record'];
            $score = (int)($keyScores[(int)$hit['id_key']] ?? 0);
            if ($rid < 1 || $score < 1 || $score > 10) {
                continue;
            }
            $snippet = '';
            foreach ($candidates as $c) {
                if ((int)$c['record_id'] === $rid && (int)$c['score'] === $score) {
                    $snippet = (string)$c['snippet'];
                    break;
                }
            }
            if ($snippet === '') {
                foreach ($records as $rec) {
                    if ((int)($rec['id'] ?? 0) !== $rid || !class_exists('PollSpeechDictEnrich', false)) {
                        continue;
                    }
                    $xs = PollSpeechDictEnrich::extractScoresFromTranscription((string)($rec['transcription'] ?? ''));
                    foreach (array_reverse($xs) as $ex) {
                        if ((int)($ex['score'] ?? 0) === $score) {
                            $snippet = trim((string)($ex['snippet'] ?? ''));
                            break 2;
                        }
                    }
                    if ($xs !== [] && $snippet === '') {
                        $snippet = trim((string)($xs[count($xs) - 1]['snippet'] ?? ''));
                    }
                }
            }
            $claim = 'r' . $rid;
            if (isset($usedEvidence[$claim])) {
                continue;
            }
            $candidates[] = [
                'score' => $score,
                'phrase' => 'key=' . (int)$hit['id_key'],
                'snippet' => $snippet,
                'record_id' => $rid,
                'dt' => (int)$hit['dt_start'],
                'claim' => $claim,
                'via' => 'key=' . (int)$hit['id_key'] . ' record=' . $rid,
            ];
        }

        if ($candidates === []) {
            return $empty;
        }

        // Dedupe key+transcript doubles of the same ask snippet.
        $uniq = [];
        foreach ($candidates as $c) {
            $uniq[(string)$c['claim']] = $c;
        }
        $candidates = array_values($uniq);

        usort($candidates, static function (array $a, array $b) use ($preferTs): int {
            $da = abs((int)$a['dt'] - $preferTs);
            $db = abs((int)$b['dt'] - $preferTs);
            if ($da !== $db) {
                return $da <=> $db;
            }
            // Prefer richer snippet when equally close.
            return strlen((string)$b['snippet']) <=> strlen((string)$a['snippet']);
        });

        $best = $candidates[0];
        return [
            'score' => (int)$best['score'],
            'via' => (string)$best['via'],
            'snippet' => (string)$best['snippet'],
            'claim' => (string)$best['claim'],
        ];
    }

    /**
     * Attach/update rating transcript snippets on already speech-filled invites (DATA_TEXT only).
     *
     * @param array{date?:string,day_start?:int,day_end?:int,log?:callable|null} $ctx
     * @return array{ok:bool,updated:int,skipped:int,date:string}
     */
    public static function backfillSnippets(array $ctx): array
    {
        $date = trim((string)($ctx['date'] ?? ''));
        $dayStart = (int)($ctx['day_start'] ?? 0);
        $dayEnd = (int)($ctx['day_end'] ?? 0);
        if ($dayStart < 1 || $dayEnd < $dayStart) {
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dayStart = (int)strtotime($date . ' 00:00:00');
                $dayEnd = (int)strtotime($date . ' 23:59:59');
            }
        }
        if ($date === '' && $dayStart > 0) {
            $date = date('Y-m-d', $dayStart);
        }
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $window = PollSpeechConfig::windowSec();
        $adminField = PollSpeechConfig::adminField();
        $dictId = PollSpeechConfig::dictId();
        $keyScores = PollSpeechConfig::keyScoreMap();

        $invites = PollSpeechRepository::speechFilledInvitesForDay($dayStart, $dayEnd);
        $updated = 0;
        $skipped = 0;
        /** @var array<string,true> $usedEvidence */
        $usedEvidence = [];
        foreach ($invites as $invite) {
            $inviteId = (int)$invite['id'];
            $procId = (int)$invite['id_proc'];
            $proc = class_exists('ProceduresRepository', false)
                ? ProceduresRepository::find($procId)
                : null;
            if ($proc === null) {
                $skipped++;
                continue;
            }
            $created = (int)($invite['dt_create'] ?? 0);
            $dtEnd = (int)($proc['dtend'] ?? 0);
            $center = self::oralAskCenter($created, $dtEnd);
            $adminIds = self::resolveAdminIds($invite, $proc, $adminField);
            $resolved = self::resolveScoreAndSnippet(
                $adminIds,
                max(0, $center - $window),
                $center + $window,
                $dictId,
                $keyScores,
                $center,
                $usedEvidence
            );
            $snippet = trim((string)($resolved['snippet'] ?? ''));
            if ($snippet === '') {
                $skipped++;
                continue;
            }
            $snippet = mb_substr($snippet, 0, 500);
            $dataText = [];
            if ($qidStaff > 0) {
                $dataText[(string)$qidStaff] = $snippet;
            }
            if ($qidService > 0) {
                $dataText[(string)$qidService] = $snippet;
            }
            if ($dataText === []) {
                $skipped++;
                continue;
            }
            try {
                PollInvitesRepository::mergeDataText($inviteId, $dataText, '[SPEECH] snippet backfill');
                $claim = (string)($resolved['claim'] ?? '');
                if ($claim !== '') {
                    $usedEvidence[$claim] = true;
                }
                $updated++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
        return ['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'date' => $date];
    }

    /** @param array<string,mixed> $invite */
    private static function inviteAlreadyAnswered(array $invite): bool
    {
        if ((int)($invite['dt_completed'] ?? 0) > 0) {
            return true;
        }
        $raw = trim((string)($invite['data_opts'] ?? ''));
        if ($raw === '' || $raw === 'a:0:{}' || $raw === '{}' || $raw === '[]') {
            return false;
        }
        if (!class_exists('PollAnswersCodec', false)) {
            return $raw !== '';
        }
        $decoded = PollAnswersCodec::decode($raw);
        return is_array($decoded) && $decoded !== [];
    }

    /**
     * @param array<string,mixed> $invite
     * @param array<string,mixed> $proc
     * @return list<int>
     */
    private static function resolveAdminIds(array $invite, array $proc, string $field): array
    {
        $linked = (int)($invite['id_operator_linked'] ?? 0);
        $procAdmin = (int)($proc['id_admin'] ?? 0);
        $procOp = (int)($proc['id_operator'] ?? 0);
        $ids = [];
        // Prefer procedure executor cabin first — ratings near the table are more specific
        // than a shared floor-admin recording reused across overlapping procedures.
        if ($procOp > 0) {
            $ids[] = $procOp;
        }
        if ($field === PollSpeechConfig::ADMIN_PROC) {
            if ($procAdmin > 0) {
                $ids[] = $procAdmin;
            }
        } elseif ($field === PollSpeechConfig::ADMIN_BOTH) {
            if ($linked > 0) {
                $ids[] = $linked;
            }
            if ($procAdmin > 0) {
                $ids[] = $procAdmin;
            }
        } else {
            if ($linked > 0) {
                $ids[] = $linked;
            } elseif ($procAdmin > 0) {
                $ids[] = $procAdmin;
            }
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** @param callable|null $log */
    private static function say($log, string $msg): void
    {
        if (is_callable($log)) {
            $log($msg);
        }
    }
}
