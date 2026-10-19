<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Phase 4: LLM proposals for keys / new dicts. */
final class CoachLlm
{
    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $facts
     * @param array<string,mixed> $windows
     * @return array{proposals:list<array<string,mixed>>,raw:list<array<string,mixed>>,errors:list<string>}
     */
    public static function build(array $catalog, array $facts, array $windows): array
    {
        $out = ['proposals' => [], 'raw' => [], 'errors' => []];
        if (!class_exists('LlmClient', false)) {
            $out['errors'][] = t('audio_script_coach.err.llm_missing');

            return $out;
        }

        $factsByScript = [];
        foreach (is_array($facts['scripts'] ?? null) ? $facts['scripts'] : [] as $sf) {
            $factsByScript[(int)$sf['id']] = $sf;
        }
        $windowsByScript = [];
        foreach (is_array($windows['windows'] ?? null) ? $windows['windows'] : [] as $w) {
            $sid = (int)($w['script_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $windowsByScript[$sid][] = $w;
        }

        $dicts = is_array($catalog['dicts'] ?? null) ? $catalog['dicts'] : [];
        $seen = [];

        foreach (is_array($catalog['scripts'] ?? null) ? $catalog['scripts'] : [] as $script) {
            $sid = (int)($script['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $sf = $factsByScript[$sid] ?? null;
            if (!is_array($sf)) {
                continue;
            }
            $needsWork = !empty($sf['silent']) || !empty($sf['trigger_gap']) || !empty($sf['weak_start'])
                || (is_array($sf['incomplete'] ?? null) && $sf['incomplete'] !== []);
            if (!$needsWork) {
                continue;
            }
            $scriptWindows = $windowsByScript[$sid] ?? [];
            if ($scriptWindows === []) {
                continue;
            }

            $payload = self::buildPromptPayload($script, $sf, $scriptWindows, $dicts);
            $res = LlmClient::complete([
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user', 'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ], [
                'json' => true,
                'temperature' => 0.2,
                'max_tokens' => 2500,
                'timeout' => 120,
            ]);
            $out['raw'][] = [
                'script_id' => $sid,
                'ok' => !empty($res['ok']),
                'error' => (string)($res['error'] ?? ''),
                'json' => $res['json'] ?? null,
            ];
            if (empty($res['ok']) || !is_array($res['json'])) {
                $out['errors'][] = t('audio_script_coach.err.llm_script', [
                    'id' => (string)$sid,
                    'error' => (string)($res['error'] ?? 'fail'),
                ]);
                continue;
            }
            $proposals = $res['json']['proposals'] ?? null;
            if (!is_array($proposals)) {
                continue;
            }
            foreach ($proposals as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $normalized = self::normalizeProposal($p, $script, $dicts);
                if ($normalized === null) {
                    continue;
                }
                $fp = $normalized['action'] . '|' . $normalized['id_dict'] . '|'
                    . $normalized['new_dict_name'] . '|' . implode(';', $normalized['phrases']);
                if (isset($seen[$fp])) {
                    continue;
                }
                $seen[$fp] = true;
                $out['proposals'][] = $normalized;
            }
        }

        return $out;
    }

    private static function systemPrompt(): string
    {
        return 'You enrich ASR keyword dictionaries for spa staff recognition scripts. '
            . 'Matching is lemma-bag exact — propose short spoken phrases people actually say. '
            . 'Return JSON object {"proposals":[...]} only. '
            . 'Each proposal: action ("add_key" or "add_dict_with_keys"), '
            . 'dict_id (int, for add_key), new_dict_name (string, for add_dict_with_keys), '
            . 'part_id (int), phrases (string array, 1-8 items), rationale (short), confidence (0-1), '
            . 'link_part (bool — attach new dict to the part). '
            . 'Prefer add_key into an existing dict when synonyms fit. '
            . 'Use add_dict_with_keys only when a new detection concept is needed. '
            . 'Do not invent script structure changes. Russian phrases OK. No markdown.';
    }

    /**
     * @param array<string,mixed> $script
     * @param array<string,mixed> $facts
     * @param list<array<string,mixed>> $scriptWindows
     * @param array<string,array<string,mixed>> $dicts
     * @return array<string,mixed>
     */
    private static function buildPromptPayload(
        array $script,
        array $facts,
        array $scriptWindows,
        array $dicts
    ): array {
        $partsCompact = [];
        $usedDicts = [];
        foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
            $partsCompact[] = [
                'id' => (int)$part['id'],
                'name' => (string)$part['name'],
                'role' => (string)($part['role'] ?? 'ordinary'),
                'start_gate' => (int)($part['start_gate'] ?? 0),
                'dict_ids' => $part['dict_ids'] ?? [],
            ];
            foreach (is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [] as $did) {
                $usedDicts[(int)$did] = true;
            }
        }
        $dictPayload = [];
        foreach (array_keys($usedDicts) as $did) {
            $d = $dicts[(string)$did] ?? null;
            if (!is_array($d)) {
                continue;
            }
            $dictPayload[] = [
                'id' => (int)$d['id'],
                'name' => (string)$d['name'],
                'sample_keys' => array_slice(is_array($d['sample_keys'] ?? null) ? $d['sample_keys'] : [], 0, 20),
            ];
        }
        $winCompact = [];
        foreach ($scriptWindows as $w) {
            $winCompact[] = [
                'kind' => (string)($w['kind'] ?? ''),
                'part_ids' => $w['part_ids'] ?? [],
                'lines' => array_slice(is_array($w['lines'] ?? null) ? $w['lines'] : [], 0, 30),
            ];
        }

        return [
            'task' => 'Suggest dictionary enrichments so this script can detect staff phrases next time.',
            'script' => [
                'id' => (int)$script['id'],
                'name' => (string)$script['name'],
                'purpose' => 'Script named "' . (string)$script['name']
                    . '". Parts with role=trigger start a session; finish closes it; killer aborts.',
                'parts' => $partsCompact,
            ],
            'facts' => [
                'starts' => (int)($facts['starts'] ?? 0),
                'finishes' => (int)($facts['finishes'] ?? 0),
                'silent' => !empty($facts['silent']),
                'trigger_gap' => !empty($facts['trigger_gap']),
                'weak_start' => !empty($facts['weak_start']),
                'trigger_parts' => $facts['trigger_parts'] ?? [],
                'incomplete_count' => is_array($facts['incomplete'] ?? null) ? count($facts['incomplete']) : 0,
                'note' => !empty($facts['weak_start'])
                    ? 'Many key hits on start parts but few collections — AND start gates / diversity may need more phrase variants on the weaker part.'
                    : '',
            ],
            'dictionaries' => $dictPayload,
            'transcript_windows' => $winCompact,
        ];
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $script
     * @param array<string,array<string,mixed>> $dicts
     * @return array<string,mixed>|null
     */
    private static function normalizeProposal(array $p, array $script, array $dicts): ?array
    {
        $action = trim((string)($p['action'] ?? ''));
        if ($action !== 'add_key' && $action !== 'add_dict_with_keys') {
            return null;
        }
        $phrases = [];
        foreach (is_array($p['phrases'] ?? null) ? $p['phrases'] : [] as $ph) {
            $ph = trim((string)$ph);
            if ($ph === '' || mb_strlen($ph, 'UTF-8') < 2) {
                continue;
            }
            // Same gate as manual key create: any overlap (exact/contains/contained) → skip
            if (class_exists('CoachPhraseCheck', false) && CoachPhraseCheck::hasOverlap($ph)) {
                continue;
            }
            $phrases[] = mb_substr($ph, 0, 255);
            if (count($phrases) >= 8) {
                break;
            }
        }
        if ($phrases === []) {
            return null;
        }

        $partId = max(0, (int)($p['part_id'] ?? 0));
        $validPart = false;
        $partDictIds = [];
        foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
            if ((int)$part['id'] === $partId) {
                $validPart = true;
                $partDictIds = is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [];
                break;
            }
        }
        if ($partId > 0 && !$validPart) {
            $partId = 0;
        }

        $dictId = max(0, (int)($p['dict_id'] ?? 0));
        $newName = mb_substr(trim((string)($p['new_dict_name'] ?? '')), 0, 100);

        if ($action === 'add_key') {
            if ($dictId < 1 && $partDictIds !== []) {
                $dictId = (int)$partDictIds[0];
            }
            if ($dictId < 1 || !isset($dicts[(string)$dictId])) {
                return null;
            }
            $newName = '';
        } else {
            $dictId = 0;
            if ($newName === '') {
                $newName = mb_substr((string)($script['name'] ?? 'dict') . ' / detect', 0, 100);
            }
        }

        return [
            'id_script' => (int)$script['id'],
            'id_part' => $partId,
            'action' => $action,
            'id_dict' => $dictId,
            'new_dict_name' => $newName,
            'phrases' => $phrases,
            'rationale' => mb_substr(trim((string)($p['rationale'] ?? '')), 0, 500),
            'confidence' => max(0.0, min(1.0, (float)($p['confidence'] ?? 0.5))),
            'link_part' => !empty($p['link_part']) ? 1 : ($action === 'add_dict_with_keys' ? 1 : 0),
        ];
    }
}
