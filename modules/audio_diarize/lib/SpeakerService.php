<?php
declare(strict_types=1);

/**
 * Persist diarization payloads, match voiceprints, day-cluster, orphan auto-bind.
 */
final class SpeakerService
{
    public const MATCH_HIGH = 0.78;
    /** Body-mic WeSpeaker: calibrated on labeled staff cues (see GT 291361). */
    public const MATCH_SOFT = 0.48;
    /** Min (best - second) to accept a voice match when several profiles are close. */
    public const MATCH_MARGIN = 0.05;
    /** Default cue-level match (override via cfg/modules/audio_diarize.ini match_cue). */
    public const MATCH_CUE = 0.48;
    public const ENROLL_MIN_TALK_SEC = 8.0;
    public const DAY_CLUSTER_THRESHOLD = 0.55;

    /** Live cue→profile cosine gate (ini / settings). */
    public static function matchCueThreshold(): float
    {
        if (class_exists('DiarizeSettings', false)) {
            return DiarizeSettings::matchCue();
        }

        return self::MATCH_CUE;
    }

    /** Live top1−top2 margin (ini / settings); 0 disables. */
    public static function matchMarginThreshold(): float
    {
        if (class_exists('DiarizeSettings', false)) {
            return DiarizeSettings::matchMargin();
        }

        return self::MATCH_MARGIN;
    }

    public static function tablesReady(): bool
    {
        return Database::tableExists('base_records_speakers')
            && Database::tableExists('base_records_speaker_profiles');
    }

    /** True when at least one staff voiceprint is ready (manual-build). */
    public static function hasReadyProfiles(): bool
    {
        if (!Database::tableExists('base_records_speaker_profiles')) {
            return false;
        }
        $n = (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_records_speaker_profiles WHERE EMBEDDING_DIM > 0 AND ID_ADMIN > 0'
        )->fetchColumn();
        return $n > 0;
    }

    /** Master switch: module audio_diarize enabled. */
    public static function diarizeEnabled(): bool
    {
        return ModuleManager::isEnabled('audio_diarize');
    }

    public static function diarizeRequiresProfiles(): bool
    {
        if (class_exists('DiarizeSettings', false)) {
            return DiarizeSettings::requiresProfiles();
        }
        return (string)Config::get('audio_diarize', 'requires_profiles', '1') !== '0';
    }

    public static function profileReadyForAdmin(int $adminId): bool
    {
        if ($adminId < 1 || !Database::tableExists('base_records_speaker_profiles')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT EMBEDDING_DIM FROM base_records_speaker_profiles WHERE ID_ADMIN = ? LIMIT 1'
        );
        $st->execute([$adminId]);
        return (int)($st->fetchColumn() ?: 0) > 0;
    }

