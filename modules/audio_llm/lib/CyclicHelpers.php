<?php
declare(strict_types=1);

/** Cyclic study markers, found parsing, amount verdict. */
final class CyclicHelpers
{
    public static function contractMarker(int $parentId, int $procedureId): string
    {
        return 'LLM-цикл №' . $parentId . ' проц №' . $procedureId;
    }

    public static function linksMarker(int $parentId, int $procedureId): string
    {
        return self::contractMarker($parentId, $procedureId) . ' (ссылки рук.)';
    }

    public static function contractMarkerLegacy(int $parentId, int $procedureId): string
    {
        return 'LLM-цикл #' . $parentId . ' проц #' . $procedureId;
    }

    public static function promptSuffix(): string
    {
        return "\n\nОтвет СТРОГО JSON (любой из форматов):\n"
            . "1) {\"result\":{\"found\":true|false,\"evidence\":\"...\"}}\n"
            . "2) {\"found\":true|false,\"evidence\":\"...\"}\n"
            . "Не возвращай пустой result. found=true только если условие подтверждено транскрипцией.";
    }

    /** @return bool|null */
    public static function parseFound(string $resultText)
    {
        $t = trim($resultText);
        if ($t === '') {
            return null;
        }
        $decoded = json_decode($t, true);
        if (is_array($decoded) && array_key_exists('found', $decoded)) {
            return self::boolish($decoded['found']);
        }
        if (preg_match('/\{[^{}]*"found"\s*:\s*(true|false|1|0|"true"|"false"|"да"|"нет")[^{}]*\}/iu', $t, $m)) {
            $inner = json_decode($m[0], true);
            if (is_array($inner) && array_key_exists('found', $inner)) {
                return self::boolish($inner['found']);
            }
            $raw = mb_strtolower(trim($m[1], " \t\"'"), 'UTF-8');
            if (in_array($raw, ['true', '1', 'да'], true)) {
                return true;
            }
            if (in_array($raw, ['false', '0', 'нет'], true)) {
                return false;
            }
        }
        $lower = mb_strtolower($t, 'UTF-8');
        if (preg_match('/\bfound\s*[:=]\s*(true|1|да)\b/u', $lower)) {
            return true;
        }
        if (preg_match('/\bfound\s*[:=]\s*(false|0|нет)\b/u', $lower)) {
            return false;
        }
        return null;
    }

    public static function parseEvidence(string $resultText): string
    {
        $t = trim($resultText);
        if ($t === '') {
            return '';
        }
        $decoded = json_decode($t, true);
        if (is_array($decoded) && isset($decoded['evidence'])) {
            return trim((string)$decoded['evidence']);
        }
        if (preg_match('/\{[^{}]*"evidence"\s*:\s*"((?:\\\\.|[^"\\\\])*)"[^{}]*\}/u', $t, $m)) {
            return trim(stripcslashes($m[1]));
        }
        return $t;
    }

    /**
     * @return array{amount:int,found:bool,any_found:bool,evidence:string}
     */
    public static function evalAmount(int $studyId, int $amountPos, int $amountNeg): array
    {
        $anyFound = false;
        $trueEvidence = [];
        $falseEvidence = [];
        if ($studyId > 0 && Database::tableExists('base_records_llm_study_results')) {
            $st = Database::pdo()->prepare(
                'SELECT RESULT_TEXT FROM base_records_llm_study_results WHERE ID_STUDY = ? AND STATUS = 2 ORDER BY ID ASC'
            );
            $st->execute([$studyId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $found = self::parseFound((string)$row['RESULT_TEXT']);
                $ev = trim(self::parseEvidence((string)$row['RESULT_TEXT']));
                if ($found === true) {
                    $anyFound = true;
                    if ($ev !== '' && !preg_match('/^не\s*найдено$/iu', $ev)) {
                        $trueEvidence[] = $ev;
                    }
                } elseif ($found === false) {
                    if ($ev !== '' && !preg_match('/^не\s*найдено$/iu', $ev)) {
                        $falseEvidence[] = $ev;
                    }
                }
            }
        }
        $parts = $anyFound ? $trueEvidence : $falseEvidence;
        $evidence = '';
        if ($parts !== []) {
            $evidence = $parts[0];
            if (count($parts) > 1) {
                $evidence .= ' (+' . (count($parts) - 1) . ')';
            }
        }
        return [
            'amount' => $anyFound ? $amountPos : $amountNeg,
            'found' => $anyFound,
            'any_found' => $anyFound,
            'evidence' => $evidence,
        ];
    }

    public static function studyHasParseableFound(int $studyId): bool
    {
        if ($studyId < 1 || !Database::tableExists('base_records_llm_study_results')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT RESULT_TEXT FROM base_records_llm_study_results WHERE ID_STUDY = ? AND STATUS = 2'
        );
        $st->execute([$studyId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (self::parseFound((string)$row['RESULT_TEXT']) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param mixed $v */
    private static function boolish($v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) !== 0;
        }
        $s = mb_strtolower(trim((string)$v), 'UTF-8');
        if (in_array($s, ['1', 'true', 'yes', 'да'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'нет'], true)) {
            return false;
        }
        return null;
    }
}
