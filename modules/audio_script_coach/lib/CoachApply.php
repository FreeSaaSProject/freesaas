<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Apply accepted proposals into audio_dicts. */
final class CoachApply
{
    /**
     * @param array<string,mixed> $proposal row from CoachRunsRepository::findProposal
     * @param list<string>|null $onlyPhrases null = all remaining; non-empty = selected subset
     * @return array{ok:bool,error:string,meta:array<string,mixed>,remaining:int}
     */
    public static function accept(
        array $proposal,
        int $adminId,
        int $userId,
        bool $linkPart,
        bool $force,
        ?array $onlyPhrases = null
    ): array {
        $out = ['ok' => false, 'error' => '', 'meta' => [], 'remaining' => 0];
        if ((int)($proposal['status'] ?? -1) !== CoachRunsRepository::PROP_PENDING) {
            $out['error'] = t('audio_script_coach.err.proposal_not_pending');

            return $out;
        }
        if (!class_exists('RecordsKeysRepository', false) || !class_exists('RecordsDictsRepository', false)) {
            $out['error'] = t('audio_script_coach.err.dicts_missing');

            return $out;
        }

        $action = (string)($proposal['action'] ?? '');
        $allPhrases = is_array($proposal['phrases'] ?? null) ? $proposal['phrases'] : [];
        if ($allPhrases === []) {
            $out['error'] = t('audio_script_coach.err.no_phrases');

            return $out;
        }

        $selected = self::resolveSelectedPhrases($allPhrases, $onlyPhrases);
        if ($selected === []) {
            $out['error'] = t('audio_script_coach.err.no_phrases_selected');

            return $out;
        }

        // Exact matches already exist — never create duplicates (even with force).
        $selected = self::dropExactExisting($selected);
        if ($selected === []) {
            $out['error'] = t('audio_script_coach.err.only_exact_left');

            return $out;
        }

        $annotated = CoachPhraseCheck::annotate($selected);
        $creatable = [];
        foreach ($annotated as $row) {
            // Soft "contains" (existing key is wider) is creatable without force —
            // that is the coach enrich case (short ASR form under a long sample).
            if ($row['ok'] || $force || CoachPhraseCheck::isOnlyWiderExisting($row['overlaps'] ?? [])) {
                $creatable[] = $row['name'];
            }
        }
        if ($creatable === []) {
            $out['error'] = t('audio_script_coach.err.all_overlap');
            $out['meta'] = ['keys' => self::skippedOnly($annotated)];

            return $out;
        }

        $prevMeta = is_array($proposal['apply_meta'] ?? null) ? $proposal['apply_meta'] : [];
        $prevKeys = is_array($prevMeta['keys'] ?? null) ? $prevMeta['keys'] : [];

        try {
            if ($action === 'add_key') {
                $dictId = (int)($proposal['id_dict'] ?? 0);
                if ($dictId < 1) {
                    $out['error'] = t('audio_script_coach.err.dict_required');

                    return $out;
                }
                $newKeys = self::createKeys($dictId, $annotated, $force);
                $keys = array_merge($prevKeys, $newKeys);
                $out['meta'] = [
                    'dict_id' => $dictId,
                    'keys' => $keys,
                    'created' => self::countCreated($newKeys),
                    'skipped' => self::countSkipped($newKeys),
                    'batch_keys' => $newKeys,
                ];
            } elseif ($action === 'add_dict_with_keys') {
                $dictId = (int)($prevMeta['dict_id'] ?? 0);
                $createdDict = false;
                if ($dictId < 1) {
                    $name = trim((string)($proposal['new_dict_name'] ?? ''));
                    if ($name === '') {
                        $name = 'coach';
                    }
                    $posIds = RecordsDictsRepository::adminAllowedPositionIds($adminId);
                    $idPosition = '';
                    if ($posIds !== [] && class_exists('RecordsScriptsRepository', false)) {
                        $idPosition = RecordsScriptsRepository::joinIds($posIds);
                    } elseif ($posIds !== []) {
                        $idPosition = (string)(int)$posIds[0];
                    }
                    $dictId = RecordsDictsRepository::create([
                        'name' => mb_substr($name, 0, 50),
                        'descr' => mb_substr(
                            t('audio_script_coach.apply.dict_descr'),
                            0,
                            class_exists('RecordsDictsRepository', false) ? RecordsDictsRepository::DESCR_MAX : 200
                        ),
                        'color' => '',
                        'color_background' => '',
                        'disabled' => 0,
                        'is_review_req' => 0,
                        'id_position' => $idPosition,
                        'tracking_code' => '',
                    ]);
                    if ($dictId < 1) {
                        $out['error'] = t('audio_script_coach.err.dict_create_failed');

                        return $out;
                    }
                    $createdDict = true;
                }
                $newKeys = self::createKeys($dictId, $annotated, $force);
                $keys = array_merge($prevKeys, $newKeys);
                $linked = !empty($prevMeta['linked_part']);
                $partId = (int)($proposal['id_part'] ?? 0);
                $scriptId = (int)($proposal['id_script'] ?? 0);
                if (!$linked && $linkPart && $partId > 0 && $scriptId > 0 && self::countCreated($newKeys) > 0) {
                    $linked = self::attachDictToPart($scriptId, $partId, $dictId);
                }
                $out['meta'] = [
                    'dict_id' => $dictId,
                    'keys' => $keys,
                    'created' => self::countCreated($newKeys),
                    'skipped' => self::countSkipped($newKeys),
                    'batch_keys' => $newKeys,
                    'linked_part' => $linked,
                    'part_id' => $partId,
                    'script_id' => $scriptId,
                    'created_dict' => $createdDict,
                ];
            } else {
                $out['error'] = t('audio_script_coach.err.unknown_action');

                return $out;
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();

            return $out;
        }

        if (self::countCreated($out['meta']['batch_keys'] ?? []) < 1) {
            $out['error'] = t('audio_script_coach.err.all_overlap');

            return $out;
        }

        $appliedNames = [];
        foreach (is_array($out['meta']['batch_keys'] ?? null) ? $out['meta']['batch_keys'] : [] as $k) {
            if ((int)($k['id'] ?? 0) > 0 && empty($k['skipped'])) {
                $appliedNames[(string)$k['name']] = true;
            }
        }
        $remaining = [];
        foreach ($allPhrases as $ph) {
            $ph = trim((string)$ph);
            if ($ph === '' || isset($appliedNames[$ph])) {
                continue;
            }
            // Exact duplicates are not actionable — drop from pending.
            $exact = false;
            if (class_exists('RecordsKeysRepository', false)) {
                foreach (RecordsKeysRepository::findOverlaps($ph, 0, 4) as $ov) {
                    if ((string)($ov['relation'] ?? '') === 'exact') {
                        $exact = true;
                        break;
                    }
                }
            }
            if ($exact) {
                continue;
            }
            $remaining[] = $ph;
        }
        $out['remaining'] = count($remaining);

        $persistMeta = $out['meta'];
        unset($persistMeta['batch_keys'], $persistMeta['created'], $persistMeta['skipped'], $persistMeta['created_dict']);

        if ($remaining === []) {
            CoachRunsRepository::decideProposal(
                (int)$proposal['id'],
                CoachRunsRepository::PROP_ACCEPTED,
                $userId,
                $persistMeta
            );
        } else {
            CoachRunsRepository::updateProposalProgress(
                (int)$proposal['id'],
                $remaining,
                $persistMeta
            );
        }
        $out['ok'] = true;

        return $out;
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function reject(array $proposal, int $userId): array
    {
        if ((int)($proposal['status'] ?? -1) !== CoachRunsRepository::PROP_PENDING) {
            return ['ok' => false, 'error' => t('audio_script_coach.err.proposal_not_pending')];
        }
        $prevMeta = is_array($proposal['apply_meta'] ?? null) ? $proposal['apply_meta'] : null;
        CoachRunsRepository::decideProposal(
            (int)$proposal['id'],
            CoachRunsRepository::PROP_REJECTED,
            $userId,
            $prevMeta
        );

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Close pending proposal when every remaining phrase is an exact duplicate (nothing to add/reject).
     *
     * @param array<string,mixed> $proposal
     */
    public static function closeIfNothingLeft(array $proposal, int $userId = 0): bool
    {
        if ((int)($proposal['status'] ?? -1) !== CoachRunsRepository::PROP_PENDING) {
            return false;
        }
        $phrases = is_array($proposal['phrases'] ?? null) ? $proposal['phrases'] : [];
        $exactHits = [];
        foreach ($phrases as $ph) {
            $ph = trim((string)$ph);
            if ($ph === '') {
                continue;
            }
            $exactId = 0;
            if (class_exists('RecordsKeysRepository', false)) {
                foreach (RecordsKeysRepository::findOverlaps($ph, 0, 4) as $ov) {
                    if ((string)($ov['relation'] ?? '') === 'exact') {
                        $exactId = (int)($ov['id'] ?? 0);
                        break;
                    }
                }
            }
            if ($exactId < 1) {
                return false;
            }
            $exactHits[] = ['id' => $exactId, 'name' => $ph, 'existed' => true];
        }
        $meta = is_array($proposal['apply_meta'] ?? null) ? $proposal['apply_meta'] : [];
        $keys = is_array($meta['keys'] ?? null) ? $meta['keys'] : [];
        $seen = [];
        foreach ($keys as $k) {
            $seen[(string)($k['name'] ?? '')] = true;
        }
        foreach ($exactHits as $hit) {
            if (isset($seen[$hit['name']])) {
                continue;
            }
            $keys[] = $hit;
            $seen[$hit['name']] = true;
        }
        $meta['keys'] = $keys;
        CoachRunsRepository::updateProposalProgress((int)$proposal['id'], [], $meta);
        CoachRunsRepository::decideProposal(
            (int)$proposal['id'],
            CoachRunsRepository::PROP_ACCEPTED,
            $userId > 0 ? $userId : (int)($proposal['id_decided_by'] ?? 0),
            $meta
        );

        return true;
    }

    /**
     * @param list<string> $phrases
     * @return list<string>
     */
    private static function dropExactExisting(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $ph) {
            $ph = trim((string)$ph);
            if ($ph === '') {
                continue;
            }
            $exact = false;
            if (class_exists('RecordsKeysRepository', false)) {
                foreach (RecordsKeysRepository::findOverlaps($ph, 0, 4) as $ov) {
                    if ((string)($ov['relation'] ?? '') === 'exact') {
                        $exact = true;
                        break;
                    }
                }
            }
            if (!$exact) {
                $out[] = $ph;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $allPhrases
     * @param list<string>|null $onlyPhrases
     * @return list<string>
     */
    private static function resolveSelectedPhrases(array $allPhrases, ?array $onlyPhrases): array
    {
        $normAll = [];
        foreach ($allPhrases as $ph) {
            $ph = trim((string)$ph);
            if ($ph !== '') {
                $normAll[$ph] = true;
            }
        }
        if ($onlyPhrases === null) {
            return array_keys($normAll);
        }
        $out = [];
        $seen = [];
        foreach ($onlyPhrases as $ph) {
            $ph = trim((string)$ph);
            if ($ph === '' || !isset($normAll[$ph]) || isset($seen[$ph])) {
                continue;
            }
            $seen[$ph] = true;
            $out[] = $ph;
        }

        return $out;
    }

    /**
     * @param list<array{name:string,ok:bool,overlaps:list<array{id:int,name:string,relation:string}>}> $annotated
     * @return list<array<string,mixed>>
     */
    private static function createKeys(int $dictId, array $annotated, bool $force): array
    {
        $created = [];
        foreach ($annotated as $row) {
            $name = (string)$row['name'];
            $overlaps = is_array($row['overlaps'] ?? null) ? $row['overlaps'] : [];
            $softWider = CoachPhraseCheck::isOnlyWiderExisting($overlaps);
            if ($overlaps !== [] && !$force && !$softWider) {
                $created[] = [
                    'id' => 0,
                    'name' => $name,
                    'skipped' => 'overlap',
                    'overlaps' => $overlaps,
                ];
                continue;
            }
            $looklike = 0;
            if ($overlaps !== [] && ($force || $softWider)) {
                $looklike = (int)($overlaps[0]['id'] ?? 0);
            }
            $id = (int)RecordsKeysRepository::create([
                'name' => mb_substr($name, 0, 255),
                'disabled' => 0,
                'id_dictonary' => $dictId,
                'id_looklike' => $looklike,
            ]);
            if ($id < 1) {
                $created[] = [
                    'id' => 0,
                    'name' => $name,
                    'skipped' => 'create_failed',
                ];
                continue;
            }
            $created[] = ['id' => $id, 'name' => $name];
        }

        return $created;
    }

    /**
     * @param list<array{name:string,ok:bool,overlaps:list<array{id:int,name:string,relation:string}>}> $annotated
     * @return list<array<string,mixed>>
     */
    private static function skippedOnly(array $annotated): array
    {
        $out = [];
        foreach ($annotated as $row) {
            $out[] = [
                'id' => 0,
                'name' => (string)$row['name'],
                'skipped' => 'overlap',
                'overlaps' => $row['overlaps'],
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $keys */
    private static function countCreated(array $keys): int
    {
        $n = 0;
        foreach ($keys as $k) {
            if ((int)($k['id'] ?? 0) > 0 && empty($k['skipped'])) {
                $n++;
            }
        }

        return $n;
    }

    /** @param list<array<string,mixed>> $keys */
    private static function countSkipped(array $keys): int
    {
        $n = 0;
        foreach ($keys as $k) {
            if (!empty($k['skipped'])) {
                $n++;
            }
        }

        return $n;
    }

    private static function attachDictToPart(int $scriptId, int $partId, int $dictId): bool
    {
        if (!class_exists('RecordsScriptsRepository', false)) {
            return false;
        }
        $tree = RecordsScriptsRepository::findWithParts($scriptId);
        if ($tree === null) {
            return false;
        }
        $found = false;
        $parts = [];
        foreach ($tree['parts'] as $part) {
            if ((int)$part['id'] === $partId) {
                $ids = is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [];
                $ids = array_values(array_map('intval', $ids));
                if (!in_array($dictId, $ids, true)) {
                    $ids[] = $dictId;
                }
                $part['dict_ids'] = $ids;
                $found = true;
            }
            $parts[] = $part;
        }
        if (!$found) {
            return false;
        }
        $root = $tree['root'];
        $meta = [
            'name' => (string)$root['name'],
            'disabled' => (int)$root['disabled'],
            'num' => (int)$root['num'],
            'id_position' => (string)$root['id_position'],
            'rating_enabled' => (int)($root['rating_enabled'] ?? 0),
            'is_hidden' => (int)($root['is_hidden'] ?? 0),
            'legacy_type_id' => (int)($root['legacy_type_id'] ?? 0),
            'day_bonus' => (int)($root['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($root['day_bonus_sum'] ?? 0),
            'day_gates' => is_array($root['day_gates'] ?? null) ? $root['day_gates'] : [],
            'proc_ratio_min' => (int)($root['proc_ratio_min'] ?? 0),
        ];
        RecordsScriptsRepository::update($scriptId, $meta, $parts);

        return true;
    }
}
