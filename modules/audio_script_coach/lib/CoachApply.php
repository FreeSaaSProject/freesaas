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
     * @return array{ok:bool,error:string,meta:array<string,mixed>}
     */
    public static function accept(array $proposal, int $adminId, int $userId, bool $linkPart, bool $force): array
    {
        $out = ['ok' => false, 'error' => '', 'meta' => []];
        if ((int)($proposal['status'] ?? -1) !== CoachRunsRepository::PROP_PENDING) {
            $out['error'] = t('audio_script_coach.err.proposal_not_pending');

            return $out;
        }
        if (!class_exists('RecordsKeysRepository', false) || !class_exists('RecordsDictsRepository', false)) {
            $out['error'] = t('audio_script_coach.err.dicts_missing');

            return $out;
        }

        $action = (string)($proposal['action'] ?? '');
        $phrases = is_array($proposal['phrases'] ?? null) ? $proposal['phrases'] : [];
        if ($phrases === []) {
            $out['error'] = t('audio_script_coach.err.no_phrases');

            return $out;
        }

        $annotated = CoachPhraseCheck::annotate($phrases);
        $creatable = [];
        foreach ($annotated as $row) {
            if ($row['ok'] || $force) {
                $creatable[] = $row['name'];
            }
        }
        if ($creatable === []) {
            $out['error'] = t('audio_script_coach.err.all_overlap');
            $out['meta'] = ['keys' => self::skippedOnly($annotated)];

            return $out;
        }

        try {
            if ($action === 'add_key') {
                $dictId = (int)($proposal['id_dict'] ?? 0);
                if ($dictId < 1) {
                    $out['error'] = t('audio_script_coach.err.dict_required');

                    return $out;
                }
                $keys = self::createKeys($dictId, $annotated, $force);
                $out['meta'] = [
                    'dict_id' => $dictId,
                    'keys' => $keys,
                    'created' => self::countCreated($keys),
                    'skipped' => self::countSkipped($keys),
                ];
            } elseif ($action === 'add_dict_with_keys') {
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
                    'descr' => mb_substr(t('audio_script_coach.apply.dict_descr'), 0, 100),
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
                $keys = self::createKeys($dictId, $annotated, $force);
                $linked = false;
                $partId = (int)($proposal['id_part'] ?? 0);
                $scriptId = (int)($proposal['id_script'] ?? 0);
                if ($linkPart && $partId > 0 && $scriptId > 0 && self::countCreated($keys) > 0) {
                    $linked = self::attachDictToPart($scriptId, $partId, $dictId);
                }
                $out['meta'] = [
                    'dict_id' => $dictId,
                    'keys' => $keys,
                    'created' => self::countCreated($keys),
                    'skipped' => self::countSkipped($keys),
                    'linked_part' => $linked,
                    'part_id' => $partId,
                    'script_id' => $scriptId,
                ];
            } else {
                $out['error'] = t('audio_script_coach.err.unknown_action');

                return $out;
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();

            return $out;
        }

        if (self::countCreated($out['meta']['keys'] ?? []) < 1) {
            $out['error'] = t('audio_script_coach.err.all_overlap');

            return $out;
        }

        CoachRunsRepository::decideProposal(
            (int)$proposal['id'],
            CoachRunsRepository::PROP_ACCEPTED,
            $userId,
            $out['meta']
        );
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
        CoachRunsRepository::decideProposal(
            (int)$proposal['id'],
            CoachRunsRepository::PROP_REJECTED,
            $userId,
            null
        );

        return ['ok' => true, 'error' => ''];
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
            if ($overlaps !== [] && !$force) {
                $created[] = [
                    'id' => 0,
                    'name' => $name,
                    'skipped' => 'overlap',
                    'overlaps' => $overlaps,
                ];
                continue;
            }
            $looklike = 0;
            if ($overlaps !== [] && $force) {
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
