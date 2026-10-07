<?php
declare(strict_types=1);

/** Build LLM payload from one base_records transcription (oper records_llm_build_payload_for_record). */
final class StudyPayload
{
    /**
     * @return array{
     *   record_id:int,operator_id:int,operator_name:string,position_name:string,
     *   dt_recorded:int,lines:list<string>,line_timestamps:list<string>
     * }|null
     */
    public static function forRecord(int $recordId): ?array
    {
        if ($recordId < 1 || !Database::tableExists('base_records')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_ADMIN, TRANSCRIPTION, DT_RECORDED FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$recordId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $src = (string)($row['TRANSCRIPTION'] ?? '');
        if ($src === '' || strpos($src, '[') === false) {
            return null;
        }

        $trashArray = self::enabledTrashPhrases();
        $instancesByRawNum = self::reviewInstancesByLine($recordId);

        $parts = explode('[', $src);
        array_shift($parts);
        $lines = [];
        $lineTimestamps = [];
        $oldStr = '';
        $oldStr2 = '';
        $oldStr3 = '';

        $lineNum = 0;
        foreach ($parts as $chunk) {
            $lineNum++;
            if (strpos($chunk, ']') === false) {
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $baseText = rtrim($pieces[1] ?? '', "\r\n");
            if ($baseText === '') {
                continue;
            }
            $tsRaw = $pieces[0] ?? '';
            $arrowP = strpos($tsRaw, '->');
            $tsStr = trim($arrowP !== false ? substr($tsRaw, 0, $arrowP) : $tsRaw);

            if ($baseText === $oldStr || $baseText === $oldStr2 || $baseText === $oldStr3) {
                continue;
            }

            $trashFound = mb_strlen($baseText, 'UTF-8') > 300;
            if (!$trashFound) {
                foreach ($trashArray as $sample) {
                    if ($sample !== '' && mb_strpos($baseText, $sample, 0, 'UTF-8') !== false) {
                        $trashFound = true;
                        break;
                    }
                }
            }
            if ($trashFound) {
                continue;
            }

            $oldStr3 = $oldStr2;
            $oldStr2 = $oldStr;
            $oldStr = $baseText;

            $displayText = $baseText;
            if (!empty($instancesByRawNum[$lineNum])) {
                $linePositions = $instancesByRawNum[$lineNum];
                usort($linePositions, static function ($a, $b) {
                    return $a['pos'] - $b['pos'];
                });
                $offset = 0;
                foreach ($linePositions as $p) {
                    $pos = (int)$p['pos'];
                    $len = (int)$p['len'];
                    if ($len <= 0) {
                        continue;
                    }
                    $before = mb_substr($displayText, 0, $pos + $offset, 'UTF-8');
                    $mid = mb_substr($displayText, $pos + $offset, $len, 'UTF-8');
                    $after = mb_substr($displayText, $pos + $offset + $len, null, 'UTF-8');
                    $wrapped = '[' . $mid . ']';
                    $displayText = $before . $wrapped . $after;
                    $offset += mb_strlen($wrapped, 'UTF-8') - $len;
                }
            }

            $lines[] = $displayText;
            $lineTimestamps[] = $tsStr;
        }

        if ($lines === []) {
            return null;
        }

        $adminId = (int)$row['ID_ADMIN'];
        $operatorName = '';
        $positionName = '';
        if ($adminId > 0 && Database::tableExists('base_users')) {
            $statusCol = Database::columnExists('base_users', 'ID_STATUS')
                ? 'ID_STATUS'
                : (Database::columnExists('base_users', 'name_enabled') ? 'name_enabled' : '');
            if ($statusCol !== '' && Database::tableExists('base_admin_statuses')) {
                $su = Database::pdo()->prepare(
                    "SELECT u.name, s.NAME AS status_name
                     FROM base_users u
                     LEFT JOIN base_admin_statuses s ON s.ID = u.{$statusCol}
                     WHERE u.id = ? LIMIT 1"
                );
            } else {
                $su = Database::pdo()->prepare('SELECT name FROM base_users WHERE id = ? LIMIT 1');
            }
            $su->execute([$adminId]);
            $u = $su->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $operatorName = (string)($u['name'] ?? '');
                $positionName = (string)($u['status_name'] ?? '');
            }
        }

        return [
            'record_id' => $recordId,
            'operator_id' => $adminId,
            'operator_name' => $operatorName,
            'position_name' => $positionName,
            'dt_recorded' => (int)$row['DT_RECORDED'],
            'lines' => $lines,
            'line_timestamps' => $lineTimestamps,
        ];
    }

    /** @param array{lines?:list<string>,line_timestamps?:list<string>} $payload */
    public static function transcriptFromPayload(array $payload): string
    {
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $tsCodes = is_array($payload['line_timestamps'] ?? null) ? $payload['line_timestamps'] : [];
        $parts = [];
        foreach ($lines as $i => $line) {
            $text = trim((string)$line);
            if ($text === '') {
                continue;
            }
            $ts = isset($tsCodes[$i]) && $tsCodes[$i] !== '' ? trim((string)$tsCodes[$i]) . ' ' : '';
            $parts[] = $ts . $text;
        }
        return implode("\n", $parts);
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

    /**
     * @return array<int,list<array{pos:int,len:int}>>
     */
    private static function reviewInstancesByLine(int $recordId): array
    {
        if (!Database::tableExists('base_records_instances')
            || !Database::tableExists('base_records_keys')
            || !Database::tableExists('base_records_dictonarys')
        ) {
            return [];
        }
        $dicIds = [];
        $sd = Database::pdo()->query(
            'SELECT ID FROM base_records_dictonarys WHERE IS_REVIEW_REQ > 0 AND (DISABLED IS NULL OR DISABLED < 1)'
        );
        if ($sd) {
            while ($d = $sd->fetch(PDO::FETCH_ASSOC)) {
                $dicIds[] = (int)$d['ID'];
            }
        }
        if ($dicIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $dicIds));
        $st = Database::pdo()->prepare(
            "SELECT i.STRING_NUM, i.STRING_POSITION, i.LENGTH
             FROM base_records_instances i
             INNER JOIN base_records_keys k ON k.ID = i.ID_KEY
             WHERE i.ID_RECORD = ? AND k.ID_DICTONARY IN ({$in}) AND (k.DISABLED IS NULL OR k.DISABLED < 1)"
        );
        $st->execute([$recordId]);
        $by = [];
        while ($inst = $st->fetch(PDO::FETCH_ASSOC)) {
            $rawNum = (int)$inst['STRING_NUM'];
            if ($rawNum < 1) {
                continue;
            }
            $by[$rawNum][] = [
                'pos' => (int)$inst['STRING_POSITION'],
                'len' => (int)$inst['LENGTH'],
            ];
        }
        return $by;
    }
}
