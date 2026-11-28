<?php
declare(strict_types=1);

/**
 * Compact day activity summary for agents (MCP audio__activity__summary): employees x hours.
 *
 * One call replaces reading hundreds of records to answer "who worked when, how much was recognised":
 * per employee (and per hour of the chosen day) record counts, ASR coverage, speech duration,
 * dictionary hits, record ids and diarization (speaker) data. Employees are paged under a byte budget;
 * next_offset = null means every employee of the selection was delivered. `totals` (whole selection) is only
 * on the first page (offset 0); later pages are cheaper because speech is computed for their employees only.
 *
 * Definitions (all per cell):
 *  - records / transcribed / untranscribed: record counts; transcribed = non-empty TRANSCRIPTION.
 *  - playtime_sec: sum of PLAYTIME (recorded audio length).
 *  - asr_records_pct: transcribed / records. asr_coverage_pct: playtime of transcribed records / playtime.
 *  - speech_sec: sum of line durations of the cleaned transcript (garbage lines dropped); capped at the
 *    record playtime. speech_pct = speech_sec / playtime_sec.
 *  - dict_hits / dicts: rows of base_records_instances per dictionary id (legend in top-level `dicts`);
 *    a cell without `dicts` has no hits.
 *  - speakers: diarization rows (base_records_speakers); dominant_* classify each record's dominant speaker
 *    against the record's employee. A cell without `speakers` has no diarized records.
 */
final class ActivitySummary
{
    public const LIMIT_DEFAULT = 20;
    public const LIMIT_MAX = 50;
    public const BYTES_DEFAULT = 12000;
    public const BYTES_MIN = 4000;
    public const BYTES_MAX = 30000;
    /** Max record ids listed per cell. */
    public const IDS_PER_CELL = 200;

