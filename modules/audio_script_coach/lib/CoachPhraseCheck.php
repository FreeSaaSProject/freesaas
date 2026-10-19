<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Overlap checks matching manual key create (RecordsKeysRepository::findOverlaps). */
final class CoachPhraseCheck
{
    /**
     * @return list<array{
     *   name:string,
     *   ok:bool,
     *   overlaps:list<array{id:int,name:string,relation:string}>
     * }>
     */
    public static function annotate(array $phrases): array
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
            $out[] = [
                'name' => $name,
                'ok' => $overlaps === [],
                'overlaps' => $overlaps,
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
}
