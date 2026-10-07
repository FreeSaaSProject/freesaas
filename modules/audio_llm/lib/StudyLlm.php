<?php
declare(strict_types=1);

/** Domain prompts for per-record study calls and aggregate summaries. */
final class StudyLlm
{
    public static function promptWantsFound(string $prompt): bool
    {
        return strpos($prompt, '"found"') !== false || (bool)preg_match('/\bfound\s*=/iu', $prompt);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $contextPayload
     * @return array{ok:bool,result:string,error:string}
     */
    public static function callForRecord(array $payload, string $prompt, ?array $contextPayload = null): array
    {
        $transcript = StudyPayload::transcriptFromPayload($payload);
        if ($transcript === '') {
            return ['ok' => false, 'result' => '', 'error' => 'Empty transcript'];
        }
        $recordId = (int)($payload['record_id'] ?? 0);
        $operatorName = (string)($payload['operator_name'] ?? '');
        $positionName = (string)($payload['position_name'] ?? '');
        $prompt = trim($prompt);
        $wantsFound = self::promptWantsFound($prompt);

        if ($wantsFound) {
            $systemContent =
                "Ты — аналитик расшифровок разговоров сотрудников салона/СПА с гостями.\n" .
                "Тебе дают полную транскрипцию одной записи и задание исследования.\n" .
                "Выполни задание строго по смыслу промпта пользователя.\n" .
                "Не выдумывай факты, которых нет в транскрипции.\n" .
                "Задание требует вердикт found. Ответ СТРОГО одним JSON-объектом без текста вне JSON.\n" .
                "Предпочтительный формат:\n" .
                "{ \"result\": { \"found\": true|false, \"evidence\": \"краткая цитата или не найдено\" } }\n" .
                "Допустимо также:\n" .
                "{ \"found\": true|false, \"evidence\": \"...\" }\n" .
                "или { \"result\": \"{\\\"found\\\":true,\\\"evidence\\\":\\\"...\\\"}\" }.\n" .
                "Никогда не оставляй result пустым: всегда укажи found=true или found=false.";
        } else {
            $systemContent =
                "Ты — аналитик расшифровок разговоров сотрудников салона/СПА с гостями.\n" .
                "Тебе дают полную транскрипцию одной записи и задание исследования.\n" .
                "Выполни задание строго по смыслу промпта пользователя.\n" .
                "Не выдумывай факты, которых нет в транскрипции.\n" .
                "Если по заданию ничего не найдено — верни пустую строку в поле result.\n" .
                "Ответ возвращай СТРОГО в компактном JSON без текста вне JSON:\n" .
                "{ \"result\": \"...\" }\n" .
                "Поле result — краткий ответ на русском языке по заданию, либо пустая строка.";
        }

        $userParts = [];
        if ($operatorName !== '') {
            $userParts[] = 'Employee: ' . $operatorName;
        }
        if ($positionName !== '') {
            $userParts[] = 'Position: ' . $positionName;
        }
        if ($recordId > 0) {
            $userParts[] = 'RecordId: ' . $recordId;
        }
        $userParts[] = '';
        $userParts[] = 'Study task:';
        $userParts[] = $prompt;
        $userParts[] = '';

        if (is_array($contextPayload)) {
            $prev = StudyPayload::transcriptFromPayload($contextPayload);
            if ($prev !== '') {
                $userParts[] = 'Previous record transcript (context only, do not analyze separately):';
                $userParts[] = $prev;
                $userParts[] = '';
            }
        }
        $userParts[] = 'Transcript:';
        $userParts[] = $transcript;

        $res = LlmClient::complete([
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user', 'content' => implode("\n", $userParts)],
        ], [
            'temperature' => 0.2,
            'max_tokens' => 400,
            'json' => true,
            'timeout' => 90,
        ]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'result' => '', 'error' => $res['error'] !== '' ? $res['error'] : t('audio_llm.err.llm')];
        }
        return ['ok' => true, 'result' => self::normalizeResultText($res['json']), 'error' => ''];
    }

    /**
     * @param array<string,mixed> $study
     * @param list<array{id_record?:int,result_text?:string}> $meaningfulItems
     * @return array{ok:bool,summary:string,error:string}
     */
    public static function aggregateSummary(array $study, array $meaningfulItems): array
    {
        if ($meaningfulItems === []) {
            return ['ok' => false, 'summary' => '', 'error' => 'Empty meaningful items'];
        }
        $blocks = [];
        foreach ($meaningfulItems as $item) {
            $text = trim((string)($item['result_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $recId = (int)($item['id_record'] ?? 0);
            $blocks[] = 'Record #' . ($recId > 0 ? $recId : '?') . ":\n" . $text;
        }
        if ($blocks === []) {
            return ['ok' => false, 'summary' => '', 'error' => 'No valid meaningful items'];
        }
        $title = trim((string)($study['TITLE'] ?? ''));
        $prompt = trim((string)($study['PROMPT'] ?? ''));
        $userParts = [];
        if ($title !== '') {
            $userParts[] = 'Study title: ' . $title;
        }
        $userParts[] = 'Study task:';
        $userParts[] = $prompt;
        $userParts[] = '';
        $userParts[] = 'Meaningful per-record results (' . count($blocks) . '):';
        $userParts[] = implode("\n\n", $blocks);

        $res = LlmClient::complete([
            [
                'role' => 'system',
                'content' =>
                    "Ты — аналитик результатов исследования аудиозаписей разговоров сотрудников с гостями.\n" .
                    "Тебе дают исходное задание исследования и список содержательных ответов по отдельным записям.\n" .
                    "Сформируй краткое общее саммари на русском языке:\n" .
                    "- выдели типичные причины, паттерны, повторяющиеся формулировки;\n" .
                    "- укажи количество релевантных случаев, если это уместно;\n" .
                    "- не выдумывай то, чего нет в переданных ответах;\n" .
                    "- 3–8 предложений или короткий структурированный список.\n" .
                    "Ответ возвращай СТРОГО в JSON: { \"summary\": \"...\" }",
            ],
            ['role' => 'user', 'content' => implode("\n", $userParts)],
        ], [
            'temperature' => 0.2,
            'max_tokens' => 800,
            'json' => true,
            'timeout' => 90,
        ]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'summary' => '', 'error' => $res['error'] !== '' ? $res['error'] : t('audio_llm.err.llm')];
        }
        $summary = trim((string)($res['json']['summary'] ?? ''));
        if ($summary === '') {
            return ['ok' => false, 'summary' => '', 'error' => 'Empty summary'];
        }
        return ['ok' => true, 'summary' => $summary, 'error' => ''];
    }

    /** @param array<string,mixed> $res */
    public static function normalizeResultText(array $res): string
    {
        if (isset($res['result'])) {
            if (is_array($res['result'])) {
                return (string)json_encode($res['result'], JSON_UNESCAPED_UNICODE);
            }
            $text = trim((string)$res['result']);
            if ($text !== '') {
                return $text;
            }
        }
        if (array_key_exists('found', $res)) {
            $payload = ['found' => $res['found']];
            if (isset($res['evidence'])) {
                $payload['evidence'] = $res['evidence'];
            }
            return (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        return '';
    }
}
