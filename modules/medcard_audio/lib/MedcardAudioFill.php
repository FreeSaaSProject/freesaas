<?php
declare(strict_types=1);

/**
 * After ASR: ensure visit sheet → LLM extract fields → write (audio_bot) or propose → notify.
 */
final class MedcardAudioFill
{
    /** Field types the model may fill (skip staff pickers / talk segments). */
    private const LLM_TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'select', 'multiselect'];

    /**
     * @param array{platform:string,chat_id:string,token:string,user_id:int,event_id:int,transcript:string} $job
     * @return array{ok:bool,instance_id:int,filled:int,error:string,url:string}
     */
    public static function fromTranscript(array $job): array
    {
        $out = ['ok' => false, 'instance_id' => 0, 'filled' => 0, 'error' => '', 'url' => ''];
        $eventId = (int)($job['event_id'] ?? 0);
        $uid = (int)($job['user_id'] ?? 0);
        $text = trim((string)($job['transcript'] ?? ''));
        if ($eventId < 1 || $text === '') {
            $out['error'] = 'missing event or transcript';
            return $out;
        }
        if (!class_exists('EventsRepository', false) || !class_exists('DocsSpawner', false)
            || !class_exists('DocsInstancesRepository', false)
        ) {
            $out['error'] = 'docs/calendar not loaded';
            return $out;
        }
        $ev = EventsRepository::find($eventId);
        if ($ev === null || !empty($ev['is_block'])) {
            $out['error'] = t('medcard_audio.bot.event_missing');
            return $out;
        }
        if ($uid > 0 && (int)($ev['id_operator'] ?? 0) !== $uid) {
            $out['error'] = t('medcard_audio.bot.event_missing');
            return $out;
        }

        DocsSpawner::ensureForEvent($eventId);
        $inst = self::resolveVisitSheet($eventId);
        if ($inst === null) {
            $out['error'] = t('medcard_audio.bot.no_sheet');
            return $out;
        }
        $instanceId = (int)$inst['id'];
        $full = DocsInstancesRepository::find($instanceId);
        if ($full === null || in_array($full['status'], ['printed', 'void'], true)) {
            $out['error'] = t('medcard_audio.bot.no_sheet');
            return $out;
        }

        $ver = DocsDefsRepository::version((int)$full['id_version']);
        if ($ver === null) {
            $out['error'] = 'document version missing';
            return $out;
        }
        $fieldMap = DocsSchema::fieldMap($ver['schema']);
        $extractable = self::extractableFields($fieldMap);
        if ($extractable === []) {
            $out['error'] = 'no extractable fields';
            return $out;
        }

        $llm = self::callLlm($text, $extractable, $ev, $full);
        if (!$llm['ok']) {
            $out['error'] = $llm['error'];
            return $out;
        }
        $fields = $llm['fields'];
        if ((int)($ev['id_operator'] ?? 0) > 0 && isset($fieldMap['doctor_id'])) {
            $fields['doctor_id'] = (int)$ev['id_operator'];
        }
        if ($fields === []) {
            $out['error'] = 'llm returned no fields';
            return $out;
        }

        $confidence = $llm['confidence'];
        $prov = [
            'confidence' => $confidence,
            'event_id' => $eventId,
            'via' => 'medcard_audio',
        ];
        $mode = MedcardAudioConfig::fillMode();
        $filled = 0;
        if ($mode === MedcardAudioConfig::FILL_PROPOSE && class_exists('DocsProposals', false)) {
            $res = DocsProposals::propose([
                'instance_id' => $instanceId,
                'fields' => $fields,
                'source' => 'audio_bot',
                'confidence' => $confidence,
            ]);
            if (!$res['ok']) {
                $out['error'] = $res['error'] !== '' ? $res['error'] : 'propose failed';
                return $out;
            }
            $filled = (int)$res['proposed'];
            $instanceId = (int)$res['instance_id'] > 0 ? (int)$res['instance_id'] : $instanceId;
        } else {
            $changed = DocsInstancesRepository::updateFields($instanceId, $fields, 'audio_bot', $prov);
            $filled = count($changed);
        }

        // Doctor → clinic fill templates for fields the model left empty.
        if (class_exists('DocsFillTemplates', false) && DocsFillTemplates::tableReady()) {
            try {
                $gap = DocsFillTemplates::applyGaps($instanceId, (int)($ev['id_operator'] ?? 0));
                $filled += (int)$gap['filled'];
            } catch (Throwable $e) {
                // LLM write already succeeded; gaps are best-effort.
            }
        }

        $out['ok'] = true;
        $out['instance_id'] = $instanceId;
        $out['filled'] = $filled;
        $out['url'] = self::editUrl($instanceId);
        return $out;
    }

    /**
     * Prefer specialty visit sheet (def_code !== medcard_visit) over generic.
     *
     * @return array<string,mixed>|null meta row from listForEvent
     */
    public static function resolveVisitSheet(int $eventId): ?array
    {
        $generic = null;
        foreach (DocsInstancesRepository::listForEvent($eventId) as $row) {
            if ((string)($row['kind'] ?? '') !== 'medcard_visit') {
                continue;
            }
            if (in_array((string)($row['status'] ?? ''), ['printed', 'void'], true)) {
                continue;
            }
            $code = (string)($row['def_code'] ?? '');
            if ($code !== '' && $code !== 'medcard_visit') {
                return $row;
            }
            if ($generic === null) {
                $generic = $row;
            }
        }
        return $generic;
    }

    /**
     * @param array<string,array<string,mixed>> $fieldMap
     * @return array<string,array<string,mixed>>
     */
    private static function extractableFields(array $fieldMap): array
    {
        $out = [];
        foreach ($fieldMap as $id => $f) {
            $type = (string)($f['type'] ?? 'text');
            if ($id === 'doctor_id' || $id === 'talk_segments') {
                continue;
            }
            if (!in_array($type, self::LLM_TYPES, true)) {
                continue;
            }
            $out[$id] = $f;
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $extractable
     * @param array<string,mixed> $ev
     * @param array<string,mixed> $instance
     * @return array{ok:bool,fields:array<string,mixed>,confidence:float,error:string}
     */
    private static function callLlm(string $transcript, array $extractable, array $ev, array $instance): array
    {
        $empty = ['ok' => false, 'fields' => [], 'confidence' => 0.0, 'error' => ''];
        if (!class_exists('LlmClient', false) || !class_exists('LlmConfig', false) || !LlmConfig::hasReadyProfile()) {
            $empty['error'] = t('medcard_audio.bot.llm_missing');
            return $empty;
        }

        $schemaForPrompt = [];
        foreach ($extractable as $id => $f) {
            $entry = [
                'id' => $id,
                'type' => (string)($f['type'] ?? 'text'),
                'label' => (string)($f['label'] ?? $id),
                'required' => !empty($f['required']),
            ];
            if (in_array($entry['type'], ['select', 'multiselect'], true)) {
                $opts = [];
                foreach ((array)($f['options'] ?? []) as $o) {
                    if (!is_array($o)) {
                        continue;
                    }
                    $v = trim((string)($o['value'] ?? ''));
                    if ($v === '') {
                        continue;
                    }
                    $opts[] = ['value' => $v, 'label' => (string)($o['label'] ?? $v)];
                }
                $entry['options'] = $opts;
            }
            $schemaForPrompt[] = $entry;
        }

        $patient = trim((string)($ev['name'] ?? ''));
        if ($patient === '' && class_exists('ClientsRepository', false) && (int)($ev['id_user'] ?? 0) > 0) {
            $c = ClientsRepository::find((int)$ev['id_user']);
            $patient = is_array($c) ? trim((string)($c['name'] ?? '')) : '';
        }
        $when = '';
        if ((int)($ev['dtstart'] ?? 0) > 0) {
            $when = date('Y-m-d H:i', (int)$ev['dtstart']);
        }

        $system = 'You are a clinical documentation assistant. Extract structured fields for an outpatient visit sheet from a doctor–patient conversation transcript. '
            . 'Return ONLY a JSON object: {"fields":{field_id:value,...},"confidence":0.0-1.0}. '
            . 'Use only the listed field ids. Omit fields with no evidence. '
            . 'For date use YYYY-MM-DD. For checkbox use 0 or 1. For select use an allowed option value. '
            . 'For multiselect use an array of allowed values. Write clinical notes in the transcript language. '
            . 'Do not invent diagnoses, drugs, or numbers that were not discussed.';

        $user = "Visit: {$when}\nPatient: {$patient}\nDocument: " . (string)($instance['def_code'] ?? '') . ' / ' . (string)($instance['def_name'] ?? '')
            . "\n\nFields schema:\n" . (string)json_encode($schemaForPrompt, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nTranscript:\n" . mb_substr($transcript, 0, 100000);

        $opts = [
            'json' => true,
            'temperature' => 0.2,
            'max_tokens' => MedcardAudioConfig::maxTokens(),
            'timeout' => 180,
        ];
        $profile = MedcardAudioConfig::llmProfile();
        if ($profile !== '') {
            $opts['profile'] = $profile;
        }

        $res = LlmClient::complete([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $opts);
        if (!$res['ok'] || !is_array($res['json'])) {
            $empty['error'] = $res['error'] !== '' ? $res['error'] : 'llm failed';
            return $empty;
        }
        $json = $res['json'];
        $rawFields = is_array($json['fields'] ?? null) ? $json['fields'] : $json;
        if (isset($rawFields['fields']) && is_array($rawFields['fields'])) {
            $rawFields = $rawFields['fields'];
        }
        $fields = [];
        foreach ($rawFields as $fid => $val) {
            $fid = (string)$fid;
            if (!isset($extractable[$fid]) && $fid !== 'doctor_id') {
                continue;
            }
            if ($fid === 'fields' || $fid === 'confidence') {
                continue;
            }
            $fields[$fid] = $val;
        }
        $conf = isset($json['confidence']) ? (float)$json['confidence'] : MedcardAudioConfig::confidenceDefault();
        if ($conf < 0.0) {
            $conf = 0.0;
        }
        if ($conf > 1.0) {
            $conf = 1.0;
        }
        return ['ok' => true, 'fields' => $fields, 'confidence' => $conf, 'error' => ''];
    }

    public static function editUrl(int $instanceId): string
    {
        $path = '/documents/' . max(0, $instanceId) . '/edit';
        $base = rtrim(trim((string)Config::get('app', 'base_url', '')), '/');
        return $base !== '' ? $base . $path : $path;
    }

    /**
     * @param array<string,mixed> $ctx bot reply context (platform, chat_id, token)
     * @param array<string,mixed> $ev
     * @param array{ok:bool,instance_id:int,filled:int,error:string,url:string} $result
     */
    public static function notify(array $ctx, array $ev, array $result): void
    {
        $token = (string)($ctx['token'] ?? '');
        $chatId = (string)($ctx['chat_id'] ?? '');
        $platform = (string)($ctx['platform'] ?? 'telegram');
        if ($token === '' || $chatId === '') {
            return;
        }
        $markup = ['inline_keyboard' => [[
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ]]];
        if (!$result['ok']) {
            $msg = t('medcard_audio.bot.fail', ['error' => (string)$result['error']]);
            BotClient::sendKeepMessage($platform, $token, $chatId, $msg, $markup);
            return;
        }
        $name = trim((string)($ev['client_name'] ?? $ev['name'] ?? ''));
        if ($name === '') {
            $name = '#' . (int)($ev['id_user'] ?? 0);
        }
        $time = (int)($ev['dtstart'] ?? 0) > 0 ? date('H:i', (int)$ev['dtstart']) : '—';
        $msg = t('medcard_audio.bot.done', [
            'name' => $name,
            'time' => $time,
            'url' => (string)$result['url'],
        ]);
        if ((int)$result['filled'] > 0) {
            $msg .= "\n" . t('medcard_audio.bot.done_fields', ['n' => (int)$result['filled']]);
        }
        BotClient::sendKeepMessage($platform, $token, $chatId, $msg, $markup);
    }
}
