<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Overlap + stem-conflict checks matching manual key create. */
final class CoachPhraseCheck
{
    /**
     * @return list<array{
     *   name:string,
     *   ok:bool,
     *   overlaps:list<array{id:int,name:string,relation:string}>,
     *   stem_conflicts:list<array{id:int,name:string,relation:string,dict_name?:string}>
     * }>
     */
    public static function annotate(array $phrases, int $dictId = 0): array
    {
        $out = [];
        foreach ($phrases as $ph) {
            $name = trim((string)$ph);
            if ($name === '') {
                continue;
            }
            $overlaps = [];
            if (class_exists('RecordsKeysRepository', false)) {
                foreach (RecordsKeysRepository::findOverlaps($name, 0, 8) as $ov) {
                    $overlaps[] = [
                        'id' => (int)$ov['id'],
                        'name' => (string)$ov['name'],
                        'relation' => (string)($ov['relation'] ?? ''),
                    ];
                }
            }
            $stem = [];
            if ($dictId > 0 && class_exists('KeyStemConflict', false)) {
                foreach (KeyStemConflict::find($name, $dictId, 0, 8) as $sc) {
                    $stem[] = [
                        'id' => (int)$sc['id'],
                        'name' => (string)$sc['name'],
                        'relation' => (string)($sc['relation'] ?? ''),
                        'dict_name' => (string)($sc['dict_name'] ?? ''),
                    ];
                }
            }
            $softOk = self::isOnlyWiderExisting($overlaps) && $stem === [];
            $out[] = [
                'name' => $name,
                'ok' => $overlaps === [] && $stem === [],
                'overlaps' => $overlaps,
                'stem_conflicts' => $stem,
                'needs_force' => !$softOk && ($overlaps !== [] || $stem !== []),
            ];
        }

        return $out;
    }

    /** True if phrase has any overlap (exact / contains / contained). */
    public static function hasOverlap(string $phrase): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '' || !class_exists('RecordsKeysRepository', false)) {
            return false;
        }

        return RecordsKeysRepository::findOverlaps($phrase, 0, 1) !== [];
    }

    /**
     * Exact duplicate only — used to drop LLM proposals that add nothing.
     * Soft overlaps stay: "contains" (existing key is wider) is exactly the ASR case
     * we want from coach (speech said the short form; long keys never match).
     */
    public static function hasExactOverlap(string $phrase): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '' || !class_exists('RecordsKeysRepository', false)) {
            return false;
        }
        foreach (RecordsKeysRepository::findOverlaps($phrase, 0, 4) as $ov) {
            if ((string)($ov['relation'] ?? '') === 'exact') {
                return true;
            }
        }

        return false;
    }

    /**
     * Soft nesting where an existing key already contains this phrase as a substring.
     * @param list<array{relation?:string}> $overlaps
     */
    public static function isOnlyWiderExisting(array $overlaps): bool
    {
        if (class_exists('RecordsKeysRepository', false)) {
            return RecordsKeysRepository::isSoftContainsOnly($overlaps);
        }
        if ($overlaps === []) {
            return true;
        }
        foreach ($overlaps as $ov) {
            if ((string)($ov['relation'] ?? '') !== 'contains') {
                return false;
            }
        }

        return true;
    }
}
