<?php
declare(strict_types=1);

/**
 * Shared poll walk: NEXT_QID / MULTY_OPTS / ANS_END_POLL / NEGATIVE.
 * Used by staff fill and future channels (bots, site).
 */
final class PollWalkEngine
{
    /**
     * @param array<int,array<string,mixed>> $questions from PollsRepository::buildWalkTree
     * @param array<int|string,mixed> $dataOpts
     * @return array{
     *   path:list<array{qid:int,question:array<string,mixed>,answer:mixed}>,
     *   next_qid:int,
     *   done:bool,
     *   needs_sign:bool
     * }
     */
    public static function walk(array $questions, array $dataOpts, int $firstQid): array
    {
        $path = [];
        $needsSign = false;
        $nextQid = $firstQid;
        $guard = 0;
        while ($nextQid > 0 && $guard < 100) {
            $guard++;
            if (!isset($questions[$nextQid])) {
                break;
            }
            $q = $questions[$nextQid];
            if (!array_key_exists($nextQid, $dataOpts) && !array_key_exists((string)$nextQid, $dataOpts)) {
                return [
                    'path' => $path,
                    'next_qid' => $nextQid,
                    'done' => false,
                    'needs_sign' => $needsSign,
                ];
            }
            $ansRaw = $dataOpts[$nextQid] ?? $dataOpts[(string)$nextQid];
            $path[] = [
                'qid' => $nextQid,
                'question' => $q,
                'answer' => $ansRaw,
            ];
            $branch = self::resolveNext($q, $ansRaw, $needsSign);
            $needsSign = $branch['needs_sign'];
            if ($branch['end']) {
                return [
                    'path' => $path,
                    'next_qid' => 0,
                    'done' => true,
                    'needs_sign' => $needsSign,
                ];
            }
            $nextQid = $branch['next_qid'];
        }
        return [
            'path' => $path,
            'next_qid' => 0,
            'done' => true,
            'needs_sign' => $needsSign,
        ];
    }

    /**
     * Apply posted answers for one or more questions into DATA_OPTS.
     *
     * @param array<int,array<string,mixed>> $questions
     * @param array<int|string,mixed> $dataOpts
     * @param array<string,mixed> $post
     * @return array{data_opts:array<int|string,mixed>,data_text:array<int|string,string>,needs_sign:bool}
     */
    public static function applyPost(array $questions, array $dataOpts, array $post, array $dataText = []): array
    {
        $needsSign = false;
        $answers = $post['answers'] ?? null;
        if (!is_array($answers)) {
            $answers = [];
        }
        foreach ($answers as $qidRaw => $val) {
            $qid = (int)$qidRaw;
            if ($qid < 1 || !isset($questions[$qid])) {
                continue;
            }
            $q = $questions[$qid];
            $multy = !empty($q['multy_opts']);
            if ($multy) {
                $ids = is_array($val) ? $val : [$val];
                $map = [];
                foreach ($ids as $aid) {
                    $aid = (int)$aid;
                    if ($aid > 0 && isset($q['_ANSWERS_'][$aid])) {
                        $map[$aid] = 1;
                        if (!empty($q['_ANSWERS_'][$aid]['negative'])) {
                            $needsSign = true;
                        }
                    }
                }
                if ($map !== []) {
                    $dataOpts[$qid] = $map;
                }
            } else {
                $aid = (int)$val;
                if ($aid > 0 && isset($q['_ANSWERS_'][$aid])) {
                    $dataOpts[$qid] = $aid;
                    if (!empty($q['_ANSWERS_'][$aid]['negative'])) {
                        $needsSign = true;
                    }
                }
            }
        }
        $texts = $post['texts'] ?? null;
        if (is_array($texts)) {
            foreach ($texts as $qidRaw => $text) {
                $qid = (int)$qidRaw;
                if ($qid < 1) {
                    continue;
                }
                $text = trim((string)$text);
                if ($text !== '') {
                    $dataText[$qid] = $text;
                } else {
                    unset($dataText[$qid]);
                }
            }
        }
        return [
            'data_opts' => $dataOpts,
            'data_text' => $dataText,
            'needs_sign' => $needsSign,
        ];
    }

    /**
     * @param array<string,mixed> $question
     * @param mixed $ansRaw
     * @return array{next_qid:int,end:bool,needs_sign:bool}
     */
    private static function resolveNext(array $question, $ansRaw, bool $needsSign): array
    {
        $defaultNext = (int)($question['NEXT_QID_DEFAULT'] ?? 0);
        $answers = $question['_ANSWERS_'] ?? [];
        $end = false;
        $next = $defaultNext;

        if (is_array($ansRaw)) {
            foreach ($ansRaw as $aid => $_v) {
                $aid = (int)$aid;
                if (!isset($answers[$aid])) {
                    continue;
                }
                $a = $answers[$aid];
                if (!empty($a['negative'])) {
                    $needsSign = true;
                }
                if (!empty($a['ans_end_poll'])) {
                    $end = true;
                    $next = 0;
                } elseif ((int)($a['next_qid'] ?? 0) > 0) {
                    $next = (int)$a['next_qid'];
                }
            }
        } else {
            $aid = (int)$ansRaw;
            if (isset($answers[$aid])) {
                $a = $answers[$aid];
                if (!empty($a['negative'])) {
                    $needsSign = true;
                }
                if (!empty($a['ans_end_poll'])) {
                    $end = true;
                    $next = 0;
                } elseif ((int)($a['next_qid'] ?? 0) > 0) {
                    $next = (int)$a['next_qid'];
                }
            }
        }

        return [
            'next_qid' => $end ? 0 : $next,
            'end' => $end || $next < 1,
            'needs_sign' => $needsSign,
        ];
    }

    /**
     * Human-readable answer label for history.
     *
     * @param array<string,mixed> $question
     * @param mixed $ansRaw
     */
    public static function answerLabel(array $question, $ansRaw): string
    {
        $answers = $question['_ANSWERS_'] ?? [];
        if (is_array($ansRaw)) {
            $parts = [];
            foreach ($ansRaw as $aid => $_v) {
                $aid = (int)$aid;
                if (isset($answers[$aid])) {
                    $parts[] = (string)$answers[$aid]['name'];
                }
            }
            return implode(', ', $parts);
        }
        $aid = (int)$ansRaw;
        return isset($answers[$aid]) ? (string)$answers[$aid]['name'] : (string)$ansRaw;
    }
}
