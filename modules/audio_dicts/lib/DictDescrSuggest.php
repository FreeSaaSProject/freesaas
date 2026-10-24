<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** LLM intent blurb for a dictionary from its key samples. Soft-depends on llm. */
final class DictDescrSuggest
{
    private const SAMPLE_KEYS = 40;

    /** True when llm module is on and has a usable profile (safe to call suggest). */
    public static function isReady(): bool
    {
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('llm')) {
            return false;
        }
        if (!class_exists('LlmClient', false) || !class_exists('LlmConfig', false)) {
            return false;
        }

        return LlmConfig::hasReadyProfile();
    }

    /**
     * @return array{ok:bool,descr:string,error:string,samples:int}
     */
    public static function suggest(int $dictId): array
    {
        $out = ['ok' => false, 'descr' => '', 'error' => '', 'samples' => 0];
        if ($dictId < 1) {
            $out['error'] = t('audio_dicts.err.not_found', ['id' => (string)$dictId]);

            return $out;
        }
        if (!self::isReady()) {
            $out['error'] = t('audio_dicts.dicts.suggest_llm_missing');

            return $out;
        }
        $dict = RecordsDictsRepository::find($dictId);
        if ($dict === null) {
            $out['error'] = t('audio_dicts.err.not_found', ['id' => (string)$dictId]);

            return $out;
        }
        $samples = self::sampleKeys($dictId);
        $out['samples'] = count($samples);
        if ($samples === []) {
            $out['error'] = t('audio_dicts.dicts.suggest_no_keys');

            return $out;
        }

        $payload = [
            'task' => 'Write a short intent description for an ASR keyword dictionary. '
                . 'Describe what staff speech this dictionary should catch (spa/massage reception context). '
                . 'Use the sample keys as evidence of intent; do not list the keys back. '
                . 'One or two sentences, Russian, max ' . RecordsDictsRepository::DESCR_MAX . ' characters. '
                . 'No quotes, no markdown, no bullet list.',
            'dictionary' => [
                'id' => $dictId,
                'name' => (string)($dict['name'] ?? ''),
                'current_descr' => (string)($dict['descr'] ?? ''),
            ],
            'sample_keys' => $samples,
        ];
        $res = LlmClient::complete([
            ['role' => 'system', 'content' => 'You write concise dictionary intent blurbs for spa audio recognition. '
                . 'Return JSON only: {"descr":"..."}. descr must be <= ' . RecordsDictsRepository::DESCR_MAX . ' characters.'],
            ['role' => 'user', 'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ], [
            'json' => true,
            'temperature' => 0.2,
            'max_tokens' => 400,
            'timeout' => 60,
        ]);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            $out['error'] = (string)($res['error'] ?? t('audio_dicts.dicts.suggest_failed'));

            return $out;
        }
        $descr = trim((string)($res['json']['descr'] ?? ''));
        if ($descr === '') {
            // Fallback: some models return plain text in content
            $raw = trim((string)($res['content'] ?? ''));
            $descr = $raw;
        }
        $descr = preg_replace('/\s+/u', ' ', $descr) ?? $descr;
        $descr = trim($descr, " \t\n\r\0\x0B\"'");
        if ($descr === '') {
            $out['error'] = t('audio_dicts.dicts.suggest_empty');

            return $out;
        }
        $out['descr'] = mb_substr($descr, 0, RecordsDictsRepository::DESCR_MAX);
        $out['ok'] = true;

        return $out;
    }

    /**
     * Suggest and optionally persist. Used by edit confirm and bulk.
     *
     * @return array{ok:bool,descr:string,error:string,samples:int,saved:bool,skipped:string}
     */
    public static function suggestAndSave(int $dictId, bool $overwrite = false, bool $save = true): array
    {
        $out = [
            'ok' => false,
            'descr' => '',
            'error' => '',
            'samples' => 0,
            'saved' => false,
            'skipped' => '',
        ];
        $dict = RecordsDictsRepository::find($dictId);
        if ($dict === null) {
            $out['error'] = t('audio_dicts.err.not_found', ['id' => (string)$dictId]);

            return $out;
        }
        $current = trim((string)($dict['descr'] ?? ''));
        if ($current !== '' && !$overwrite) {
            $out['skipped'] = t('audio_dicts.dicts.suggest_skip_filled');

            return $out;
        }
        $res = self::suggest($dictId);
        $out['samples'] = (int)$res['samples'];
        if (!$res['ok']) {
            $out['error'] = (string)$res['error'];

            return $out;
        }
        $out['descr'] = (string)$res['descr'];
        $out['ok'] = true;
        if ($save) {
            RecordsDictsRepository::updateDescr($dictId, $out['descr']);
            $out['saved'] = true;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function bulkSuggest(array $ids, bool $overwrite = false): array
    {
        $done = 0;
        $skipped = [];
        if (!self::isReady()) {
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $skipped[] = ['id' => $id, 'reason' => t('audio_dicts.dicts.suggest_llm_missing')];
                }
            }

            return ['done' => 0, 'skipped' => $skipped];
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            $res = self::suggestAndSave($id, $overwrite, true);
            if ($res['ok'] && !empty($res['saved'])) {
                $done++;
                continue;
            }
            $reason = (string)($res['skipped'] !== '' ? $res['skipped'] : $res['error']);
            if ($reason === '') {
                $reason = t('audio_dicts.dicts.suggest_failed');
            }
            $skipped[] = ['id' => $id, 'reason' => $reason];
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /** @return list<string> */
    private static function sampleKeys(int $dictId): array
    {
        if (!Database::tableExists('base_records_keys')) {
            return [];
        }
        $limit = self::SAMPLE_KEYS;
        $st = Database::pdo()->prepare(
            "SELECT NAME FROM base_records_keys
             WHERE ID_DICTONARY=? AND DISABLED<1
             ORDER BY ID ASC
             LIMIT {$limit}"
        );
        $st->execute([$dictId]);
        $out = [];
        while ($name = $st->fetchColumn()) {
            $name = trim((string)$name);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