    private const ENVELOPE_RESERVE = 600;
    private const CHUNK = 150;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string,mixed> $in query / JSON arguments
     * @return array<string,mixed>
     */
    public static function run(array $in): array
    {
        $p = self::parse($in);
        if ($p['error'] !== '') {
            return ['ok' => false, 'error' => $p['error']];
        }

        $rows = self::loadRecords($p);
        $dicts = self::dictHits($p);
        $spk = self::speakersByRecord($p);
        $speakersAvailable = $spk !== null;

        /** @var array<int,list<array<string,mixed>>> $byAdmin */
        $byAdmin = [];
        foreach ($rows as $r) {
            $byAdmin[(int)$r['id_admin']][] = $r;
        }
        // Order: busiest first, then id (stable paging).
        $order = array_keys($byAdmin);
        usort($order, static function (int $a, int $b) use ($byAdmin): int {
            return [count($byAdmin[$b]), $a] <=> [count($byAdmin[$a]), $b];
        });
        $employeesTotal = count($order);

        // Speech is the only expensive part (cleans every transcript): compute it per employee, on demand.
        /** @var array<int,float> $speech */
        $speech = [];
        $ensureSpeech = static function (array $adminRows) use (&$speech): void {
            $need = [];
            foreach ($adminRows as $r) {
                if (!isset($speech[(int)$r['id']])) {
                    $need[] = $r;
                }
            }
            if ($need === []) {
                return;
            }
            $speech += self::speechByRecord($need);
            foreach ($need as $r) {
                $speech[(int)$r['id']] ??= 0.0;
            }
        };
        $buildEmployee = static function (int $admin) use ($byAdmin, $dicts, $spk, &$speech, $ensureSpeech): array {
            $ensureSpeech($byAdmin[$admin]);
            $e = ['total' => self::newCell(), 'hours' => [], 'badges' => [], 'first' => PHP_INT_MAX, 'last' => 0];
            foreach ($byAdmin[$admin] as $r) {
                $rec = self::recordFor($r, $speech, $dicts, $spk);
                $hour = (int)date('G', (int)$r['dt_recorded']);
                self::addToCell($e['total'], $rec);
                $e['hours'][$hour] ??= self::newCell();
                self::addToCell($e['hours'][$hour], $rec);
                $e['badges'][(int)$r['id_dictophone']] = true;
                $e['first'] = min($e['first'], (int)$r['dt_recorded']);
                $e['last'] = max($e['last'], (int)$r['dt_recorded']);
            }
            return $e;
        };

        // Totals only on the first page: they need every transcript, later pages do not.
        /** @var array<int,array<string,mixed>> $built */
        $built = [];
        $dayOut = null;
        if ($p['offset'] === 0) {
            $day = self::newCell();
            foreach ($order as $a) {
                $built[$a] = $buildEmployee($a);
            }
            foreach ($rows as $r) {
                self::addToCell($day, self::recordFor($r, $speech, $dicts, $spk));
            }
            $dayOut = self::finish($day, $speakersAvailable, false);
            $dayOut['employees'] = $employeesTotal;
        }

        $page = array_slice($order, $p['offset'], $p['limit']);
        $adminIds = array_filter($page, static fn (int $a): bool => $a > 0);
        $names = $adminIds === [] ? [] : UsersRepository::staffNamesIncluding(array_values($adminIds));
        $dictNames = RecordsRepository::dictMetaMap();

        $withHours = $p['detail'] === 'hours';
        $budget = $p['max_bytes'] - self::ENVELOPE_RESERVE;
        if ($dayOut !== null) {
            $budget -= strlen((string)json_encode($dayOut, self::JSON_FLAGS));
        }
        $employees = [];
        $used = 0;
        $legendIds = [];
        if (isset($day)) {
            foreach (array_keys($day['dicts']) as $did) {
                $legendIds[(int)$did] = true;
            }
        }
        foreach ($page as $admin) {
            $e = $built[$admin] ?? $buildEmployee($admin);
            $row = [
                'id_admin' => $admin,
                'name' => $admin > 0 ? (string)($names[$admin] ?? '') : '',
            ];
            if ($admin === 0) {
                $row['unassigned'] = true;
            }
            $badges = array_keys($e['badges']);
            sort($badges);
            $row['badges'] = $badges;
            $row['first_at'] = date('H:i:s', $e['first']);
            $row['last_at'] = date('H:i:s', $e['last']);
            $row += self::finish($e['total'], $speakersAvailable, !$withHours && $p['with_ids'] === 1);
            if ($withHours) {
                $hours = [];
                $hs = array_keys($e['hours']);
                sort($hs);
                foreach ($hs as $h) {
                    $hours[] = ['hour' => $h] + self::finish($e['hours'][$h], $speakersAvailable, $p['with_ids'] === 1);
                }
                $row['hours'] = $hours;
            }
            $size = strlen((string)json_encode($row, self::JSON_FLAGS)) + 1;
            // The first employee is always delivered so paging moves forward.
            if ($employees !== [] && $used + $size > $budget) {
                break;
            }
            $employees[] = $row;
            $used += $size;
            foreach ($e['total']['dicts'] as $did => $_n) {
                $legendIds[(int)$did] = true;
            }
        }

        $nextOffset = $p['offset'] + count($employees);
        if ($nextOffset >= $employeesTotal) {
            $nextOffset = null;
        }
        $legend = new stdClass();
        ksort($legendIds);
        foreach (array_keys($legendIds) as $did) {
            $legend->{(string)$did} = (string)($dictNames[$did]['name'] ?? '');
        }

        $out = [
            'ok' => true,
            'date' => $p['date'],
            'filters' => [
                'admin' => $p['admin'],
                'time_from' => $p['time_from'],
                'time_to' => $p['time_to'],
                'disabled' => $p['disabled'],
                'detail' => $p['detail'],
                'with_ids' => $p['with_ids'],
            ],
        ];
        if ($dayOut !== null) {
            $out['totals'] = $dayOut;
        }
        $out += [
            'employees_total' => $employeesTotal,
            'offset' => $p['offset'],
            'limit' => $p['limit'],
            'next_offset' => $nextOffset,
            'selection_complete' => $nextOffset === null,
            'speakers_available' => $speakersAvailable,
            'dicts' => $legend,
            'bytes' => 0,
            'max_bytes' => $p['max_bytes'],
            'employees' => $employees,
        ];
        if ($nextOffset !== null) {
            $out['hint'] = 'More employees: call again with the same arguments and offset=' . $nextOffset . '.';
        }
        $out['bytes'] = strlen((string)json_encode($out, self::JSON_FLAGS));
        return $out;
    }

