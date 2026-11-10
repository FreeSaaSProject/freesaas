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
            // Score without dialogue snippet must not enter stats.
            if ($built['data_opts'] === [] || $built['data_text'] === []) {
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
                        . ' snippet=' . mb_substr($snippet, 0, 120)
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
     * No speech answer → no invite. Pass date=YYYY-MM-DD to scope to that procedure day.
     *
     * @param array{date?:string,log?:callable|null} $ctx
     * @return array{ok:bool,deleted:int,created:int,filled:int,skipped:int,errors:list<string>,date:string}
     */
    public static function recreateAllFromSpeech(array $ctx = []): array
    {
        $log = is_callable($ctx['log'] ?? null) ? $ctx['log'] : null;
        $date = trim((string)($ctx['date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'ok' => false,
                'deleted' => 0,
                'created' => 0,
                'filled' => 0,
                'skipped' => 0,
                'errors' => [t('poll_speech.err.day')],
                'date' => $date,
            ];
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
            'date' => $date,
        ];
        if ($pollId < 1 || $dictId < 1 || ($qidStaff < 1 && $qidService < 1)) {
            $out['errors'][] = t('poll_speech.err.tables');
            return $out;
        }
        if (!Database::tableExists('base_poll_invites') || !class_exists('PollInvitesRepository', false)) {
            $out['errors'][] = t('poll_speech.err.tables');
            return $out;
        }

        $marks = PollSpeechRepository::listAllOralMarks($date !== '' ? $date : null);
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
            if ($built['data_opts'] === [] || $built['data_text'] === []) {
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
                        . ' snippet=' . mb_substr($snippet, 0, 120)
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
            if ($built['data_opts'] === [] || $built['data_text'] === []) {
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
                        . ' snippet=' . mb_substr($snippet, 0, 120)
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

        $opId = (int)($proc['id_operator'] ?? 0);
        $admId = (int)($proc['id_admin'] ?? 0);
        $respUsers = class_exists('PollInvitesRepository', false)
            ? PollInvitesRepository::buildRespUsers($opId, $admId)
            : '';

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
            'resp_users' => $respUsers,
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
     * Superadmin manual score override (keeps existing dialogue snippet).
     *
     * @throws InvalidArgumentException
     */
    public static function applyManualScore(int $inviteId, int $score): void
    {
        if ($inviteId < 1 || $score < 1 || $score > 10) {
            throw new InvalidArgumentException(t('poll_invites.err.score_range'));
        }
        if (!class_exists('PollInvitesRepository', false)) {
            throw new InvalidArgumentException(t('poll_speech.err.tables'));
        }
        $invite = PollInvitesRepository::find($inviteId);
        if ($invite === null) {
            throw new InvalidArgumentException(t('poll_invites.err.not_found', ['id' => $inviteId]));
        }
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $ansStaff = PollSpeechConfig::answersStaff();
        $ansService = PollSpeechConfig::answersService();
        if (($qidStaff < 1 && $qidService < 1) || ($ansStaff === [] && $ansService === [])) {
            throw new InvalidArgumentException(t('poll_invites.err.speech_score'));
        }
        $snippet = '';
        if (class_exists('PollInvitesRepository', false)) {
            $snippet = PollInvitesRepository::transcriptSnippet($invite['data_text'] ?? '', 0);
        }
        if ($snippet === '' && class_exists('PollAnswersCodec', false)) {
            $texts = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
            if (is_array($texts)) {
                foreach ($texts as $tv) {
                    $tv = trim((string)$tv);
                    if ($tv !== '') {
                        $snippet = $tv;
                        break;
                    }
                }
            }
        }
        $built = self::buildAnswers($score, $snippet, $qidStaff, $qidService, $ansStaff, $ansService);
        if ($built['data_opts'] === []) {
            throw new InvalidArgumentException(t('poll_invites.err.speech_score'));
        }
        // Keep prior text slots if new build has empty text (no snippet).
        if ($built['data_text'] === [] && class_exists('PollAnswersCodec', false)) {
            $prev = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
            if (is_array($prev) && $prev !== []) {
                $built['data_text'] = [];
                foreach ($prev as $k => $v) {
                    $built['data_text'][(string)$k] = (string)$v;
                }
            }
        }
        PollInvitesRepository::saveAnswersFromChannel(
            $inviteId,
            $built['data_opts'],
            $built['data_text'],
            true,
            'manual',
            '[SCORE] manual=' . $score
        );
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

        // Ratchet per cabin record: higher scores never get lowered by softer keys
        // («10» + «хорошо»→8 + «три»→3 ⇒ 10). Same score → prefer transcript via / richer snippet.
        $uniq = [];
        foreach ($candidates as $c) {
            $claim = (string)$c['claim'];
            if (!isset($uniq[$claim])) {
                $uniq[$claim] = $c;
                continue;
            }
            $uniq[$claim] = self::ratchetPreferCandidate($uniq[$claim], $c);
        }
        $candidates = array_values($uniq);

        // Key-only hits without an ask→reply dialogue are noise for stats — drop them.
        $candidates = array_values(array_filter(
            $candidates,
            static function (array $c): bool {
                return trim((string)($c['snippet'] ?? '')) !== '';
            }
        ));
        if ($candidates === []) {
            return $empty;
        }

        usort($candidates, static function (array $a, array $b) use ($preferTs): int {
            $da = abs((int)$a['dt'] - $preferTs);
            $db = abs((int)$b['dt'] - $preferTs);
            if ($da !== $db) {
                return $da <=> $db;
            }
            // Same distance: still ratchet upward, then richer snippet.
            $sa = (int)$a['score'];
            $sb = (int)$b['score'];
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
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
     * Keep the higher score; never replace a stronger hit with a weaker soft key.
     *
     * @param array{score:int,phrase:string,snippet:string,record_id:int,dt:int,claim:string,via:string} $a
     * @param array{score:int,phrase:string,snippet:string,record_id:int,dt:int,claim:string,via:string} $b
     * @return array{score:int,phrase:string,snippet:string,record_id:int,dt:int,claim:string,via:string}
     */
    private static function ratchetPreferCandidate(array $a, array $b): array
    {
        $sa = (int)($a['score'] ?? 0);
        $sb = (int)($b['score'] ?? 0);
        if ($sb > $sa) {
            // Keep the better dialogue text when the winner has none.
            if (trim((string)($b['snippet'] ?? '')) === '' && trim((string)($a['snippet'] ?? '')) !== '') {
                $b['snippet'] = $a['snippet'];
            }
            return $b;
        }
        if ($sa > $sb) {
            if (trim((string)($a['snippet'] ?? '')) === '' && trim((string)($b['snippet'] ?? '')) !== '') {
                $a['snippet'] = $b['snippet'];
            }
            return $a;
        }
        // Equal score: prefer transcript extract over dict key; then longer snippet.
        $aTr = str_starts_with((string)($a['via'] ?? ''), 'transcript');
        $bTr = str_starts_with((string)($b['via'] ?? ''), 'transcript');
        if ($bTr && !$aTr) {
            return $b;
        }
        if ($aTr && !$bTr) {
            return $a;
        }
        return strlen((string)($b['snippet'] ?? '')) > strlen((string)($a['snippet'] ?? '')) ? $b : $a;
    }

    /**
     * True when DATA_TEXT has at least one non-empty dialogue snippet.
     */
    public static function hasDialogueSnippet($dataText): bool
    {
        if (class_exists('PollInvitesRepository', false)) {
            return PollInvitesRepository::transcriptSnippet($dataText, 0) !== '';
        }
        if (!is_array($dataText)) {
            $dataText = class_exists('PollAnswersCodec', false)
                ? PollAnswersCodec::decode((string)$dataText)
                : [];
        }
        if (!is_array($dataText)) {
            return false;
        }
        foreach ($dataText as $v) {
            if (trim((string)$v) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Safe cleanup of completed [SPEECH] fills without dialogue snippet.
     *
     * Does NOT delete:
     * - open oral marks (not completed);
     * - fills that already have DATA_TEXT;
     * - recent fills (default grace 12h) — ASR/collect may still be running;
     * - fills whose cabin record(s) still have empty TRANSCRIPTION.
     *
     * Before delete: one backfill attempt (attach ask→reply if transcript now exists).
     *
     * @param array{log?:callable|null,limit?:int,min_age_hours?:int,try_backfill?:bool} $ctx
     * @return array{ok:bool,deleted:int,scanned:int,backfilled:int,skipped_young:int,skipped_asr:int,skipped_other:int}
     */
    public static function purgeFilledWithoutSnippet(array $ctx = []): array
    {
        $log = is_callable($ctx['log'] ?? null) ? $ctx['log'] : null;
        $out = [
            'ok' => true,
            'deleted' => 0,
            'scanned' => 0,
            'backfilled' => 0,
            'skipped_young' => 0,
            'skipped_asr' => 0,
            'skipped_other' => 0,
        ];
        if (!Database::tableExists('base_poll_invites')) {
            $out['ok'] = false;
            return $out;
        }
        $minAgeHours = (int)($ctx['min_age_hours'] ?? 12);
        if ($minAgeHours < 0) {
            $minAgeHours = 0;
        }
        $cutoff = time() - ($minAgeHours * 3600);
        $tryBackfill = !array_key_exists('try_backfill', $ctx) || !empty($ctx['try_backfill']);
        $limit = max(0, (int)($ctx['limit'] ?? 0));

        $sql = "SELECT ID AS id, ID_PROC AS id_proc, DT_CREATE AS dt_create, DT_COMPLETED AS dt_completed,
                       DATA_TEXT AS data_text, LOG AS log,
                       ID_OPERATOR_LINKED AS id_operator_linked,
                       ID_PROC_OPERATOR AS id_proc_operator, ID_PROC_ADMIN AS id_proc_admin
                FROM base_poll_invites
                WHERE DT_COMPLETED > 0
                  AND LOG LIKE '%[SPEECH]%'
                ORDER BY ID ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $st = Database::pdo()->query($sql);
        $toDelete = [];
        $window = class_exists('PollSpeechConfig', false) ? PollSpeechConfig::windowSec() : 900;
        $adminField = class_exists('PollSpeechConfig', false)
            ? PollSpeechConfig::adminField()
            : 'both';
        $dictId = class_exists('PollSpeechConfig', false) ? PollSpeechConfig::dictId() : 0;
        $keyScores = class_exists('PollSpeechConfig', false) ? PollSpeechConfig::keyScoreMap() : [];
        $qidStaff = class_exists('PollSpeechConfig', false) ? PollSpeechConfig::qidStaff() : 0;
        $qidService = class_exists('PollSpeechConfig', false) ? PollSpeechConfig::qidService() : 0;

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['scanned']++;
            $id = (int)($row['id'] ?? 0);
            if ($id < 1 || self::hasDialogueSnippet($row['data_text'] ?? '')) {
                continue;
            }
            $completed = (int)($row['dt_completed'] ?? 0);
            if ($completed > $cutoff) {
                $out['skipped_young']++;
                continue;
            }

            $recordIds = class_exists('PollInvitesRepository', false)
                ? PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? ''))
                : [];
            $asrState = self::recordsAsrState($recordIds);
            if ($asrState === 'pending') {
                $out['skipped_asr']++;
                continue;
            }

            // No record id in log: inspect cabin window (may still be waiting for upload/ASR).
            if ($asrState === 'unknown') {
                $procId = (int)($row['id_proc'] ?? 0);
                $proc = ($procId > 0 && class_exists('ProceduresRepository', false))
                    ? ProceduresRepository::find($procId)
                    : null;
                if ($proc === null) {
                    $out['skipped_other']++;
                    continue;
                }
                $center = self::oralAskCenter((int)($row['dt_create'] ?? 0), (int)($proc['dtend'] ?? 0));
                $adminIds = self::resolveAdminIds($row, $proc, $adminField);
                $records = PollSpeechRepository::recordsInWindow(
                    $adminIds,
                    max(0, $center - $window),
                    $center + $window
                );
                if ($records === []) {
                    // No cabin audio yet — do not purge; files may arrive later.
                    $out['skipped_asr']++;
                    continue;
                }
                $pending = false;
                foreach ($records as $rec) {
                    if (trim((string)($rec['transcription'] ?? '')) === '') {
                        $pending = true;
                        break;
                    }
                }
                if ($pending) {
                    $out['skipped_asr']++;
                    continue;
                }
                // ASR done in window — try backfill once.
                if ($tryBackfill) {
                    $used = [];
                    $resolved = self::resolveScoreAndSnippet(
                        $adminIds,
                        max(0, $center - $window),
                        $center + $window,
                        $dictId,
                        $keyScores,
                        $center,
                        $used
                    );
                    $snippet = trim((string)($resolved['snippet'] ?? ''));
                    if ($snippet !== '' && self::attachSnippetToInvite($id, $snippet, $qidStaff, $qidService)) {
                        $out['backfilled']++;
                        continue;
                    }
                }
                $toDelete[] = $id;
                continue;
            }

            // Linked record(s) have transcription — try backfill, else delete confirmed junk.
            if ($tryBackfill) {
                $procId = (int)($row['id_proc'] ?? 0);
                $proc = ($procId > 0 && class_exists('ProceduresRepository', false))
                    ? ProceduresRepository::find($procId)
                    : null;
                if ($proc !== null) {
                    $center = self::oralAskCenter((int)($row['dt_create'] ?? 0), (int)($proc['dtend'] ?? 0));
                    $adminIds = self::resolveAdminIds($row, $proc, $adminField);
                    $used = [];
                    $resolved = self::resolveScoreAndSnippet(
                        $adminIds,
                        max(0, $center - $window),
                        $center + $window,
                        $dictId,
                        $keyScores,
                        $center,
                        $used
                    );
                    $snippet = trim((string)($resolved['snippet'] ?? ''));
                    if ($snippet !== '' && self::attachSnippetToInvite($id, $snippet, $qidStaff, $qidService)) {
                        $out['backfilled']++;
                        continue;
                    }
                }
            }
            $toDelete[] = $id;
        }

        if ($toDelete !== [] && class_exists('PollInvitesRepository', false)) {
            foreach (array_chunk($toDelete, 500) as $chunk) {
                $out['deleted'] += PollInvitesRepository::deleteByIds($chunk);
            }
        }
        self::say(
            $log,
            'poll_speech: purge without snippet'
            . ' deleted=' . $out['deleted']
            . ' backfilled=' . $out['backfilled']
            . ' young=' . $out['skipped_young']
            . ' asr=' . $out['skipped_asr']
            . ' scanned=' . $out['scanned']
        );
        return $out;
    }

    /**
     * @param list<int> $recordIds
     * @return 'pending'|'ready'|'unknown'
     */
    private static function recordsAsrState(array $recordIds): string
    {
        if ($recordIds === [] || !Database::tableExists('base_records')) {
            return 'unknown';
        }
        $ph = implode(',', array_fill(0, count($recordIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, TRANSCRIPTION AS transcription FROM base_records WHERE ID IN ($ph)"
        );
        $st->execute($recordIds);
        $found = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $found++;
            if (trim((string)($row['transcription'] ?? '')) === '') {
                return 'pending';
            }
        }
        return $found > 0 ? 'ready' : 'unknown';
    }

    private static function attachSnippetToInvite(int $inviteId, string $snippet, int $qidStaff, int $qidService): bool
    {
        $snippet = trim($snippet);
        if ($inviteId < 1 || $snippet === '' || !class_exists('PollInvitesRepository', false)) {
            return false;
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
            return false;
        }
        try {
            PollInvitesRepository::mergeDataText($inviteId, $dataText, '[SPEECH] snippet backfill (purge)');
            return true;
        } catch (Throwable $e) {
            return false;
        }
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