    /**
     * On-shift staff with AUDIO_ANALYTICS and whether each has a ready voice profile.
     *
     * @return array{
     *   ok:bool,day:string,total:int,missing_count:int,
     *   staff:list<array{id:int,name:string,acronym:string,has_profile:bool}>,
     *   missing:list<array{id:int,name:string,acronym:string,has_profile:bool}>
     * }
     */
    public static function shiftProfileCoverage(string $dayYmd): array
    {
        $dayYmd = trim($dayYmd);
        $empty = [
            'ok' => true,
            'day' => $dayYmd,
            'total' => 0,
            'missing_count' => 0,
            'staff' => [],
            'missing' => [],
        ];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd)) {
            $empty['ok'] = false;
            return $empty;
        }
        if (
            !ModuleManager::isEnabled('workweeks')
            || !class_exists('WorkweeksRepository', false)
            || !is_file(base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php'))
        ) {
            return $empty;
        }
        require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
        $dayStart = strtotime($dayYmd . ' 00:00:00');
        if ($dayStart === false) {
            $empty['ok'] = false;
            return $empty;
        }
        $ids = [];
        foreach (WorkweeksRepository::listForDay((int)$dayStart) as $shift) {
            $oid = (int)($shift['id_operator'] ?? $shift['ID_OPERATOR'] ?? 0);
            if ($oid > 0) {
                $ids[$oid] = true;
            }
        }
        if ($ids === []) {
            return $empty;
        }
        // Reuse picker gate: audio analytics on.
        $audioOn = [];
        if (
            Database::tableExists('base_users')
            && Database::columnExists('base_users', 'AUDIO_ANALYTICS_ENABLED')
        ) {
            $idList = array_keys($ids);
            $ph = implode(',', array_fill(0, count($idList), '?'));
            $st = Database::pdo()->prepare(
                "SELECT ID, AUDIO_ANALYTICS_ENABLED AS audio FROM base_users WHERE ID IN ($ph)"
            );
            $st->execute($idList);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $audioOn[(int)$row['ID']] = !empty($row['audio']);
            }
        }
        $staff = [];
        $missing = [];
        foreach (array_keys($ids) as $uid) {
            if (empty($audioOn[$uid])) {
                continue;
            }
            $name = class_exists('UsersRepository', false)
                ? trim((string)UsersRepository::nameById($uid))
                : ('#' . $uid);
            if ($name === '') {
                $name = '#' . $uid;
            }
            $acr = class_exists('UsersRepository', false)
                ? UsersRepository::shortName($name)
                : '';
            $row = [
                'id' => $uid,
                'name' => $name,
                'acronym' => $acr,
                'has_profile' => self::profileReadyForAdmin($uid),
            ];
            $staff[] = $row;
            if (!$row['has_profile']) {
                $missing[] = $row;
            }
        }
        usort($staff, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        return [
            'ok' => $missing === [],
            'day' => $dayYmd,
            'total' => count($staff),
            'missing_count' => count($missing),
            'staff' => $staff,
            'missing' => $missing,
        ];
    }

    /** True when this record’s calendar day has full on-shift voice-profile coverage. */
    public static function recordDayProfilesReady(int $recordId): bool
    {
        if ($recordId < 1 || !Database::tableExists('base_records')) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT DT_RECORDED FROM base_records WHERE ID = ? LIMIT 1');
        $st->execute([$recordId]);
        $ts = (int)($st->fetchColumn() ?: 0);
        if ($ts < 1) {
            return false;
        }
        $cov = self::shiftProfileCoverage(date('Y-m-d', $ts));
        return !empty($cov['ok']) && (int)($cov['total'] ?? 0) > 0;
    }

    /**
     * Cabin jobs with full shift profiles go to ASR-DIA (speakers+text); otherwise whisper ASR.
     */
    public static function preferAsrDiaForRecord(int $recordId): bool
    {
        if ($recordId < 1 || !self::diarizeEnabled()) {
            return false;
        }
        // requires_profiles (default on): every on-shift audio staff must have a vector.
        if (self::diarizeRequiresProfiles() && !self::recordDayProfilesReady($recordId)) {
            return false;
        }
        if (!is_file(base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php'))) {
            return false;
        }
        require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
        if (!AsrDiaRunsRepository::available()) {
            return false;
        }
        if (!class_exists('EdgeHosts', false) || EdgeHosts::forService('asr_dia') === []) {
            return false;
        }
        // Soft mode (requires_profiles off): still need at least one pack for matching.
        if (!self::diarizeRequiresProfiles() && !self::hasReadyProfiles()) {
            return false;
        }
        return true;
    }

    /**
     * Divert untranscribed record from whisper → asr_dia journal (apply transcription+speakers).
     *
     * @return array{ok:bool,run_id:int,diag:string}
     */
    public static function enqueueAsrDiaInsteadOfWhisper(int $recordId): array
    {
        $out = ['ok' => false, 'run_id' => 0, 'diag' => ''];
        if ($recordId < 1 || !self::preferAsrDiaForRecord($recordId)) {
            $out['diag'] = 'not_eligible';
            return $out;
        }
        require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
        require_once base_path('modules/audio_diarize/lib/AsrDiaPushDispatcher.php');
        $ids = AsrDiaRunsRepository::enqueueRecords([$recordId], '', true, true);
        if ($ids === []) {
            $out['diag'] = 'enqueue_failed';
            return $out;
        }
        AsrDiaPushDispatcher::syncAll();
        $out['ok'] = true;
        $out['run_id'] = (int)$ids[0];
        $out['diag'] = 'ASR_DIA=' . $out['run_id'];
        return $out;
    }

    /**
     * @param array{
     *   speakers?:list<array<string,mixed>>,
     *   segments?:list<array<string,mixed>>,
     *   cue_speakers?:list<array<string,mixed>>,
     *   dominant_speaker?:string
     * }|null $payload
     * @return array{ok:bool,diag:string}
     */
    /**
     * Drop speaker markup so the edge diarize worker can claim the record again.
     *
     * @param list<int> $recordIds
     */
    public static function clearForRecords(array $recordIds): int
    {
        if (!self::tablesReady()) {
            return 0;
        }
        $ids = [];
        foreach ($recordIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }
        $idList = array_values($ids);
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $pdo = Database::pdo();
        $pdo->prepare("DELETE FROM base_records_cue_speakers WHERE ID_RECORD IN ($ph)")->execute($idList);
        if (Database::tableExists('base_records_speaker_segments')) {
            $pdo->prepare("DELETE FROM base_records_speaker_segments WHERE ID_RECORD IN ($ph)")->execute($idList);
        }
        $st = $pdo->prepare("DELETE FROM base_records_speakers WHERE ID_RECORD IN ($ph)");
        $st->execute($idList);
        return $st->rowCount();
    }

    /** @param list<int> $recordIds @return array<int,bool> id => has any speaker row */
    public static function speakerPresenceMap(array $recordIds): array
    {
        $out = [];
        foreach ($recordIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = false;
            }
        }
        if ($out === [] || !Database::tableExists('base_records_speakers')) {
            return $out;
        }
        $ids = array_keys($out);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT ID_RECORD FROM base_records_speakers WHERE ID_RECORD IN ($ph)"
        );
        $st->execute($ids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['ID_RECORD'];
            if (isset($out[$rid])) {
                $out[$rid] = true;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array{rematch?:bool} $opts rematch=false keeps worker IDs (asr_dia already matched with stricter floors)
     */
    public static function saveForRecord(int $recordId, ?array $payload, array $opts = []): array
    {
        if ($recordId < 1 || !self::tablesReady()) {
            return ['ok' => false, 'diag' => 'speakers_unavailable'];
        }
        if (!self::diarizeEnabled()) {
            return ['ok' => false, 'diag' => 'diarize_disabled'];
        }
        if ($payload === null || $payload === []) {
            return ['ok' => true, 'diag' => 'SPEAKERS=0'];
        }

        $speakers = is_array($payload['speakers'] ?? null) ? $payload['speakers'] : [];
        $segments = is_array($payload['segments'] ?? null) ? $payload['segments'] : [];
        $cues = is_array($payload['cue_speakers'] ?? null) ? $payload['cue_speakers'] : [];
        $dominant = trim((string)($payload['dominant_speaker'] ?? ''));
        $doRematch = !array_key_exists('rematch', $opts) || !empty($opts['rematch']);
        // asr_dia (and similar) send final IDs — soft cue rematch would re-attach weak false positives.
        if (!empty($payload['skip_rematch']) || (string)($payload['engine'] ?? '') === 'asr_dia') {
            $doRematch = false;
        }

        $pdo = Database::pdo();
        self::clearForRecords([$recordId]);

        $now = time();
        $insSpk = $pdo->prepare(
            'INSERT INTO base_records_speakers
             (ID_RECORD, LOCAL_ID, ROLE, MATCHED_ADMIN_ID, CONFIDENCE, TALK_SEC, IS_DOMINANT,
              EMBEDDING, EMBEDDING_DIM, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $saved = 0;
        foreach ($speakers as $s) {
            if (!is_array($s)) {
                continue;
            }
            $local = substr(trim((string)($s['id'] ?? '')), 0, 16);
            if ($local === '') {
                continue;
            }
            $emb = [];
            if (isset($s['embedding']) && is_array($s['embedding'])) {
                foreach ($s['embedding'] as $v) {
                    $emb[] = (float)$v;
                }
            }
            $role = strtolower(trim((string)($s['role'] ?? 'unknown')));
            if (!in_array($role, ['staff', 'other', 'unknown'], true)) {
                $role = 'unknown';
            }
            $isDom = ($dominant !== '' && $local === $dominant) || !empty($s['is_dominant']) ? 1 : 0;
            $insSpk->execute([
                $recordId,
                $local,
                $role,
                max(0, (int)($s['id_admin'] ?? $s['matched_admin_id'] ?? 0)),
                (float)($s['confidence'] ?? 0),
                (float)($s['talk_sec'] ?? 0),
                $isDom,
                $emb !== [] ? SpeakerEmbedding::pack($emb) : null,
                count($emb),
                $now,
                $now,
            ]);
            $saved++;
        }

        if (Database::tableExists('base_records_speaker_segments') && $segments !== []) {
            $insSeg = $pdo->prepare(
                'INSERT INTO base_records_speaker_segments (ID_RECORD, LOCAL_ID, T0, T1) VALUES (?, ?, ?, ?)'
            );
            foreach ($segments as $seg) {
                if (!is_array($seg)) {
                    continue;
                }
                $local = substr(trim((string)($seg['speaker'] ?? $seg['local_id'] ?? '')), 0, 16);
                if ($local === '') {
                    continue;
                }
                $insSeg->execute([
                    $recordId,
                    $local,
                    (float)($seg['t0'] ?? 0),
                    (float)($seg['t1'] ?? 0),
                ]);
            }
        }

        if (Database::tableExists('base_records_cue_speakers') && $cues !== []) {
            $insCue = $pdo->prepare(
                'INSERT INTO base_records_cue_speakers (ID_RECORD, STRING_NUM, LOCAL_ID) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE LOCAL_ID = VALUES(LOCAL_ID)'
            );
            foreach ($cues as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $line = (int)($c['line'] ?? 0);
                $local = substr(trim((string)($c['speaker'] ?? '')), 0, 16);
                if ($line < 1 || $local === '') {
                    continue;
                }
                $insCue->execute([$recordId, $line, $local]);
            }
        }

        $cueFix = self::realignCuesFromSegments($recordId);

        $matchDiag = '';
        if ($doRematch) {
            $matchDiag = self::matchRecordAgainstProfiles($recordId);
        }
        // Auto-enroll from dominant is disabled — profiles come from manual samples only.
        $bindDiag = self::maybeAutobindOrphan($recordId);

        $diag = 'SPEAKERS=' . $saved;
        if (!$doRematch) {
            $diag .= ' NO_REMATCH';
        }
        if ($cueFix > 0) {
            $diag .= ' CUE_FIX=' . $cueFix;
        }
        if ($matchDiag !== '') {
            $diag .= ' ' . $matchDiag;
        }
        if ($bindDiag !== '') {
            $diag .= ' ' . $bindDiag;
        }
        return ['ok' => true, 'diag' => $diag];
    }

    /**
     * Fix cue↔speaker labels by majority overlap with Nemotron segments.
     * Mis-tagged lines (S2 on A127 speech) confuse UI filter; samples use segments, not cues.
     *
     * @return int number of cue rows updated or deleted
     */
    public static function realignCuesFromSegments(int $recordId): int
    {
        if (
            $recordId < 1
            || !Database::tableExists('base_records_cue_speakers')
            || !Database::tableExists('base_records_speaker_segments')
            || !Database::tableExists('base_records')
        ) {
            return 0;
        }
        $pdo = Database::pdo();
        $stTx = $pdo->prepare('SELECT TRANSCRIPTION FROM base_records WHERE ID = ? LIMIT 1');
        $stTx->execute([$recordId]);
        $tx = (string)($stTx->fetchColumn() ?: '');
        if (trim($tx) === '') {
            return 0;
        }

        $stSeg = $pdo->prepare(
            'SELECT LOCAL_ID, T0, T1 FROM base_records_speaker_segments
             WHERE ID_RECORD = ? ORDER BY T0 ASC'
        );
        $stSeg->execute([$recordId]);
        $segs = $stSeg->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($segs === []) {
            return 0;
        }

        $stCue = $pdo->prepare(
            'SELECT STRING_NUM, LOCAL_ID FROM base_records_cue_speakers WHERE ID_RECORD = ?'
        );
        $stCue->execute([$recordId]);
        $cues = [];
        while ($row = $stCue->fetch(PDO::FETCH_ASSOC)) {
            $cues[(int)$row['STRING_NUM']] = (string)$row['LOCAL_ID'];
        }
        if ($cues === []) {
            return 0;
        }

        $upd = $pdo->prepare(
            'UPDATE base_records_cue_speakers SET LOCAL_ID = ? WHERE ID_RECORD = ? AND STRING_NUM = ?'
        );
        $del = $pdo->prepare(
            'DELETE FROM base_records_cue_speakers WHERE ID_RECORD = ? AND STRING_NUM = ?'
        );

        $changed = 0;
        $lines = preg_split("/\R/u", $tx) ?: [];
        foreach ($lines as $i => $line) {
            $num = $i + 1;
            if (!isset($cues[$num])) {
                continue;
            }
            $line = trim((string)$line);
            if ($line === '' || $line[0] !== '[') {
                continue;
            }
            $rb = strpos($line, ']');
            if ($rb === false) {
                continue;
            }
            $timeRaw = substr($line, 1, $rb - 1);
            if (strpos($timeRaw, '->') === false) {
                continue;
            }
            [$a, $b] = array_map('trim', explode('->', $timeRaw, 2));
            $t0 = (float)self::mmssToSeconds($a);
            $t1 = (float)self::mmssToSeconds($b);
            if ($t1 <= $t0) {
                $t1 = $t0 + 1.0;
            }
            $dur = $t1 - $t0;
            $votes = [];
            foreach ($segs as $seg) {
                $ov0 = max($t0, (float)$seg['T0']);
                $ov1 = min($t1, (float)$seg['T1']);
                if ($ov1 > $ov0) {
                    $lid = (string)$seg['LOCAL_ID'];
                    $votes[$lid] = ($votes[$lid] ?? 0.0) + ($ov1 - $ov0);
                }
            }
            $best = '';
            $bestOv = 0.0;
            foreach ($votes as $lid => $ov) {
                if ($ov > $bestOv) {
                    $bestOv = $ov;
                    $best = (string)$lid;
                }
            }
            $cur = $cues[$num];
            $minOv = max(0.25, min(0.75, $dur * 0.30));
            if ($best === '' || $bestOv < $minOv) {
                // No reliable segment cover — drop false label (e.g. S2 on silence/other).
                if ($cur !== '') {
                    $del->execute([$recordId, $num]);
                    $changed++;
                }
                continue;
            }
            if ($best !== $cur) {
                $upd->execute([substr($best, 0, 16), $recordId, $num]);
                $changed++;
            }
        }
        return $changed;
    }

    private static function mmssToSeconds(string $mmss): int
    {
        $mmss = trim($mmss);
        if (strpos($mmss, ':') === false) {
            return max(0, (int)$mmss);
        }
        $p = explode(':', $mmss);
        if (count($p) === 2) {
            return max(0, ((int)$p[0]) * 60 + (int)$p[1]);
        }
        if (count($p) >= 3) {
            return max(0, ((int)$p[0]) * 3600 + ((int)$p[1]) * 60 + (int)$p[2]);
        }
        return 0;
    }

    /**
     * Parse worker POST fields into a payload array.
     *
     * @param array<string,mixed> $src
     * @return array<string,mixed>|null
     */
    public static function payloadFromRequest(array $src): ?array
    {
        $speakersRaw = $src['speakers'] ?? null;
        if ($speakersRaw === null || $speakersRaw === '') {
            return null;
        }
        $speakers = is_string($speakersRaw) ? json_decode($speakersRaw, true) : $speakersRaw;
        if (!is_array($speakers)) {
            return null;
        }
        $segments = $src['segments'] ?? [];
        if (is_string($segments)) {
            $segments = json_decode($segments, true);
        }
        $cues = $src['cue_speakers'] ?? [];
        if (is_string($cues)) {
            $cues = json_decode($cues, true);
        }
        return [
            'speakers' => $speakers,
            'segments' => is_array($segments) ? $segments : [],
            'cue_speakers' => is_array($cues) ? $cues : [],
            'dominant_speaker' => trim((string)($src['dominant_speaker'] ?? '')),
        ];
    }

    public static function matchRecordAgainstProfiles(int $recordId): string
    {
        if (!self::tablesReady()) {
            return '';
        }
        $pdo = Database::pdo();
        $stRec = $pdo->prepare('SELECT ID_ADMIN FROM base_records WHERE ID = ? LIMIT 1');
        $stRec->execute([$recordId]);
        $assignedAdmin = (int)($stRec->fetchColumn() ?: 0);

        $profiles = self::allProfiles();
        $profilePack = self::profilesPayloadForWorker();
        $byAdmin = [];
        foreach ($profilePack as $pp) {
            $byAdmin[(int)$pp['id_admin']] = $pp;
        }
        $st = $pdo->prepare(
            'SELECT ID, LOCAL_ID, EMBEDDING, EMBEDDING_DIM, TALK_SEC, IS_DOMINANT
             FROM base_records_speakers WHERE ID_RECORD = ?'
        );
        $st->execute([$recordId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare(
            'UPDATE base_records_speakers
             SET ROLE = ?, MATCHED_ADMIN_ID = ?, CONFIDENCE = ?, DT_UPDATE = ?
             WHERE ID = ?'
        );
        $matched = 0;
        $now = time();
        foreach ($rows as $row) {
            $emb = SpeakerEmbedding::unpack(
                isset($row['EMBEDDING']) ? (string)$row['EMBEDDING'] : null,
                (int)($row['EMBEDDING_DIM'] ?? 0)
            );
            $isDom = (int)($row['IS_DOMINANT'] ?? 0) === 1;

            if ($emb === [] || $profiles === []) {
                // Dominant without voiceprint stays unlabeled staff — no forced badge assignment.
                $upd->execute([$isDom ? 'staff' : 'other', 0, 0.0, $now, (int)$row['ID']]);
                continue;
            }

            $bestAdmin = 0;
            $bestSim = 0.0;
            $second = 0.0;
            $assignedSim = 0.0;
            foreach ($profiles as $p) {
                $pid = (int)$p['id_admin'];
                $pack = $byAdmin[$pid] ?? ['id_admin' => $pid, 'embedding' => $p['embedding'], 'samples' => []];
                $sim = self::bestSimAgainstProfile($emb, $pack);
                if ($assignedAdmin > 0 && $pid === $assignedAdmin) {
                    $assignedSim = $sim;
                }
                if ($sim > $bestSim) {
                    $second = $bestSim;
                    $bestSim = $sim;
                    $bestAdmin = $pid;
                } elseif ($sim > $second) {
                    $second = $sim;
                }
            }
            // Prefer badge wearer only when their score is competitive (not a free pass).
            if (
                $assignedAdmin > 0
                && $assignedSim >= self::matchCueThreshold()
                && $assignedSim + 0.02 >= $bestSim
            ) {
                $bestAdmin = $assignedAdmin;
                $bestSim = $assignedSim;
            }
            $margin = self::matchMarginThreshold();
            $soft = self::matchCueThreshold();
            $ambiguous = $margin > 0.0
                && ($bestSim - $second) < $margin
                && $bestAdmin !== $assignedAdmin;
            $role = $isDom ? 'staff' : 'other';
            $admin = 0;
            $conf = $bestSim;
            // Voice match only — never force assigned admin below threshold.
            if (!$ambiguous && $bestSim >= $soft && $bestAdmin > 0) {
                $role = 'staff';
                $admin = $bestAdmin;
                $matched++;
            } elseif ($isDom) {
                $role = 'staff';
                $admin = 0;
            }
            $upd->execute([$role, $admin, $conf, $now, (int)$row['ID']]);
        }
        return $matched > 0 ? ('VOICE_MATCH=' . $matched) : '';
    }

    /**
     * Build / replace staff profile from embedded manual samples (centroid).
     *
     * @return array{ok:bool,diag:string,sample_count:int}
     */
    public static function buildProfileFromManualSamples(int $adminId): array
    {
        if ($adminId < 1) {
            return ['ok' => false, 'diag' => 'bad_admin', 'sample_count' => 0];
        }
        if (!is_file(base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php'))) {
            return ['ok' => false, 'diag' => 'no_samples_repo', 'sample_count' => 0];
        }
        require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
        $clips = SpeakerSamplesRepository::embeddedManualForAdmin($adminId);
        if ($clips === []) {
            return ['ok' => false, 'diag' => 'no_embedded_samples', 'sample_count' => 0];
        }
        $vecs = [];
        $talk = 0.0;
        foreach ($clips as $c) {
            $vecs[] = $c['embedding'];
            $talk += (float)$c['talk_sec'];
        }
        $cent = SpeakerEmbedding::centroid($vecs);
        if (count($cent) < 32) {
            return ['ok' => false, 'diag' => 'bad_centroid', 'sample_count' => count($clips)];
        }
        self::replaceProfile($adminId, $cent, $talk, count($clips), 'manual_build');
        return ['ok' => true, 'diag' => 'PROFILE=' . $adminId, 'sample_count' => count($clips)];
    }

    /**
     * @param list<float> $embedding
     */
    public static function replaceProfile(
        int $adminId,
        array $embedding,
        float $talkSec,
        int $sampleCount,
        string $source = 'manual_build'
    ): void {
        if ($adminId < 1 || $embedding === [] || !Database::tableExists('base_records_speaker_profiles')) {
            return;
        }
        $pdo = Database::pdo();
        $now = time();
        $blob = SpeakerEmbedding::pack(SpeakerEmbedding::l2norm($embedding));
        $dim = count($embedding);
        $st = $pdo->prepare('SELECT ID FROM base_records_speaker_profiles WHERE ID_ADMIN = ? LIMIT 1');
        $st->execute([$adminId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE base_records_speaker_profiles
                 SET EMBEDDING = ?, EMBEDDING_DIM = ?, SAMPLE_COUNT = ?, TALK_SEC_TOTAL = ?,
                     QUALITY = 1, SOURCE = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            )->execute([$blob, $dim, max(1, $sampleCount), max(0.0, $talkSec), $source, $now, $id]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO base_records_speaker_profiles
             (ID_ADMIN, EMBEDDING, EMBEDDING_DIM, SAMPLE_COUNT, TALK_SEC_TOTAL, QUALITY, SOURCE, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([
            $adminId,
            $blob,
            $dim,
            max(1, $sampleCount),
            max(0.0, $talkSec),
            $source,
            $now,
            $now,
        ]);
    }

    /** @deprecated Auto-enroll from dominant disabled; kept as no-op for callers. */
    public static function maybeEnrollFromRecord(int $recordId): string
    {
        return '';
    }

    /**
     * Orphan auto-bind: only when ID_ADMIN=0 and high-confidence unique voice match.
     */
    public static function maybeAutobindOrphan(int $recordId): string
    {
        // Badges are almost always assigned at ingest — auto-bind is opt-in.
        if (!(int)Config::get('audio', 'speaker_orphan_autobind', 0)) {
            return '';
        }
        if (!self::tablesReady()) {
            return '';
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID, ID_ADMIN FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$recordId]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            return '';
        }
        $adminId = (int)($rec['ID_ADMIN'] ?? 0);
        if ($adminId > 0) {
            // Never override assignment; flag mismatch only
            return self::mismatchNotice($recordId, $adminId);
        }
        $st = $pdo->prepare(
            'SELECT MATCHED_ADMIN_ID, CONFIDENCE, IS_DOMINANT FROM base_records_speakers
             WHERE ID_RECORD = ? AND MATCHED_ADMIN_ID > 0
             ORDER BY IS_DOMINANT DESC, CONFIDENCE DESC LIMIT 2'
        );
        $st->execute([$recordId]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($cands === []) {
            return '';
        }
        $top = $cands[0];
        $conf = (float)($top['CONFIDENCE'] ?? 0);
        $matchAdmin = (int)($top['MATCHED_ADMIN_ID'] ?? 0);
        if ($matchAdmin < 1 || $conf < self::MATCH_HIGH) {
            return '';
        }
        if (isset($cands[1])) {
            $c2 = (float)($cands[1]['CONFIDENCE'] ?? 0);
            if ($c2 >= self::MATCH_SOFT && ((int)($cands[1]['MATCHED_ADMIN_ID'] ?? 0)) !== $matchAdmin) {
                return 'VOICE_AMBIGUOUS=1';
            }
        }
        $pdo->prepare('UPDATE base_records SET ID_ADMIN = ? WHERE ID = ? AND ID_ADMIN < 1')
            ->execute([$matchAdmin, $recordId]);
        return 'ORPHAN_VOICE_BIND=' . $matchAdmin;
    }

    private static function mismatchNotice(int $recordId, int $assignedAdmin): string
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT MATCHED_ADMIN_ID, CONFIDENCE FROM base_records_speakers
             WHERE ID_RECORD = ? AND IS_DOMINANT = 1 AND MATCHED_ADMIN_ID > 0 LIMIT 1'
        );
        $st->execute([$recordId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }
        $matched = (int)$row['MATCHED_ADMIN_ID'];
        $conf = (float)$row['CONFIDENCE'];
        if ($matched > 0 && $matched !== $assignedAdmin && $conf >= self::MATCH_HIGH) {
            return 'VOICE_MISMATCH=' . $matched;
        }
        return '';
    }

    /** @param list<float> $embedding */
    public static function upsertProfile(int $adminId, array $embedding, float $talkSec, string $source = 'auto'): void
    {
        if ($adminId < 1 || $embedding === [] || !Database::tableExists('base_records_speaker_profiles')) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID, EMBEDDING, EMBEDDING_DIM, SAMPLE_COUNT, TALK_SEC_TOTAL
             FROM base_records_speaker_profiles WHERE ID_ADMIN = ? LIMIT 1'
        );
        $st->execute([$adminId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $now = time();
        if ($row) {
            $cur = SpeakerEmbedding::unpack(
                isset($row['EMBEDDING']) ? (string)$row['EMBEDDING'] : null,
                (int)($row['EMBEDDING_DIM'] ?? 0)
            );
            $merged = SpeakerEmbedding::ema($cur, $embedding, 0.2);
            $pdo->prepare(
                'UPDATE base_records_speaker_profiles
                 SET EMBEDDING = ?, EMBEDDING_DIM = ?, SAMPLE_COUNT = SAMPLE_COUNT + 1,
                     TALK_SEC_TOTAL = TALK_SEC_TOTAL + ?, SOURCE = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            )->execute([
                SpeakerEmbedding::pack($merged),
                count($merged),
                max(0.0, $talkSec),
                $source,
                $now,
                (int)$row['ID'],
            ]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO base_records_speaker_profiles
             (ID_ADMIN, EMBEDDING, EMBEDDING_DIM, SAMPLE_COUNT, TALK_SEC_TOTAL, QUALITY, SOURCE, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, 1, ?, 1, ?, ?, ?)'
        )->execute([
            $adminId,
            SpeakerEmbedding::pack(SpeakerEmbedding::l2norm($embedding)),
            count($embedding),
            max(0.0, $talkSec),
            $source,
            $now,
            $now,
        ]);
    }

    /** @return list<array{id_admin:int,embedding:list<float>}> */
    public static function allProfiles(): array
    {
        if (!Database::tableExists('base_records_speaker_profiles')) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT ID_ADMIN, EMBEDDING, EMBEDDING_DIM, SAMPLE_COUNT, TALK_SEC_TOTAL
             FROM base_records_speaker_profiles WHERE ID_ADMIN > 0'
        );
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $emb = SpeakerEmbedding::unpack(
                isset($row['EMBEDDING']) ? (string)$row['EMBEDDING'] : null,
                (int)($row['EMBEDDING_DIM'] ?? 0)
            );
            if ($emb === []) {
                continue;
            }
            $out[] = [
                'id_admin' => (int)$row['ID_ADMIN'],
                'embedding' => $emb,
                'sample_count' => (int)($row['SAMPLE_COUNT'] ?? 0),
                'talk_sec' => (float)($row['TALK_SEC_TOTAL'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Compact profile pack for the diarize worker (centroid + manual sample vectors).
     *
     * @return list<array{id_admin:int,embedding:list<float>,samples:list<list<float>>}>
     */
    public static function profilesPayloadForWorker(): array
    {
        $profiles = self::allProfiles();
        if ($profiles === []) {
            return [];
        }
        $samplesByAdmin = [];
        if (
            is_file(base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php'))
            && Database::tableExists('base_records_speaker_samples')
        ) {
            require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
            foreach ($profiles as $p) {
                $adminId = (int)$p['id_admin'];
                $clips = SpeakerSamplesRepository::embeddedManualForAdmin($adminId);
                $vecs = [];
                foreach ($clips as $c) {
                    if (!empty($c['embedding']) && is_array($c['embedding'])) {
                        $vecs[] = array_map('floatval', $c['embedding']);
                    }
                }
                $samplesByAdmin[$adminId] = $vecs;
            }
        }
        $out = [];
        foreach ($profiles as $p) {
            $adminId = (int)$p['id_admin'];
            $out[] = [
                'id_admin' => $adminId,
                'embedding' => array_map('floatval', $p['embedding']),
                'samples' => $samplesByAdmin[$adminId] ?? [],
                'sample_count' => (int)($p['sample_count'] ?? count($samplesByAdmin[$adminId] ?? [])),
                'talk_sec' => (float)($p['talk_sec'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Best cosine vs profile centroid and its manual samples.
     *
     * @param list<float> $emb
     * @param array{id_admin:int,embedding:list<float>,samples?:list<list<float>>} $profile
     */
    public static function bestSimAgainstProfile(array $emb, array $profile): float
    {
        $best = SpeakerEmbedding::cosineSimilarity($emb, $profile['embedding'] ?? []);
        foreach (is_array($profile['samples'] ?? null) ? $profile['samples'] : [] as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $sim = SpeakerEmbedding::cosineSimilarity($emb, $sample);
            if ($sim > $best) {
                $best = $sim;
            }
        }
        return $best;
    }

    /**
     * Cross-chunk clustering for one badge+day: glue local speakers by embedding proximity.
     *
     * @return array{ok:bool,clusters:int,updated:int,message:string}
     */
    public static function clusterDayBadge(string $day, int $badgeId): array
    {
        if (!self::tablesReady() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $badgeId < 1) {
            return ['ok' => false, 'clusters' => 0, 'updated' => 0, 'message' => 'bad_args'];
        }
        $dayStart = strtotime($day . ' 00:00:00');
        $dayEnd = strtotime($day . ' 23:59:59');
        if ($dayStart === false || $dayEnd === false) {
            return ['ok' => false, 'clusters' => 0, 'updated' => 0, 'message' => 'bad_day'];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT s.ID, s.ID_RECORD, s.LOCAL_ID, s.EMBEDDING, s.EMBEDDING_DIM, s.TALK_SEC, s.IS_DOMINANT, r.ID_ADMIN
             FROM base_records_speakers s
             INNER JOIN base_records r ON r.ID = s.ID_RECORD
             WHERE r.ID_DICTOPHONE = ? AND r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
               AND (r.DISABLED IS NULL OR r.DISABLED < 1)'
        );
        $st->execute([$badgeId, $dayStart, $dayEnd]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return ['ok' => true, 'clusters' => 0, 'updated' => 0, 'message' => 'empty'];
        }

        $items = [];
        foreach ($rows as $row) {
            $emb = SpeakerEmbedding::unpack(
                isset($row['EMBEDDING']) ? (string)$row['EMBEDDING'] : null,
                (int)($row['EMBEDDING_DIM'] ?? 0)
            );
            if ($emb === []) {
                continue;
            }
            $items[] = [
                'id' => (int)$row['ID'],
                'embedding' => $emb,
                'talk' => (float)$row['TALK_SEC'],
                'dominant' => (int)$row['IS_DOMINANT'] === 1,
            ];
        }
        if ($items === []) {
            return ['ok' => true, 'clusters' => 0, 'updated' => 0, 'message' => 'no_embeddings'];
        }

        // Greedy clustering by cosine distance
        $clusters = []; // list of list indices into $items
        foreach ($items as $i => $it) {
            $best = -1;
            $bestSim = 0.0;
            foreach ($clusters as $ci => $members) {
                $cent = SpeakerEmbedding::centroid(array_map(static function ($j) use ($items) {
                    return $items[$j]['embedding'];
                }, $members));
                $sim = SpeakerEmbedding::cosineSimilarity($it['embedding'], $cent);
                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $best = $ci;
                }
            }
            $dist = 1.0 - $bestSim;
            if ($best >= 0 && $dist <= self::DAY_CLUSTER_THRESHOLD) {
                $clusters[$best][] = $i;
            } else {
                $clusters[] = [$i];
            }
        }

        // Pick dominant cluster = most total talk among members that were per-file dominant
        $clusterTalk = [];
        foreach ($clusters as $ci => $members) {
            $sum = 0.0;
            foreach ($members as $j) {
                if ($items[$j]['dominant']) {
                    $sum += $items[$j]['talk'];
                } else {
                    $sum += $items[$j]['talk'] * 0.25;
                }
            }
            $clusterTalk[$ci] = $sum;
        }
        arsort($clusterTalk);
        $staffCluster = (int)array_key_first($clusterTalk);

        $upd = $pdo->prepare(
            'UPDATE base_records_speakers SET DAY_CLUSTER = ?, ROLE = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $now = time();
        $updated = 0;
        foreach ($clusters as $ci => $members) {
            $label = 'D' . $ci;
            $role = ($ci === $staffCluster) ? 'staff' : 'other';
            foreach ($members as $j) {
                $upd->execute([$label, $role, $now, $items[$j]['id']]);
                $updated++;
            }
        }

        return [
            'ok' => true,
            'clusters' => count($clusters),
            'updated' => $updated,
            'message' => 'clustered',
        ];
    }

    /**
     * Sweep recent days: cluster each badge that has speaker rows.
     *
     * @return array{ok:bool,processed:int,message:string}
     */
    public static function clusterRecentDays(int $lookbackDays = 3): array
    {
        if (!self::tablesReady()) {
            return ['ok' => false, 'processed' => 0, 'message' => 'no_tables'];
        }
        $lookbackDays = max(1, min(14, $lookbackDays));
        $pdo = Database::pdo();
        $from = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') - $lookbackDays, (int)date('Y'));
        $st = $pdo->prepare(
            'SELECT DISTINCT r.ID_DICTOPHONE AS badge,
                    DATE(FROM_UNIXTIME(r.DT_RECORDED)) AS day
             FROM base_records r
             INNER JOIN base_records_speakers s ON s.ID_RECORD = r.ID
             WHERE r.DT_RECORDED >= ? AND r.ID_DICTOPHONE > 0
             LIMIT 200'
        );
        $st->execute([$from]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $day = (string)($row['day'] ?? '');
            $badge = (int)($row['badge'] ?? 0);
            if ($day === '' || $badge < 1) {
                continue;
            }
            self::clusterDayBadge($day, $badge);
            $n++;
        }
        return ['ok' => true, 'processed' => $n, 'message' => 'day_cluster_done'];
    }

    /**
     * Manual bind: local cluster (e.g. S2) → staff. Updates match row + optional enrollment clips.
     *
     * @return array{
     *   ok:bool,error:string,local_id:string,admin_id:int,
     *   samples?:array<string,mixed>
     * }
     */
    /**
     * @param list<array{t0:float,t1:float}|array{0:float,1:float}> $excludeRanges
     */
    public static function assignLocalToAdmin(
        int $recordId,
        string $localId,
        int $adminId,
        bool $addSamples = true,
        array $excludeRanges = []
    ): array {
        $localId = substr(trim($localId), 0, 16);
        $out = [
            'ok' => false,
            'error' => '',
            'local_id' => $localId,
            'admin_id' => $adminId,
        ];
        if ($recordId < 1 || $localId === '' || $adminId < 1) {
            $out['error'] = 'bad_args';
            return $out;
        }
        if (!Database::tableExists('base_records_speakers')) {
            $out['error'] = 'speakers_unavailable';
            return $out;
        }
        if (
            class_exists('SpeakerSamplesRepository', false)
            && !SpeakerSamplesRepository::isEligibleStaffAdmin($adminId)
        ) {
            $out['error'] = 'bad_admin';
            return $out;
        }
        if (class_exists('SpeakerSamplesRepository', false)) {
            $allowed = false;
            foreach (SpeakerSamplesRepository::staffOptionsForRecord($recordId) as $opt) {
                if ((int)($opt['id'] ?? 0) === $adminId) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $out['error'] = 'bad_admin';
                return $out;
            }
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID, MATCHED_ADMIN_ID FROM base_records_speakers
             WHERE ID_RECORD = ? AND LOCAL_ID = ? LIMIT 1'
        );
        $st->execute([$recordId, $localId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $out['error'] = 'speaker_missing';
            return $out;
        }

        $now = time();
        $pdo->prepare(
            'UPDATE base_records_speakers
             SET ROLE = ?, MATCHED_ADMIN_ID = ?, CONFIDENCE = ?, DT_UPDATE = ?
             WHERE ID = ?'
        )->execute(['staff', $adminId, 1.0, $now, (int)$row['ID']]);

        $samples = null;
        if ($addSamples && class_exists('SpeakerSamplesRepository', false)) {
            $samples = SpeakerSamplesRepository::addManualFromLocalSpeaker(
                $adminId,
                $recordId,
                $localId,
                $excludeRanges
            );
            if (
                !empty($samples['ok'])
                && (int)($samples['added'] ?? 0) > 0
                && class_exists('DiarizePushDispatcher', false)
            ) {
                DiarizePushDispatcher::afterQueueChange();
            }
        }
        if ($samples !== null) {
            $out['samples'] = $samples;
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * @return list<array{t0:float,t1:float}>
     */
    public static function parseExcludeRanges($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $t0 = (float)($r['t0'] ?? $r[0] ?? 0);
            $t1 = (float)($r['t1'] ?? $r[1] ?? 0);
            if ($t1 > $t0) {
                $out[] = ['t0' => $t0, 't1' => $t1];
            }
        }
        return $out;
    }

    /**
     * @return list<array{local_id:string,role:string,matched_admin_id:int,confidence:float,talk_sec:float,is_dominant:bool,day_cluster:string}>
     */
    public static function speakersForRecord(int $recordId): array
    {
        if ($recordId < 1 || !Database::tableExists('base_records_speakers')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT LOCAL_ID, ROLE, MATCHED_ADMIN_ID, CONFIDENCE, TALK_SEC, IS_DOMINANT, DAY_CLUSTER
             FROM base_records_speakers WHERE ID_RECORD = ? ORDER BY IS_DOMINANT DESC, TALK_SEC DESC'
        );
        $st->execute([$recordId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'local_id' => (string)$row['LOCAL_ID'],
                'role' => (string)$row['ROLE'],
                'matched_admin_id' => (int)$row['MATCHED_ADMIN_ID'],
                'confidence' => (float)$row['CONFIDENCE'],
                'talk_sec' => (float)$row['TALK_SEC'],
                'is_dominant' => (int)$row['IS_DOMINANT'] === 1,
                'day_cluster' => (string)$row['DAY_CLUSTER'],
            ];
        }
        return $out;
    }

    /** @return array<int,string> string_num => local_id */
    public static function cueMapForRecord(int $recordId): array
    {
        $maps = self::cueMapsForRecords([$recordId]);
        return $maps[$recordId] ?? [];
    }

    /**
     * @param list<int> $recordIds
     * @return array<int,array<int,string>> record_id => (string_num => local_id)
     */
    public static function cueMapsForRecords(array $recordIds): array
    {
        $ids = [];
        foreach ($recordIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === [] || !Database::tableExists('base_records_cue_speakers')) {
            return [];
        }
        $idList = array_values($ids);
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_RECORD, STRING_NUM, LOCAL_ID FROM base_records_cue_speakers
             WHERE ID_RECORD IN ($ph)"
        );
        $st->execute($idList);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['ID_RECORD'];
            $out[$rid][(int)$row['STRING_NUM']] = (string)$row['LOCAL_ID'];
        }
        return $out;
    }

    /**
     * Highlight opts for TranscriptHighlighter (roles + matched admins + cues).
     * Acronym badges only when MATCHED_ADMIN_ID > 0 (voice match) — no assigned-admin assume.
     * record_admin_id is the badge wearer on the record (UI hides their own acronym).
     *
     * @return array{cue_speakers:array<int,string>,speaker_roles:array<string,string>,speaker_admins:array<string,int>,record_admin_id:int}
     */
    public static function highlightOptsForRecord(int $recordId, int $assumeAdminId = 0): array
    {
        $bundle = self::highlightOptsForRecords(
            [$recordId],
            $assumeAdminId > 0 ? [$recordId => $assumeAdminId] : []
        );
        return $bundle[$recordId] ?? [
            'cue_speakers' => [],
            'speaker_roles' => [],
            'speaker_admins' => [],
            'record_admin_id' => max(0, $assumeAdminId),
        ];
    }

    /**
     * @param list<int> $recordIds
     * @param array<int,int> $assumeAdminByRecord record id => badge wearer (ID_ADMIN)
     * @return array<int,array{cue_speakers:array<int,string>,speaker_roles:array<string,string>,speaker_admins:array<string,int>,record_admin_id:int}>
     */
    public static function highlightOptsForRecords(array $recordIds, array $assumeAdminByRecord = []): array
    {
        $ids = [];
        foreach ($recordIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'cue_speakers' => [],
                'speaker_roles' => [],
                'speaker_admins' => [],
                'record_admin_id' => max(0, (int)($assumeAdminByRecord[$id] ?? 0)),
            ];
        }
        if ($ids === [] || !Database::tableExists('base_records_speakers')) {
            return $out;
        }

        $cues = self::cueMapsForRecords(array_values($ids));
        foreach ($cues as $rid => $map) {
            $out[$rid]['cue_speakers'] = $map;
        }

        $idList = array_values($ids);
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_RECORD, LOCAL_ID, ROLE, MATCHED_ADMIN_ID
             FROM base_records_speakers WHERE ID_RECORD IN ($ph)"
        );
        $st->execute($idList);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['ID_RECORD'];
            $lid = (string)$row['LOCAL_ID'];
            if ($lid === '' || !isset($out[$rid])) {
                continue;
            }
            $out[$rid]['speaker_roles'][$lid] = strtolower((string)$row['ROLE']);
            $matched = (int)$row['MATCHED_ADMIN_ID'];
            if ($matched > 0) {
                $out[$rid]['speaker_admins'][$lid] = $matched;
            }
        }
        return $out;
    }

    /**
     * Voice-based orphan candidates for a badge window (extends BadgeOrphanHints).
     *
     * @return list<array{admin_id:int,confidence:float,hits:int}>
     */
    public static function orphanVoiceCandidates(string $badgeCode, int $fromTs, int $toTs): array
    {
        if (!self::tablesReady() || $badgeCode === '' || $fromTs < 1 || $toTs < $fromTs) {
            return [];
        }
        $dict = ctype_digit($badgeCode) ? (int)$badgeCode : $badgeCode;
        $st = Database::pdo()->prepare(
            'SELECT s.MATCHED_ADMIN_ID AS admin_id,
                    AVG(s.CONFIDENCE) AS conf,
                    COUNT(*) AS hits
             FROM base_records_speakers s
             INNER JOIN base_records r ON r.ID = s.ID_RECORD
             WHERE r.ID_ADMIN < 1 AND r.ID_DICTOPHONE = ?
               AND r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
               AND s.MATCHED_ADMIN_ID > 0 AND s.IS_DOMINANT = 1
               AND (r.DISABLED IS NULL OR r.DISABLED < 1)
             GROUP BY s.MATCHED_ADMIN_ID
             ORDER BY hits DESC, conf DESC
             LIMIT 5'
        );
        $st->execute([$dict, $fromTs, $toTs]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'admin_id' => (int)$row['admin_id'],
                'confidence' => (float)$row['conf'],
                'hits' => (int)$row['hits'],
            ];
        }
        return $out;
    }
}