    /**
     * @return array{
     *   date:string, from:int, to:int, time_from:string, time_to:string, admin:int, disabled:int,
     *   detail:string, with_ids:int, limit:int, offset:int, max_bytes:int, error:string
     * }
     */
    private static function parse(array $in): array
    {
        $out = [
            'date' => '', 'from' => 0, 'to' => 0, 'time_from' => '', 'time_to' => '', 'admin' => 0,
            'disabled' => 0, 'detail' => 'hours', 'with_ids' => 0,
            'limit' => self::LIMIT_DEFAULT, 'offset' => 0, 'max_bytes' => self::BYTES_DEFAULT,
            'error' => '',
        ];
        $date = trim((string)($in['date'] ?? ''));
        if ($date === '') {
            $out['error'] = t('audio.activity.err.date_required');
            return $out;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $out['error'] = t('audio.transcripts.err.date');
            return $out;
        }
        $out['date'] = $date;
        $base = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        $out['from'] = $base;
        $out['to'] = $base + 86399;

        $timeFrom = trim((string)($in['time_from'] ?? ''));
        $timeTo = trim((string)($in['time_to'] ?? ''));
        if ($timeFrom !== '') {
            $s = TranscriptsBatch::hhmmToSeconds($timeFrom);
            if ($s < 0) {
                $out['error'] = t('audio.transcripts.err.time');
                return $out;
            }
            $out['from'] = $base + $s;
            $out['time_from'] = $timeFrom;
        }
        if ($timeTo !== '') {
            $s = TranscriptsBatch::hhmmToSeconds($timeTo);
            if ($s < 0) {
                $out['error'] = t('audio.transcripts.err.time');
                return $out;
            }
            $out['to'] = $base + $s + 59;
            $out['time_to'] = $timeTo;
        }

        $out['admin'] = max(0, (int)($in['admin'] ?? 0));
        $out['disabled'] = max(0, min(2, (int)($in['disabled'] ?? 0)));
        $detail = trim((string)($in['detail'] ?? 'hours'));
        if ($detail === '') {
            $detail = 'hours';
        }
        if (!in_array($detail, ['hours', 'totals'], true)) {
            $out['error'] = t('audio.activity.err.detail');
            return $out;
        }
        $out['detail'] = $detail;
        $out['with_ids'] = !empty($in['with_ids']) && (string)$in['with_ids'] !== '0' ? 1 : 0;
        $out['limit'] = max(1, min(self::LIMIT_MAX, (int)($in['limit'] ?? self::LIMIT_DEFAULT)));
        $out['offset'] = max(0, (int)($in['offset'] ?? 0));
        $out['max_bytes'] = max(self::BYTES_MIN, min(self::BYTES_MAX, (int)($in['max_bytes'] ?? self::BYTES_DEFAULT)));
        return $out;
    }

