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
     * @return array{
     *   proposals:list<array<string,mixed>>,
     *   raw:list<array<string,mixed>>,
     *   errors:list<string>,
     *   filter:array<string,mixed>
     * }
     */
    public static function build(array $catalog, array $facts, array $windows): array
    {
        $filter = self::emptyFilterStats();
        $out = ['proposals' => [], 'raw' => [], 'errors' => [], 'filter' => $filter];
        $llmReady = class_exists('ModuleManager', false) && ModuleManager::isEnabled('llm')
            && class_exists('LlmClient', false)
            && class_exists('LlmConfig', false)
            && LlmConfig::hasReadyProfile();
        if (!$llmReady) {
            $out['errors'][] = t('audio_script_coach.err.llm_missing');
            $filter['skip_reason'] = 'llm_missing';
            $out['filter'] = $filter;

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
        $scriptsNeedingWork = 0;
        $scriptsMissingWindows = 0;
        $scriptsSentToLlm = 0;

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
                || (is_array($sf['missed_starts'] ?? null) && $sf['missed_starts'] !== [])
                || (is_array($sf['incomplete'] ?? null) && $sf['incomplete'] !== [])
                || (is_array($sf['stage_gaps'] ?? null) && $sf['stage_gaps'] !== [])
                || !empty($sf['force_enrich']);
            if (!$needsWork) {
                continue;
            }
            $scriptsNeedingWork++;
            $scriptWindows = $windowsByScript[$sid] ?? [];
            if ($scriptWindows === []) {
                $scriptsMissingWindows++;
                continue;
            }
            $scriptsSentToLlm++;

            $payload = self::buildPromptPayload($script, $sf, $scriptWindows, $dicts);
            $system = self::systemPrompt();
            $userJson = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
            $approxIn = self::approxTokens($system) + self::approxTokens($userJson);
            if (class_exists('CoachProgress', false) && CoachProgress::enabled()) {
                CoachProgress::note(t('audio_script_coach.progress.llm_send', [
                    'id' => (string)$sid,
                    'name' => (string)($script['name'] ?? ('#' . $sid)),
                    'chars' => (string)(mb_strlen($system) + mb_strlen($userJson)),
                    'approx' => (string)$approxIn,
                    'windows' => (string)count($scriptWindows),
                ]));
            }
            $res = LlmClient::complete([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userJson],
            ], [
                'json' => true,
                'temperature' => 0.2,
                'max_tokens' => 8000,
                'timeout' => 120,
            ]);
            $usage = is_array($res['usage'] ?? null) ? $res['usage'] : [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];
            $out['raw'][] = [
                'script_id' => $sid,
                'ok' => !empty($res['ok']),
                'error' => (string)($res['error'] ?? ''),
                'json' => $res['json'] ?? null,
                'usage' => $usage,
                'approx_prompt_tokens' => $approxIn,
            ];
            if (class_exists('CoachProgress', false) && CoachProgress::enabled()) {
                $pt = (int)($usage['prompt_tokens'] ?? 0);
                $ct = (int)($usage['completion_tokens'] ?? 0);
                $tt = (int)($usage['total_tokens'] ?? 0);
                if ($pt < 1 && $ct < 1) {
                    CoachProgress::note(t('audio_script_coach.progress.llm_recv_approx', [
                        'id' => (string)$sid,
                        'approx_in' => (string)$approxIn,
                        'out_chars' => (string)mb_strlen((string)($res['text'] ?? '')),
                        'ok' => !empty($res['ok']) ? '1' : '0',
                    ]), !empty($res['ok']) ? '#e2e8f0' : '#fca5a5');
                } else {
                    CoachProgress::note(t('audio_script_coach.progress.llm_recv', [
                        'id' => (string)$sid,
                        'in' => (string)$pt,
                        'out' => (string)$ct,
                        'total' => (string)$tt,
                        'ok' => !empty($res['ok']) ? '1' : '0',
                    ]), !empty($res['ok']) ? '#86efac' : '#fca5a5');
                }
            }
            if (empty($res['ok']) || !is_array($res['json'])) {
                $out['errors'][] = t('audio_script_coach.err.llm_script', [
                    'id' => (string)$sid,
                    'error' => (string)($res['error'] ?? 'fail'),
                ]);
                continue;
            }
            $proposals = $res['json']['proposals'] ?? null;
            if (!is_array($proposals)) {
                $filter['proposals_dropped']['invalid_payload']++;
                continue;
            }
            foreach ($proposals as $p) {
                $filter['proposals_raw']++;
                if (!is_array($p)) {
                    $filter['proposals_dropped']['not_object']++;
                    continue;
                }
                $normalized = self::normalizeProposal($p, $script, $dicts, $filter);
                if ($normalized === null) {
                    continue;
                }
                $fp = $normalized['action'] . '|' . $normalized['id_dict'] . '|'
                    . $normalized['new_dict_name'] . '|' . implode(';', $normalized['phrases']);
                if (isset($seen[$fp])) {
                    $filter['proposals_dropped']['duplicate']++;
                    $filter['phrases_dropped']['duplicate_proposal'] += count($normalized['phrases']);
                    continue;
                }
                $seen[$fp] = true;
                $filter['proposals_kept']++;
                $filter['phrases_kept'] += count($normalized['phrases']);
                $out['proposals'][] = $normalized;
            }
        }

        if ($scriptsSentToLlm < 1) {
            if ($scriptsNeedingWork < 1) {
                $filter['skip_reason'] = 'nothing_to_enrich';
            } elseif ($scriptsMissingWindows > 0) {
                $filter['skip_reason'] = 'no_windows';
            } else {
                $filter['skip_reason'] = 'nothing_to_enrich';
            }
        } else {
            $filter['skip_reason'] = '';
        }
        $filter['scripts_needing_work'] = $scriptsNeedingWork;
        $filter['scripts_missing_windows'] = $scriptsMissingWindows;
        $filter['scripts_sent_to_llm'] = $scriptsSentToLlm;
        $out['filter'] = $filter;

        return $out;
    }

    /**
     * @return array{
     *   phrases_raw:int,phrases_kept:int,phrases_dropped:array<string,int>,
     *   proposals_raw:int,proposals_kept:int,proposals_dropped:array<string,int>,
     *   skip_reason:string,scripts_needing_work:int,scripts_missing_windows:int,scripts_sent_to_llm:int
     * }
     */
    private static function emptyFilterStats(): array
    {
        return [
            'phrases_raw' => 0,
            'phrases_kept' => 0,
            'phrases_dropped' => [
                'empty' => 0,
                'too_short' => 0,
                'overlap' => 0, // exact only (legacy counter name)
                'overlap_exact' => 0,
                'overlap_soft_kept' => 0, // contains/contained — kept for ASR short forms
                'cap' => 0,
                'bad_action' => 0,
                'bad_dict' => 0,
                'duplicate_proposal' => 0,
            ],
            'proposals_raw' => 0,
            'proposals_kept' => 0,
            'proposals_dropped' => [
                'invalid_payload' => 0,
                'not_object' => 0,
                'bad_action' => 0,
                'no_phrases' => 0,
                'bad_dict' => 0,
                'duplicate' => 0,
            ],
            'skip_reason' => '',
            'scripts_needing_work' => 0,
            'scripts_missing_windows' => 0,
            'scripts_sent_to_llm' => 0,
        ];
    }

    private static function systemPrompt(): string
    {
        return 'You enrich ASR keyword dictionaries for spa staff recognition scripts. '
            . 'Matching is lemma-bag exact: a phrase must appear in speech as ASR wrote it. '
            . 'Each dictionary may include descr (intent) and sample_keys. '
            . 'Use descr to decide which intent a transcript line belongs to; '
            . 'use sample_keys as ASR examples of that intent. '
            . 'When a line matches the intent (descr) but wording/ASR differs from samples, '
            . 'propose add_key with the transcript form (short phrase, 2–8 words). '
            . 'Do not invent unrelated topics; stay close to descr + samples and the open windows. '
            . 'Return JSON object {"proposals":[...]} only. '
            . 'Each proposal: action ("add_key" or "add_dict_with_keys"), '
            . 'dict_id (int, for add_key), new_dict_name (string, for add_dict_with_keys), '
            . 'part_id (int), phrases (string array, 1-8 items), rationale (short), confidence (0-1), '
            . 'link_part (bool — attach new dict to the part). '
            . 'Prefer add_key into an existing dict whose samples match the intent. '
            . 'For kind=missed_start, only enrich start-gate / trigger parts. '
            . 'For kind=stage_gap, focus on the listed missed part_ids (mid/late stages). '
            . 'For kind=stage_enrich, the day is already covered — still propose ASR-faithful '
            . 'synonyms for each stage so dictionaries stay robust. '
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
        $triggerDictIds = [];
        foreach (is_array($facts['trigger_parts'] ?? null) ? $facts['trigger_parts'] : [] as $tp) {
            foreach (is_array($tp['dict_ids'] ?? null) ? $tp['dict_ids'] : [] as $did) {
                $did = (int)$did;
                if ($did > 0) {
                    $triggerDictIds[$did] = true;
                }
            }
        }
        $hasMissedStarts = is_array($facts['missed_starts'] ?? null) && $facts['missed_starts'] !== [];
        $sampleLimit = $hasMissedStarts ? 10 : 12;

        $dictPayload = [];
        foreach (array_keys($usedDicts) as $did) {
            $d = $dicts[(string)$did] ?? null;
            if (!is_array($d)) {
                continue;
            }
            $samples = is_array($d['sample_keys'] ?? null) ? $d['sample_keys'] : [];
            $dictPayload[] = [
                'id' => (int)$d['id'],
                'name' => (string)$d['name'],
                'descr' => (string)($d['descr'] ?? ''),
                'role' => isset($triggerDictIds[$did]) ? 'trigger' : 'ordinary',
                'sample_keys' => array_slice($samples, 0, $sampleLimit),
                'key_count' => (int)($d['key_count'] ?? count($samples)),
            ];
        }
        $winCompact = [];
        foreach ($scriptWindows as $w) {
            $row = [
                'kind' => (string)($w['kind'] ?? ''),
                'part_ids' => $w['part_ids'] ?? [],
                'lines' => array_slice(is_array($w['lines'] ?? null) ? $w['lines'] : [], 0, 30),
            ];
            if ((string)($w['kind'] ?? '') === 'missed_start') {
                $row['procedure_name'] = (string)($w['procedure_name'] ?? '');
            }
            if (!empty($w['hint'])) {
                $row['hint'] = (string)$w['hint'];
            }
            $winCompact[] = $row;
        }

        $hasStageGaps = is_array($facts['stage_gaps'] ?? null) && $facts['stage_gaps'] !== [];
        $forceEnrich = !empty($facts['force_enrich']);
        $task = 'Suggest dictionary enrichments so this script can detect staff phrases next time.';
        if ($hasMissedStarts) {
            $task = 'Paid procedures have audio but no start-gate dictionary hits nearby. '
                . 'For each trigger dictionary, use sample_keys as the intended phrases. '
                . 'In missed_start transcript windows, find lines that mean the same as a sample '
                . 'but were written differently by ASR (truncation, wrong consonants, glued words, '
                . 'missing particles). Propose those ASR forms as add_key into the matching trigger dict. '
                . 'Skip lines that do not correspond to any sample intent.';
        } elseif (!empty($facts['weak_start'])) {
            $task = 'Start-gate keys fire often but few collections open. '
                . 'Propose phrase variants so both start-gate parts can match in the same talk '
                . '(compare windows to sample_keys; prefer ASR-faithful forms).';
        } elseif ($hasStageGaps) {
            $task = 'Some finished sessions still miss mid/late script stages. '
                . 'For stage_gap windows, enrich dictionaries of the listed missed parts: '
                . 'find transcript lines that match sample intent but differ in ASR wording.';
        } elseif ($forceEnrich) {
            $task = 'The day looks covered (starts and finishes OK). Still mine stage_enrich windows '
                . 'for ASR-faithful synonyms that strengthen each stage dictionary. '
                . 'Stay close to sample_keys; skip unrelated chatter.';
        }

        return [
            'task' => $task,
            'script' => [
                'id' => (int)$script['id'],
                'name' => (string)$script['name'],
                'purpose' => 'Script named "' . (string)$script['name']
                    . '". trigger starts a session; conversion marks saveable mid-flow; '
                    . 'closer saves and closes; breaker reopens on repeat start; killer aborts.',
                'parts' => $partsCompact,
            ],
            'facts' => [
                'starts' => (int)($facts['starts'] ?? 0),
                'finishes' => (int)($facts['finishes'] ?? 0),
                'silent' => !empty($facts['silent']),
                'trigger_gap' => !empty($facts['trigger_gap']),
                'weak_start' => !empty($facts['weak_start']),
                'force_enrich' => $forceEnrich,
                'trigger_parts' => $facts['trigger_parts'] ?? [],
                'missed_starts' => array_slice(
                    is_array($facts['missed_starts'] ?? null) ? $facts['missed_starts'] : [],
                    0,
                    8
                ),
                'incomplete_count' => is_array($facts['incomplete'] ?? null) ? count($facts['incomplete']) : 0,
                'stage_gaps_count' => $hasStageGaps ? count($facts['stage_gaps']) : 0,
            ],
            'dictionaries' => $dictPayload,
            'transcript_windows' => $winCompact,
        ];
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $script
     * @param array<string,array<string,mixed>> $dicts
     * @param array<string,mixed> $filter
     * @return array<string,mixed>|null
     */
    private static function normalizeProposal(array $p, array $script, array $dicts, array &$filter): ?array
    {
        $action = trim((string)($p['action'] ?? ''));
        if ($action !== 'add_key' && $action !== 'add_dict_with_keys') {
            $filter['proposals_dropped']['bad_action']++;
            $rawPh = is_array($p['phrases'] ?? null) ? $p['phrases'] : [];
            $filter['phrases_raw'] += count($rawPh);
            $filter['phrases_dropped']['bad_action'] += count($rawPh);

            return null;
        }
        $phrases = [];
        $rawList = is_array($p['phrases'] ?? null) ? $p['phrases'] : [];
        foreach ($rawList as $ph) {
            $filter['phrases_raw']++;
            $ph = trim((string)$ph);
            if ($ph === '') {
                $filter['phrases_dropped']['empty']++;
                continue;
            }
            if (mb_strlen($ph, 'UTF-8') < 2) {
                $filter['phrases_dropped']['too_short']++;
                continue;
            }
            // Drop exact duplicates only. Soft overlaps (existing key wider = "contains")
            // must stay: speech often uses the short form that long keys never match.
            if (class_exists('CoachPhraseCheck', false) && CoachPhraseCheck::hasExactOverlap($ph)) {
                $filter['phrases_dropped']['overlap']++;
                $filter['phrases_dropped']['overlap_exact']++;
                continue;
            }
            if (class_exists('CoachPhraseCheck', false) && CoachPhraseCheck::hasOverlap($ph)) {
                $filter['phrases_dropped']['overlap_soft_kept']++;
            }
            if (count($phrases) >= 8) {
                $filter['phrases_dropped']['cap']++;
                continue;
            }
            $phrases[] = mb_substr($ph, 0, 255);
        }
        if ($phrases === []) {
            $filter['proposals_dropped']['no_phrases']++;

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
                $filter['proposals_dropped']['bad_dict']++;
                $filter['phrases_dropped']['bad_dict'] += count($phrases);

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

    /** Rough token estimate (~3 chars/token) when the API omits usage. */
    private static function approxTokens(string $text): int
    {
        $len = mb_strlen($text);
        if ($len < 1) {
            return 0;
        }

        return (int)ceil($len / 3);
    }
}
