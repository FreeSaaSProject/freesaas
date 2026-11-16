<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class Icd10Repository
{
    /** Loose ICD-10 pattern: L70, L70.0, Z41.1 */
    public static function isCodeShape(string $code): bool
    {
        return (bool)preg_match('/^[A-ZА-Я]\d{2}(\.\d{1,4})?$/u', strtoupper(str_replace(' ', '', $code)));
    }

    /**
     * Split free-text dia_codes into normalized codes.
     *
     * @return list<string>
     */
    public static function parseCodes(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;\/]+/u', trim($raw)) ?: [] as $part) {
            $part = strtoupper(trim(str_replace(' ', '', (string)$part)));
            $part = preg_replace('/^([A-Z])(\d)/u', '$1$2', $part) ?? $part;
            if ($part === '' || !self::isCodeShape($part)) {
                continue;
            }
            if (!in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /** @return array{CODE:string,NAME:string}|null */
    public static function find(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '' || !Database::tableExists('base_egisz_icd10')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT CODE, NAME FROM base_egisz_icd10 WHERE CODE = ? AND ENABLED = 1 LIMIT 1'
        );
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '' || !Database::tableExists('base_egisz_icd10')) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $st = Database::pdo()->prepare(
            'SELECT CODE, NAME FROM base_egisz_icd10
             WHERE ENABLED = 1 AND (CODE LIKE ? OR NAME LIKE ?)
             ORDER BY CODE ASC LIMIT ' . $limit
        );
        $st->execute([$like, $like]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'code' => (string)$row['CODE'],
                'name' => (string)$row['NAME'],
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $codes
     * @return list<array{code:string,name:string,known:bool}>
     */
    public static function enrich(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $row = self::find($code);
            $out[] = [
                'code' => $code,
                'name' => $row ? (string)$row['NAME'] : '',
                'known' => $row !== null,
            ];
        }
        return $out;
    }
}