    /**
     * WHERE for base_records rows of the selection.
     *
     * @param array<string,mixed> $p
     * @return array{0:string,1:list<int>}
     */
    private static function where(array $p, string $alias): array
    {
        $a = $alias === '' ? '' : $alias . '.';
        $where = [$a . 'DT_RECORDED >= ?', $a . 'DT_RECORDED <= ?'];
        $params = [(int)$p['from'], (int)$p['to']];
        if ((int)$p['admin'] > 0) {
            $where[] = $a . 'ID_ADMIN = ?';
            $params[] = (int)$p['admin'];
        }
        if ((int)$p['disabled'] === 1) {
            $where[] = $a . 'DISABLED = 0';
        } elseif ((int)$p['disabled'] === 2) {
            $where[] = $a . 'DISABLED = 1';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    private static function loadRecords(array $p): array
    {
        if (!Database::tableExists('base_records')) {
            return [];
        }
        [$w, $params] = self::where($p, 'r');
        $pending = Database::tableExists('base_records_reviews')
            ? '(CASE WHEN EXISTS (SELECT 1 FROM base_records_reviews rv WHERE rv.ID_RECORD = r.ID AND rv.DT_REVIEW < 1) THEN 1 ELSE 0 END)'
            : '0';
        $st = Database::pdo()->prepare(
            "SELECT r.ID AS id, r.ID_ADMIN AS id_admin, r.ID_DICTOPHONE AS id_dictophone,
                    r.DT_RECORDED AS dt_recorded, r.PLAYTIME AS playtime, r.DISABLED AS disabled,
                    (CASE WHEN r.TRANSCRIPTION IS NOT NULL AND TRIM(r.TRANSCRIPTION) <> '' THEN 1 ELSE 0 END) AS has_tr,
                    $pending AS pending
             FROM base_records r WHERE $w ORDER BY r.DT_RECORDED ASC, r.ID ASC"
        );
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * One record as the aggregation input.
     *
     * @param array<string,mixed> $r row of loadRecords
     * @param array<int,float> $speech
     * @param array<int,array<int,int>> $dicts
     * @param array<int,array{rows:int,talk:float,staff:float,other:float,dom:int}>|null $spk
     * @return array<string,mixed>
     */
    private static function recordFor(array $r, array $speech, array $dicts, ?array $spk): array
    {
        $rid = (int)$r['id'];
        return [
            'id' => $rid,
            'admin' => (int)$r['id_admin'],
            'playtime' => (int)$r['playtime'],
            'has_tr' => (int)$r['has_tr'] === 1,
            'disabled' => (int)$r['disabled'] === 1,
            'pending' => (int)$r['pending'] === 1,
            'speech' => (float)($speech[$rid] ?? 0.0),
            'dicts' => $dicts[$rid] ?? [],
            'spk' => $spk[$rid] ?? null,
        ];
    }

    /**
     * Speech seconds per record from the cleaned transcript (timecodes of kept lines).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,float>
     */
    private static function speechByRecord(array $rows): array
    {
        $withText = [];
        $playtime = [];
        foreach ($rows as $r) {
            if ((int)$r['has_tr'] === 1) {
                $withText[] = (int)$r['id'];
                $playtime[(int)$r['id']] = (int)$r['playtime'];
            }
        }
        $out = [];
        if ($withText === []) {
            return $out;
        }
        $trash = RecordsRepository::trashPhrases();
        foreach (array_chunk($withText, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = Database::pdo()->prepare("SELECT ID, TRANSCRIPTION FROM base_records WHERE ID IN ($in)");
            $st->execute($chunk);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$row['ID'];
                $f = TranscriptGarbage::filterTranscription((string)$row['TRANSCRIPTION'], $trash);
                $sec = self::speechSeconds($f['text']);
                $cap = $playtime[$id] ?? 0;
                $out[$id] = $cap > 0 ? min($sec, (float)$cap) : $sec;
            }
        }
        return $out;
    }

    /** Sum of (end - start) of "[MM:SS->MM:SS]" lines; fractional seconds are accepted. */
    private static function speechSeconds(string $text): float
    {
        if ($text === '') {
            return 0.0;
        }
        $sum = 0.0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\[(\d+):(\d+(?:\.\d+)?)->(\d+):(\d+(?:\.\d+)?)\]/', $line, $m) !== 1) {
                continue;
            }
            $d = ((int)$m[3] * 60 + (float)$m[4]) - ((int)$m[1] * 60 + (float)$m[2]);
            if ($d > 0) {
                $sum += $d;
            }
        }
        return $sum;
    }

    /**
     * Dictionary hits per record: record id => (dictionary id => hits).
     *
     * @param array<string,mixed> $p
     * @return array<int,array<int,int>>
     */
    private static function dictHits(array $p): array
    {
        if (!Database::tableExists('base_records_instances') || !Database::tableExists('base_records_keys')) {
            return [];
        }
        [$w, $params] = self::where($p, 'r');
        $st = Database::pdo()->prepare(
            "SELECT i.ID_RECORD AS rid, k.ID_DICTONARY AS did, COUNT(*) AS n
             FROM base_records_instances i
             JOIN base_records_keys k ON k.ID = i.ID_KEY
             JOIN base_records r ON r.ID = i.ID_RECORD
             WHERE $w
             GROUP BY i.ID_RECORD, k.ID_DICTONARY"
        );
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['rid']][(int)$row['did']] = (int)$row['n'];
        }
        return $out;
    }

    /**
     * Diarization per record, or null when the speaker tables are not available.
     *
     * @param array<string,mixed> $p
     * @return array<int,array{rows:int,talk:float,staff:float,other:float,dom:int}>|null
     */
    private static function speakersByRecord(array $p): ?array
    {
        if (!class_exists('SpeakerService', false) || !SpeakerService::tablesReady()) {
            return null;
        }
        [$w, $params] = self::where($p, 'r');
        $st = Database::pdo()->prepare(
            "SELECT s.ID_RECORD AS rid, s.ROLE AS role, s.MATCHED_ADMIN_ID AS matched,
                    s.IS_DOMINANT AS dominant, s.TALK_SEC AS talk
             FROM base_records_speakers s
             JOIN base_records r ON r.ID = s.ID_RECORD
             WHERE $w"
        );
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['rid'];
            $out[$rid] ??= ['rows' => 0, 'talk' => 0.0, 'staff' => 0.0, 'other' => 0.0, 'dom' => -1];
            $out[$rid]['rows']++;
            $talk = (float)$row['talk'];
            $out[$rid]['talk'] += $talk;
            if ((string)$row['role'] === 'staff') {
                $out[$rid]['staff'] += $talk;
            } else {
                $out[$rid]['other'] += $talk;
            }
            if ((int)$row['dominant'] === 1 && $out[$rid]['dom'] < 0) {
                $out[$rid]['dom'] = (int)$row['matched'];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function newCell(): array
    {
        return [
            'records' => 0, 'transcribed' => 0, 'disabled' => 0, 'pending_review' => 0,
            'playtime_sec' => 0, 'playtime_transcribed_sec' => 0, 'speech_sec' => 0.0,
            'dict_hits' => 0, 'dicts' => [],
            'spk' => [
                'diarized_records' => 0, 'speaker_rows' => 0, 'talk_sec' => 0.0,
                'staff_talk_sec' => 0.0, 'other_talk_sec' => 0.0,
                'dominant_self' => 0, 'dominant_other' => 0, 'dominant_unmatched' => 0,
            ],
            'ids' => [],
        ];
    }

    /**
     * @param array<string,mixed> $cell
     * @param array<string,mixed> $rec
     */
    private static function addToCell(array &$cell, array $rec): void
    {
        $cell['records']++;
        $cell['playtime_sec'] += (int)$rec['playtime'];
        if ($rec['has_tr']) {
            $cell['transcribed']++;
            $cell['playtime_transcribed_sec'] += (int)$rec['playtime'];
        }
        if ($rec['disabled']) {
            $cell['disabled']++;
        }
        if ($rec['pending']) {
            $cell['pending_review']++;
        }
        $cell['speech_sec'] += (float)$rec['speech'];
        foreach ($rec['dicts'] as $did => $n) {
            $cell['dict_hits'] += (int)$n;
            $cell['dicts'][(int)$did] = ($cell['dicts'][(int)$did] ?? 0) + (int)$n;
        }
        if (is_array($rec['spk'])) {
            $s = $rec['spk'];
            $cell['spk']['diarized_records']++;
            $cell['spk']['speaker_rows'] += $s['rows'];
            $cell['spk']['talk_sec'] += $s['talk'];
            $cell['spk']['staff_talk_sec'] += $s['staff'];
            $cell['spk']['other_talk_sec'] += $s['other'];
            $admin = (int)$rec['admin'];
            if ($s['dom'] > 0 && $admin > 0 && $s['dom'] === $admin) {
                $cell['spk']['dominant_self']++;
            } elseif ($s['dom'] > 0) {
                $cell['spk']['dominant_other']++;
            } else {
                $cell['spk']['dominant_unmatched']++;
            }
        }
        $cell['ids'][] = (int)$rec['id'];
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private static function finish(array $c, bool $withSpeakers, bool $withIds): array
    {
        $records = (int)$c['records'];
        $pt = (int)$c['playtime_sec'];
        $speech = (float)$c['speech_sec'];
        $dicts = new stdClass();
        ksort($c['dicts']);
        foreach ($c['dicts'] as $did => $n) {
            $dicts->{(string)$did} = (int)$n;
        }
        $out = [
            'records' => $records,
            'transcribed' => (int)$c['transcribed'],
            'untranscribed' => $records - (int)$c['transcribed'],
            'disabled' => (int)$c['disabled'],
            'pending_review' => (int)$c['pending_review'],
            'playtime_sec' => $pt,
            'speech_sec' => (int)round($speech),
            'asr_records_pct' => $records > 0 ? round(100 * (int)$c['transcribed'] / $records, 1) : 0.0,
            'asr_coverage_pct' => $pt > 0 ? round(100 * (int)$c['playtime_transcribed_sec'] / $pt, 1) : 0.0,
            'speech_pct' => $pt > 0 ? round(100 * $speech / $pt, 1) : 0.0,
            'dict_hits' => (int)$c['dict_hits'],
        ];
        // Empty blocks are omitted to keep cells small: no `dicts` = no hits, no `speakers` = no diarization.
        if ((int)$c['dict_hits'] > 0) {
            $out['dicts'] = $dicts;
        }
        if ($withSpeakers && (int)$c['spk']['diarized_records'] > 0) {
            $s = $c['spk'];
            $out['speakers'] = [
                'diarized_records' => (int)$s['diarized_records'],
                'speaker_rows' => (int)$s['speaker_rows'],
                'talk_sec' => (int)round((float)$s['talk_sec']),
                'staff_talk_sec' => (int)round((float)$s['staff_talk_sec']),
                'other_talk_sec' => (int)round((float)$s['other_talk_sec']),
                'dominant_self' => (int)$s['dominant_self'],
                'dominant_other' => (int)$s['dominant_other'],
                'dominant_unmatched' => (int)$s['dominant_unmatched'],
            ];
        }
        if ($withIds) {
            $ids = $c['ids'];
            $out['record_ids'] = array_slice($ids, 0, self::IDS_PER_CELL);
            if (count($ids) > self::IDS_PER_CELL) {
                $out['record_ids_truncated'] = true;
            }
        }
        return $out;
    }
}
