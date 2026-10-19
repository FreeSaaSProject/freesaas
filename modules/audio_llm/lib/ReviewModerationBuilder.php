<?php
declare(strict_types=1);

/**
 * Build a joint transcript package for pending-review moderation (one admin + day).
 */
final class ReviewModerationBuilder
{
    /**
     * @return array{
     *   ok:bool,
     *   error:string,
     *   employee_name:string,
     *   date:string,
     *   transcripts:string,
     *   pending_count:int,
     *   context_count:int,
     *   record_ids:list<int>
     * }
     */
    public static function build(int $adminId, int $year, int $month, int $day): array
    {
        $empty = [
            'ok' => false,
            'error' => '',
            'employee_name' => '',
            'date' => '',
            'transcripts' => '',
            'pending_count' => 0,
            'context_count' => 0,
            'record_ids' => [],
        ];
        if ($adminId < 1 || $year < 1970 || $month < 1 || $month > 12 || $day < 1) {
            $empty['error'] = t('audio_llm.moderation.err.scope');
            return $empty;
        }
        if (!Database::tableExists('base_records') || !Database::tableExists('base_records_reviews')) {
            $empty['error'] = t('audio_llm.moderation.err.tables');
            return $empty;
        }

        $from = (int)mktime(0, 0, 0, $month, $day, $year);
        $to = (int)mktime(23, 59, 59, $month, $day, $year);
        $pending = self::loadPending($adminId, $from, $to);
        if ($pending === []) {
            $empty['error'] = t('audio_llm.moderation.err.none');
            return $empty;
        }
        if (count($pending) > ReviewModerationConfig::MAX_PENDING) {
            $empty['error'] = t('audio_llm.moderation.err.too_many', [
                'n' => count($pending),
                'max' => ReviewModerationConfig::MAX_PENDING,
            ]);
            $empty['pending_count'] = count($pending);
            return $empty;
        }

        $pendingIds = [];
        foreach ($pending as $row) {
            $pendingIds[(int)$row['id']] = true;
        }

        $contextIds = [];
        foreach ($pending as $row) {
            $prev = self::neighbor($adminId, (int)$row['dt_recorded'], (int)$row['id'], false);
            $next = self::neighbor($adminId, (int)$row['dt_recorded'], (int)$row['id'], true);
            foreach ([$prev, $next] as $nb) {
                if ($nb === null) {
                    continue;
                }
                $nid = (int)$nb['id'];
                if (!isset($pendingIds[$nid])) {
                    $contextIds[$nid] = $nb;
                }
            }
        }

        $byId = [];
        foreach ($pending as $row) {
            $byId[(int)$row['id']] = $row + ['is_context' => false];
        }
        foreach ($contextIds as $nid => $row) {
            $byId[$nid] = $row + ['is_context' => true];
        }

        uasort($byId, static function (array $a, array $b): int {
            $dt = ((int)$a['dt_recorded']) <=> ((int)$b['dt_recorded']);
            return $dt !== 0 ? $dt : ((int)$a['id'] <=> (int)$b['id']);
        });

        $dictMeta = class_exists('RecordsRepository', false)
            ? RecordsRepository::dictMetaMap()
            : [];
        $trash = self::enabledTrashPhrases();

        $blocks = [];
        $orderedIds = [];
        foreach ($byId as $row) {
            $rid = (int)$row['id'];
            $orderedIds[] = $rid;
            $blocks[] = self::formatBlock($row, $dictMeta, $trash);
        }

        $employeeName = '';
        if (class_exists('UsersRepository', false)) {
            $employeeName = UsersRepository::nameById($adminId);
        }
        if ($employeeName === '') {
            $employeeName = '#' . $adminId;
        }

        $dateLabel = sprintf('%02d.%02d.%04d', $day, $month, $year);

        return [
            'ok' => true,
            'error' => '',
            'employee_name' => $employeeName,
            'date' => $dateLabel,
            'transcripts' => implode("\n\n---\n\n", $blocks),
            'pending_count' => count($pending),
            'context_count' => count($contextIds),
            'record_ids' => $orderedIds,
        ];
    }

    /**
     * @return list<array{id:int,dt_recorded:int,playtime:int,transcription:string,key_dicts:string}>
     */
    private static function loadPending(int $adminId, int $from, int $to): array
    {
        // Pending review ignores DISABLED (same as calendar Тр.осм / bot queue).
        $st = Database::pdo()->prepare(
            'SELECT r.ID AS id, r.DT_RECORDED AS dt_recorded, r.PLAYTIME AS playtime,
                    r.TRANSCRIPTION AS transcription, r.KEY_TYPES AS key_dicts
             FROM base_records r
             INNER JOIN base_records_reviews rv ON rv.ID_RECORD = r.ID AND rv.DT_REVIEW < 1
             WHERE r.ID_ADMIN = ? AND r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
             ORDER BY r.DT_RECORDED ASC, r.ID ASC'
        );
        $st->execute([$adminId, $from, $to]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int)$row['id'],
                'dt_recorded' => (int)$row['dt_recorded'],
                'playtime' => (int)$row['playtime'],
                'transcription' => (string)($row['transcription'] ?? ''),
                'key_dicts' => (string)($row['key_dicts'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array{id:int,dt_recorded:int,playtime:int,transcription:string,key_dicts:string}|null
     */
    private static function neighbor(int $adminId, int $dt, int $id, bool $next): ?array
    {
        // Neighbors also ignore DISABLED so context stays continuous around queued clips.
        if ($next) {
            $sql = 'SELECT ID AS id, DT_RECORDED AS dt_recorded, PLAYTIME AS playtime,
                           TRANSCRIPTION AS transcription, KEY_TYPES AS key_dicts
                    FROM base_records
                    WHERE ID_ADMIN = ?
                      AND (DT_RECORDED > ? OR (DT_RECORDED = ? AND ID > ?))
                    ORDER BY DT_RECORDED ASC, ID ASC
                    LIMIT 1';
        } else {
            $sql = 'SELECT ID AS id, DT_RECORDED AS dt_recorded, PLAYTIME AS playtime,
                           TRANSCRIPTION AS transcription, KEY_TYPES AS key_dicts
                    FROM base_records
                    WHERE ID_ADMIN = ?
                      AND (DT_RECORDED < ? OR (DT_RECORDED = ? AND ID < ?))
                    ORDER BY DT_RECORDED DESC, ID DESC
                    LIMIT 1';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$adminId, $dt, $dt, $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'dt_recorded' => (int)$row['dt_recorded'],
            'playtime' => (int)$row['playtime'],
            'transcription' => (string)($row['transcription'] ?? ''),
            'key_dicts' => (string)($row['key_dicts'] ?? ''),
        ];
    }

    /**
     * @param array{id:int,dt_recorded:int,playtime:int,transcription:string,key_dicts:string,is_context?:bool} $row
     * @param array<int,array{name:string,color?:string,bg?:string}> $dictMeta
     * @param list<string> $trash
     */
    private static function formatBlock(array $row, array $dictMeta, array $trash): string
    {
        $id = (int)$row['id'];
        $dt = (int)$row['dt_recorded'];
        $play = max(0, (int)$row['playtime']);
        $start = $dt > 0 ? date('H:i', $dt) : '—';
        $end = $dt > 0 ? date('H:i', $dt + $play) : '—';
        $isContext = !empty($row['is_context']);

        $lines = [];
        if ($isContext) {
            $lines[] = t('audio_llm.moderation.block.context_note');
        }
        $lines[] = t('audio_llm.moderation.block.record_id', ['id' => $id]);
        $lines[] = t('audio_llm.moderation.block.time', ['start' => $start, 'end' => $end]);

        if (!$isContext) {
            $dictNames = self::dictNames((string)$row['key_dicts'], $dictMeta);
            if ($dictNames !== []) {
                $lines[] = t('audio_llm.moderation.block.dicts', [
                    'names' => implode(', ', $dictNames),
                ]);
            }
        }

        $lines[] = '';
        $lines[] = t('audio_llm.moderation.block.transcript_label');
        $clean = self::cleanTranscript((string)$row['transcription'], $trash);
        $lines[] = $clean !== '' ? $clean : t('audio_llm.moderation.block.transcript_empty');

        return implode("\n", $lines);
    }

    /**
     * @param array<int,array{name:string}> $dictMeta
     * @return list<string>
     */
    private static function dictNames(string $keyTypes, array $dictMeta): array
    {
        if ($keyTypes === '' || !class_exists('TranscriptHighlighter', false)) {
            return [];
        }
        $names = [];
        foreach (TranscriptHighlighter::parseKeyDicts($keyTypes) as $chip) {
            $did = (int)($chip['dict_id'] ?? 0);
            if ($did < 1) {
                continue;
            }
            $name = trim((string)($dictMeta[$did]['name'] ?? ''));
            if ($name === '') {
                $name = '#' . $did;
            }
            $names[$name] = true;
        }
        return array_keys($names);
    }

    /** @param list<string> $trash */
    private static function cleanTranscript(string $src, array $trash): string
    {
        $src = (string)$src;
        if ($src === '' || strpos($src, '[') === false) {
            return trim($src);
        }

        $parts = explode('[', $src);
        array_shift($parts);
        $out = [];
        $oldStr = '';
        $oldStr2 = '';
        $oldStr3 = '';

        foreach ($parts as $chunk) {
            if (strpos($chunk, ']') === false) {
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $baseText = rtrim($pieces[1] ?? '', "\r\n");
            if ($baseText === '') {
                continue;
            }
            if ($baseText === $oldStr || $baseText === $oldStr2 || $baseText === $oldStr3) {
                continue;
            }
            $isTrash = false;
            if (class_exists('TranscriptGarbage', false)) {
                $isTrash = TranscriptGarbage::isTrashLine($baseText, $trash);
            } else {
                if (mb_strlen($baseText, 'UTF-8') > 300) {
                    $isTrash = true;
                } else {
                    foreach ($trash as $sample) {
                        if ($sample !== '' && mb_strpos($baseText, $sample, 0, 'UTF-8') !== false) {
                            $isTrash = true;
                            break;
                        }
                    }
                }
            }
            if ($isTrash) {
                continue;
            }
            $oldStr3 = $oldStr2;
            $oldStr2 = $oldStr;
            $oldStr = $baseText;
            $out[] = $baseText;
        }

        return implode("\n", $out);
    }

    /** @return list<string> */
    private static function enabledTrashPhrases(): array
    {
        if (!Database::tableExists('base_records_trashes')) {
            return [];
        }
        $st = Database::pdo()->query("SELECT NAME FROM base_records_trashes WHERE ENABLED = '1' OR ENABLED = 1");
        $out = [];
        if ($st) {
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $n = trim((string)($r['NAME'] ?? ''));
                if ($n !== '') {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }
}
